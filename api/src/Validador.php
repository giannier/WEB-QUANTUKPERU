<?php
/**
 * Validación y saneamiento de la Hoja de Reclamación.
 *
 * Los campos y su obligatoriedad salen del Art. 5 del D.S. 011-2011-PCM.
 * Nada de lo que entra acá llega al PDF ni al correo sin pasar por este filtro.
 */

declare(strict_types=1);

final class Validador
{
    /** @var array<string,string> */
    private array $errores = [];

    /** @var array<string,mixed> */
    private array $limpio = [];

    private const TIPOS_DOC = ['DNI', 'CE', 'Pasaporte', 'RUC'];
    private const TIPOS_BIEN = ['Producto', 'Servicio'];
    private const TIPOS_RECLAMO = ['Reclamo', 'Queja'];

    private const MAX = [
        'nombre'        => 120,
        'documento'     => 20,
        'domicilio'     => 200,
        'telefono'      => 25,
        'correo'        => 120,
        'tutor_nombre'  => 120,
        'tutor_domicilio' => 200,
        'tutor_telefono' => 25,
        'tutor_correo'  => 120,
        'bien_desc'     => 300,

        // Estos dos topes no son arbitrarios: son los que permiten que la
        // Hoja de Reclamación entre en una sola página A4. Ver HojaPdf.
        'detalle'       => 1800,
        'pedido'        => 700,
    ];

    public function validar(array $post): bool
    {
        $this->errores = [];
        $this->limpio  = [];

        // --- Consumidor reclamante ---------------------------------------
        $this->texto($post, 'nombre', 'Nombre y apellidos', true);
        $this->opcion($post, 'tipo_documento', 'Tipo de documento', self::TIPOS_DOC, true);
        $this->documento($post);
        $this->texto($post, 'domicilio', 'Domicilio', true);
        $this->telefono($post, 'telefono', 'Teléfono', true);
        $this->correo($post, 'correo', 'Correo electrónico', true);

        // --- Menor de edad: el Art. 5 exige los datos del representante ---
        $esMenor = !empty($post['es_menor']);
        $this->limpio['es_menor'] = $esMenor;

        if ($esMenor) {
            $this->texto($post, 'tutor_nombre', 'Nombre del padre o representante', true);
            $this->texto($post, 'tutor_domicilio', 'Domicilio del representante', true);
            $this->telefono($post, 'tutor_telefono', 'Teléfono del representante', true);
            $this->correo($post, 'tutor_correo', 'Correo del representante', true);
        } else {
            foreach (['tutor_nombre', 'tutor_domicilio', 'tutor_telefono', 'tutor_correo'] as $c) {
                $this->limpio[$c] = '';
            }
        }

        // --- Bien contratado ---------------------------------------------
        $this->opcion($post, 'tipo_bien', 'Tipo de bien', self::TIPOS_BIEN, true);
        $this->texto($post, 'bien_desc', 'Descripción del producto o servicio', true);
        $this->monto($post);

        // --- Detalle -----------------------------------------------------
        $this->opcion($post, 'tipo_reclamo', 'Tipo de solicitud', self::TIPOS_RECLAMO, true);
        $this->texto($post, 'detalle', 'Detalle', true, 20);
        $this->texto($post, 'pedido', 'Pedido del consumidor', true, 10);

        // --- Conformidad ---------------------------------------------------
        // En el Libro virtual esto reemplaza a la firma: el Art. 5 pide un
        // mecanismo que acredite que el consumidor está conforme con lo que
        // registró.
        if (empty($post['conformidad'])) {
            $this->errores['conformidad'] = 'Debes confirmar que los datos son correctos.';
        }
        $this->limpio['conformidad'] = true;

        if (empty($post['acepta_privacidad'])) {
            $this->errores['acepta_privacidad'] = 'Debes aceptar la política de privacidad.';
        }
        $this->limpio['acepta_privacidad'] = true;

        return $this->errores === [];
    }

    public function errores(): array { return $this->errores; }
    public function datos(): array   { return $this->limpio; }

    // ---------------------------------------------------------------------
    // Reglas
    // ---------------------------------------------------------------------

    private function texto(array $p, string $campo, string $rotulo, bool $obligatorio, int $min = 2): void
    {
        $v = $this->normalizar((string) ($p[$campo] ?? ''));

        if ($v === '') {
            if ($obligatorio) {
                $this->errores[$campo] = $rotulo . ' es obligatorio.';
            }
            $this->limpio[$campo] = '';
            return;
        }

        if (mb_strlen($v) < $min) {
            $this->errores[$campo] = $rotulo . ' es demasiado corto.';
        }

        $max = self::MAX[$campo] ?? 300;
        if (mb_strlen($v) > $max) {
            $v = mb_substr($v, 0, $max);
        }

        $this->limpio[$campo] = $v;
    }

    private function opcion(array $p, string $campo, string $rotulo, array $validas, bool $obligatorio): void
    {
        $v = trim((string) ($p[$campo] ?? ''));

        if (!in_array($v, $validas, true)) {
            if ($obligatorio) {
                $this->errores[$campo] = 'Selecciona ' . mb_strtolower($rotulo) . '.';
            }
            $this->limpio[$campo] = '';
            return;
        }

        $this->limpio[$campo] = $v;
    }

    private function documento(array $p): void
    {
        $tipo = $this->limpio['tipo_documento'] ?? '';
        $v = preg_replace('/[^A-Za-z0-9]/', '', (string) ($p['documento'] ?? '')) ?? '';

        if ($v === '') {
            $this->errores['documento'] = 'El número de documento es obligatorio.';
            $this->limpio['documento'] = '';
            return;
        }

        $largos = ['DNI' => [8, 8], 'RUC' => [11, 11], 'CE' => [8, 12], 'Pasaporte' => [6, 12]];
        [$min, $max] = $largos[$tipo] ?? [5, 20];

        $n = strlen($v);
        if ($n < $min || $n > $max) {
            $this->errores['documento'] = $tipo === 'DNI'
                ? 'El DNI debe tener 8 dígitos.'
                : sprintf('El número de %s debe tener entre %d y %d caracteres.', $tipo ?: 'documento', $min, $max);
        }

        if (in_array($tipo, ['DNI', 'RUC'], true) && !ctype_digit($v)) {
            $this->errores['documento'] = 'El ' . $tipo . ' solo admite números.';
        }

        $this->limpio['documento'] = strtoupper($v);
    }

    private function telefono(array $p, string $campo, string $rotulo, bool $obligatorio): void
    {
        $v = $this->normalizar((string) ($p[$campo] ?? ''));

        if ($v === '') {
            if ($obligatorio) {
                $this->errores[$campo] = $rotulo . ' es obligatorio.';
            }
            $this->limpio[$campo] = '';
            return;
        }

        if (!preg_match('/^[+]?[0-9()\s.-]{6,25}$/', $v)) {
            $this->errores[$campo] = $rotulo . ' no tiene un formato válido.';
        }

        $this->limpio[$campo] = mb_substr($v, 0, self::MAX[$campo] ?? 25);
    }

    private function correo(array $p, string $campo, string $rotulo, bool $obligatorio): void
    {
        $v = trim((string) ($p[$campo] ?? ''));

        // Un salto de línea dentro de una dirección permitiría inyectar
        // cabeceras en el correo saliente. Se corta de raíz.
        $v = str_replace(["\r", "\n", "\0", "%0a", "%0d"], '', $v);

        if ($v === '') {
            if ($obligatorio) {
                $this->errores[$campo] = $rotulo . ' es obligatorio.';
            }
            $this->limpio[$campo] = '';
            return;
        }

        if (!filter_var($v, FILTER_VALIDATE_EMAIL) || mb_strlen($v) > (self::MAX[$campo] ?? 120)) {
            $this->errores[$campo] = $rotulo . ' no es válido.';
            $this->limpio[$campo] = '';
            return;
        }

        $this->limpio[$campo] = mb_strtolower($v);
    }

    private function monto(array $p): void
    {
        $raw = trim((string) ($p['monto'] ?? ''));

        if ($raw === '') {
            $this->limpio['monto'] = null;   // el monto no es obligatorio
            return;
        }

        $raw = str_replace([',', ' '], ['.', ''], $raw);

        if (!is_numeric($raw) || (float) $raw < 0 || (float) $raw > 9999999) {
            $this->errores['monto'] = 'El monto reclamado no es válido.';
            $this->limpio['monto'] = null;
            return;
        }

        $this->limpio['monto'] = round((float) $raw, 2);
    }

    /**
     * Quita caracteres de control y colapsa espacios.
     * No se aplica htmlspecialchars acá: el escapado se hace en el punto de
     * salida (HTML del correo), porque en el PDF el texto va en crudo y
     * escaparlo antes dejaría "&amp;" impreso en la hoja.
     */
    private function normalizar(string $v): string
    {
        $v = str_replace(["\0", "\r"], '', $v);
        $v = preg_replace('/[\x{0000}-\x{0008}\x{000B}\x{000C}\x{000E}-\x{001F}\x{007F}]/u', '', $v) ?? '';
        $v = preg_replace('/[ \t]+/', ' ', $v) ?? '';
        $v = preg_replace('/\n{3,}/', "\n\n", $v) ?? '';

        return trim($v);
    }
}
