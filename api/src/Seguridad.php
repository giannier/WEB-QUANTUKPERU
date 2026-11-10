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

    /** Rangos publicados por Cloudflare (https://www.cloudflare.com/ips/). */
    private const CLOUDFLARE = [
        '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22',
        '141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20',
        '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
        '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
        '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32',
        '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
    ];

    /**
     * IP real del visitante.
     *
     * Se parte de REMOTE_ADDR, que no se puede falsear. CF-Connecting-IP solo
     * se acepta cuando la conexión llega desde un rango de Cloudflare; si no,
     * cualquiera podría falsear su IP enviando la cabecera a mano.
     * X-Forwarded-For nunca se usa.
     */
    public function ip(): string
    {
        $remota = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
        if (!filter_var($remota, FILTER_VALIDATE_IP)) {
            return '0.0.0.0';
        }

        $cf = trim((string) ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? ''));
        if ($cf !== '' && filter_var($cf, FILTER_VALIDATE_IP) && $this->enRangoCloudflare($remota)) {
            return $cf;
        }

        return $remota;
    }

    private function enRangoCloudflare(string $ip): bool
    {
        $bin = @inet_pton($ip);
        if ($bin === false) {
            return false;
        }
        foreach (self::CLOUDFLARE as $cidr) {
            [$red, $bits] = explode('/', $cidr);
            $binRed = inet_pton($red);
            if (strlen($binRed) !== strlen($bin)) {
                continue;
            }
            $bits = (int) $bits;
            $bytes = intdiv($bits, 8);
            if (strncmp($bin, $binRed, $bytes) !== 0) {
                continue;
            }
            $resto = $bits % 8;
            if ($resto === 0) {
                return true;
            }
            $mascara = (0xFF << (8 - $resto)) & 0xFF;
            if ((ord($bin[$bytes]) & $mascara) === (ord($binRed[$bytes]) & $mascara)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Sal secreta propia del servidor (se genera sola la primera vez y vive en
     * storage/, que está cerrado por HTTP). Con una sal fija y pública en el
     * código, el hash de una IP se podría revertir por fuerza bruta.
     */
    private function sal(): string
    {
        static $sal = null;
        if ($sal !== null) {
            return $sal;
        }
        $archivo = $this->dirRate . '/.sal';
        $valor = is_file($archivo) ? trim((string) @file_get_contents($archivo)) : '';
        if (strlen($valor) < 32) {
            $nuevo = bin2hex(random_bytes(32));
            // 'x' falla si otro proceso la creó antes: en ese caso se relee
            $fp = @fopen($archivo, 'x');
            if ($fp !== false) {
                fwrite($fp, $nuevo);
                fclose($fp);
                @chmod($archivo, 0600);
            }
            $valor = trim((string) @file_get_contents($archivo));
            if (strlen($valor) < 32) {
                $valor = $nuevo;
            }
        }
        return $sal = $valor;
    }

    /** Clave derivada de la IP: no se guarda la IP en claro. */
    private function clave(string $ip): string
    {
        return hash_hmac('sha256', $ip, $this->sal());
    }

    /** Huella pública de la IP para el archivo de hojas (no reversible sin la sal). */
    public function huellaIp(string $ip): string
    {
        return $this->clave($ip);
    }

    /**
     * Comprueba los límites y, si se cumplen, reserva el cupo en la misma
     * operación bajo flock: dos envíos simultáneos no pueden colarse entre la
     * lectura y la escritura. Límites por IP (intervalo mínimo y tope diario),
     * tope global por hora y por día, y contenido repetido en 24 h.
     * Si el almacén no se puede abrir, se rechaza (fail-closed).
     */
    public function reservar(string $ip, string $contenido, ?string &$motivo = null): bool
    {
        $ahora = time();
        $fp = @fopen($this->dirRate . '/estado.json', 'c+');
        if ($fp === false || !flock($fp, LOCK_EX)) {
            if ($fp !== false) {
                fclose($fp);
            }
            $motivo = 'almacen';
            return false;
        }

        try {
            $e = json_decode((string) stream_get_contents($fp), true);
            $e = is_array($e) ? $e : [];
            $vigente = static fn($t) => is_int($t) && $t > $ahora - 86400;

            $ips = [];
            foreach ((array) ($e['ips'] ?? []) as $k => $marcas) {
                $m = array_values(array_filter((array) $marcas, $vigente));
                if ($m !== []) {
                    $ips[$k] = $m;
                }
            }
            $global = array_values(array_filter((array) ($e['global'] ?? []), $vigente));
            $dups   = array_filter((array) ($e['dups'] ?? []), $vigente);

            $k      = $this->clave($ip);
            $marcas = $ips[$k] ?? [];
            $huella = hash_hmac('sha256', $contenido, $this->sal());

            $ultimaHora = count(array_filter($global, fn($t) => $t > $ahora - 3600));

            if ($ultimaHora >= $this->limites['global_por_hora']) {
                $motivo = 'global_hora';
            } elseif (count($global) >= $this->limites['global_por_dia']) {
                $motivo = 'global_dia';
            } elseif ($marcas !== [] && max($marcas) > $ahora - $this->limites['intervalo_segundos']) {
                $motivo = 'intervalo';
            } elseif (count($marcas) >= $this->limites['por_dia']) {
                $motivo = 'dia';
            } elseif (isset($dups[$huella])) {
                $motivo = 'duplicado';
            } else {
                $marcas[]       = $ahora;
                $ips[$k]        = $marcas;
                $global[]       = $ahora;
                $dups[$huella]  = $ahora;
                $motivo = null;
            }

            if ($motivo === null) {
                ftruncate($fp, 0);
                rewind($fp);
                fwrite($fp, json_encode(['ips' => $ips, 'global' => $global, 'dups' => $dups]));
                fflush($fp);
            }
            return $motivo === null;
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
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
