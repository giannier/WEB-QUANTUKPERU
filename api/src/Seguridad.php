<?php
/**
 * Defensas del formulario: límite por IP, token de sesión, trampas para bots.
 *
 * Todo se resuelve en archivos porque un hosting compartido de cPanel no
 * garantiza Redis ni una base de datos disponible para esto.
 */

declare(strict_types=1);

final class Seguridad
{
    private array $limites;
    private string $dirRate;

    public function __construct(array $config)
    {
        $this->limites = $config['limites'];
        $this->dirRate = $config['storage'] . '/ratelimit';

        if (!is_dir($this->dirRate)) {
            mkdir($this->dirRate, 0750, true);
        }
    }

    /**
     * IP real del visitante.
     *
     * Se leen las cabeceras de proxy solo si el hosting está detrás de uno
     * conocido. Confiar ciegamente en X-Forwarded-For permitiría a cualquiera
     * falsear su IP y saltarse el límite enviando la cabecera a mano.
     */
    public function ip(): string
    {
        $candidatas = [];

        // Cloudflare firma esta cabecera y no es falsificable de extremo a extremo
        if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
            $candidatas[] = $_SERVER['HTTP_CF_CONNECTING_IP'];
        }

        $candidatas[] = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        foreach ($candidatas as $ip) {
            $ip = trim((string) $ip);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }

        return '0.0.0.0';
    }

    /** Clave de archivo derivada de la IP: no se guarda la IP en claro. */
    private function clave(string $ip): string
    {
        return hash('sha256', $ip . '|quantuk-libro-reclamaciones');
    }

    /**
     * ¿Esta IP puede registrar otro reclamo?
     * Ventana deslizante: se conservan las marcas de tiempo del último día
     * y se cuentan las que caen dentro de cada ventana.
     */
    public function permitido(string $ip, ?string &$motivo = null): bool
    {
        $ahora = time();

        // --- Freno global -------------------------------------------------
        $global = $this->leer($this->dirRate . '/_global.json');
        $global = array_values(array_filter($global, fn($t) => $t > $ahora - 3600));
        if (count($global) >= $this->limites['global_por_hora']) {
            $motivo = 'global';
            return false;
        }

        // --- Por IP -------------------------------------------------------
        $archivo = $this->dirRate . '/' . $this->clave($ip) . '.json';
        $marcas  = $this->leer($archivo);
        $marcas  = array_values(array_filter($marcas, fn($t) => $t > $ahora - 86400));

        $ultimaHora = count(array_filter($marcas, fn($t) => $t > $ahora - 3600));

        if ($ultimaHora >= $this->limites['por_hora']) {
            $motivo = 'hora';
            return false;
        }

        if (count($marcas) >= $this->limites['por_dia']) {
            $motivo = 'dia';
            return false;
        }

        return true;
    }

    /** Deja constancia de un envío aceptado. */
    public function registrar(string $ip): void
    {
        $ahora = time();

        $archivo = $this->dirRate . '/' . $this->clave($ip) . '.json';
        $marcas  = $this->leer($archivo);
        $marcas  = array_values(array_filter($marcas, fn($t) => $t > $ahora - 86400));
        $marcas[] = $ahora;
        $this->escribir($archivo, $marcas);

        $g = $this->dirRate . '/_global.json';
        $global = array_values(array_filter($this->leer($g), fn($t) => $t > $ahora - 3600));
        $global[] = $ahora;
        $this->escribir($g, $global);

        $this->limpiar();
    }

    private function leer(string $archivo): array
    {
        if (!is_file($archivo)) {
            return [];
        }
        $raw = @file_get_contents($archivo);
        if ($raw === false) {
            return [];
        }
        $datos = json_decode($raw, true);
        return is_array($datos) ? array_filter($datos, 'is_int') : [];
    }

    private function escribir(string $archivo, array $marcas): void
    {
        // LOCK_EX evita que dos envíos simultáneos se pisen el contador
        @file_put_contents($archivo, json_encode(array_values($marcas)), LOCK_EX);
    }

    /** Borra archivos de IPs que ya no tienen marcas vigentes. */
    private function limpiar(): void
    {
        // Solo una de cada veinte veces: recorrer el directorio en cada
        // petición sería un gasto inútil.
        if (random_int(1, 20) !== 1) {
            return;
        }

        foreach (glob($this->dirRate . '/*.json') ?: [] as $archivo) {
            if (basename($archivo) === '_global.json') {
                continue;
            }
            if (filemtime($archivo) < time() - 86400) {
                @unlink($archivo);
            }
        }
    }

    // ---------------------------------------------------------------------
    // Token del formulario
    // ---------------------------------------------------------------------

    /** Emite un token ligado a la sesión y anota el instante de emisión. */
    public function emitirToken(): array
    {
        $this->sesion();

        $token = bin2hex(random_bytes(32));
        $_SESSION['lr_token']  = $token;
        $_SESSION['lr_emitido'] = time();

        return ['token' => $token, 'emitido' => $_SESSION['lr_emitido']];
    }

    /**
     * Valida el token y de paso el tiempo que tardó en llenarse el formulario.
     * Un formulario enviado en menos de unos segundos no lo completó una
     * persona.
     */
    public function tokenValido(string $token, ?string &$motivo = null): bool
    {
        $this->sesion();

        $guardado = $_SESSION['lr_token'] ?? '';
        $emitido  = (int) ($_SESSION['lr_emitido'] ?? 0);

        if ($guardado === '' || !hash_equals($guardado, $token)) {
            $motivo = 'token';
            return false;
        }

        $transcurrido = time() - $emitido;

        if ($transcurrido < $this->limites['segundos_minimos']) {
            $motivo = 'demasiado_rapido';
            return false;
        }

        if ($transcurrido > $this->limites['vigencia_token']) {
            $motivo = 'token_vencido';
            return false;
        }

        return true;
    }

    /** Un token sirve una sola vez: evita reenvíos del mismo formulario. */
    public function consumirToken(): void
    {
        $this->sesion();
        unset($_SESSION['lr_token'], $_SESSION['lr_emitido']);
    }

    private function sesion(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => !empty($_SERVER['HTTPS']) || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        session_name('QTKLR');
        session_start();
    }

    /**
     * Campo trampa: está oculto por CSS, una persona nunca lo ve ni lo llena.
     * Si viene con contenido, lo completó un robot que leyó el HTML.
     */
    public function honeypotLimpio(array $post): bool
    {
        return trim((string) ($post['direccion_alternativa'] ?? '')) === '';
    }
}
