<?php
/**
 * Numeración correlativa de las Hojas de Reclamación.
 *
 * El Art. 5 del D.S. 011-2011-PCM la exige. Tiene que ser consecutiva y sin
 * huecos, así que el contador se protege con un bloqueo exclusivo: si dos
 * personas envían el formulario en el mismo instante, una espera a la otra
 * en lugar de que ambas se lleven el mismo número.
 */

declare(strict_types=1);

final class Correlativo
{
    private string $archivo;

    public function __construct(string $storage)
    {
        if (!is_dir($storage)) {
            mkdir($storage, 0750, true);
        }
        $this->archivo = $storage . '/correlativo.dat';
    }

    /**
     * Entrega el siguiente número y lo deja persistido.
     * Formato: LR-2026-000001
     */
    public function siguiente(): string
    {
        $anio = (int) date('Y');

        $fp = fopen($this->archivo, 'c+');
        if ($fp === false) {
            throw new RuntimeException('No se pudo abrir el contador de correlativos.');
        }

        try {
            // Bloqueo exclusivo: el resto de peticiones espera acá
            if (!flock($fp, LOCK_EX)) {
                throw new RuntimeException('No se pudo bloquear el contador.');
            }

            $contenido = stream_get_contents($fp) ?: '';
            $estado = json_decode($contenido, true);

            $ultimoAnio = is_array($estado) ? (int) ($estado['anio'] ?? 0) : 0;
            $ultimoNum  = is_array($estado) ? (int) ($estado['numero'] ?? 0) : 0;

            // La serie reinicia cada año
            $numero = ($ultimoAnio === $anio) ? $ultimoNum + 1 : 1;

            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, json_encode(['anio' => $anio, 'numero' => $numero]));
            fflush($fp);

            return sprintf('LR-%d-%06d', $anio, $numero);
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }
}
