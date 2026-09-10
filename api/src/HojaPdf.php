<?php
/**
 * Genera la Hoja de Reclamación en PDF.
 *
 * Estructura tomada del Art. 5 del D.S. 011-2011-PCM (contenido mínimo) y del
 * formato del Anexo 1.
 *
 * UNA SOLA HOJA
 * -------------
 * El requisito de que entre en una página no se cumple "a ojo": el texto que
 * escribe el consumidor es de largo variable. Se genera el PDF, se cuentan las
 * páginas y, si pasa de una, se vuelve a generar con el cuerpo un punto más
 * chico. Recién si agotadas las medidas sigue sin entrar, se recorta el texto
 * dejando constancia de ello. Ver generar().
 */

declare(strict_types=1);

require_once __DIR__ . '/../lib/fpdf/fpdf.php';

final class HojaPdf extends FPDF
{
    private array $proveedor;
    private array $hoja;
    private float $cuerpo;          // tamaño de fuente del cuerpo, en puntos
    private int $plazoDias;

    private const ROJO  = [237, 28, 36];
    private const TINTA = [26, 26, 28];
    private const GRIS  = [110, 116, 124];
    private const LINEA = [214, 219, 225];
    private const FONDO = [246, 247, 249];

    private const MARGEN = 12.0;

    public function __construct(array $proveedor, array $hoja, int $plazoDias, float $cuerpo = 8.6)
    {
        parent::__construct('P', 'mm', 'A4');

        $this->proveedor = $proveedor;
        $this->hoja      = $hoja;
        $this->cuerpo    = $cuerpo;
        $this->plazoDias = $plazoDias;

        $this->SetMargins(self::MARGEN, self::MARGEN, self::MARGEN);
        $this->SetAutoPageBreak(true, self::MARGEN);
        $this->SetTitle('Hoja de Reclamacion ' . ($hoja['correlativo'] ?? ''));
        $this->SetAuthor($proveedor['razon_social']);
        $this->SetCreator('quantukperu.com');
    }

    /**
     * Construye el documento probando tamaños de fuente hasta que quepa en
     * una página. Devuelve el PDF como cadena binaria.
     *
     * @param array{paginas:int,cuerpo:float,recortado:bool} $auditoria
     */
    public static function generar(array $proveedor, array $hoja, int $plazoDias, ?array &$auditoria = null): string
    {
        $medidas = [8.6, 8.2, 7.8, 7.4, 7.0];
        $recortado = false;

        foreach ($medidas as $intento => $cuerpo) {
            $pdf = new self($proveedor, $hoja, $plazoDias, $cuerpo);
            $pdf->construir();

            if ($pdf->PageNo() === 1) {
                $auditoria = ['paginas' => 1, 'cuerpo' => $cuerpo, 'recortado' => $recortado];
                return $pdf->Output('S');
            }
        }

        // Último recurso: el consumidor escribió muchísimo. Se recorta dejando
        // constancia expresa, porque alterar en silencio lo que declaró sería
        // peor que decirlo.
        $hoja['detalle'] = self::recortar($hoja['detalle'], 1500);
        $hoja['pedido']  = self::recortar($hoja['pedido'], 600);
        $recortado = true;

        $pdf = new self($proveedor, $hoja, $plazoDias, 7.0);
        $pdf->construir();

        $auditoria = ['paginas' => $pdf->PageNo(), 'cuerpo' => 7.0, 'recortado' => true];
        return $pdf->Output('S');
    }

    private static function recortar(string $texto, int $max): string
    {
        if (mb_strlen($texto) <= $max) {
            return $texto;
        }
        return mb_substr($texto, 0, $max)
            . "\n\n[Texto recortado por espacio. El detalle completo consta en el correo de este reclamo.]";
    }

    // -----------------------------------------------------------------
    // Composición
    // -----------------------------------------------------------------

    public function construir(): void
    {
        $this->AddPage();
        $this->cabecera();
        $this->bloqueProveedor();
        $this->bloqueConsumidor();

        if (!empty($this->hoja['es_menor'])) {
            $this->bloqueRepresentante();
        }

        $this->bloqueBien();
        $this->bloqueDetalle();
        $this->bloqueProveedorAcciones();
        $this->pieLegal();
    }

    private function cabecera(): void
    {
        $w = $this->anchoUtil();

        // Banda superior roja con el título
        $this->SetFillColor(...self::ROJO);
        $this->Rect(self::MARGEN, self::MARGEN, $w, 13, 'F');

        $this->SetTextColor(255, 255, 255);
        $this->SetFont('Helvetica', 'B', 12);
        $this->SetXY(self::MARGEN + 3, self::MARGEN + 3.4);
        $this->Cell(90, 6, $this->t('HOJA DE RECLAMACIÓN'), 0, 0, 'L');

        $this->SetFont('Helvetica', '', 7.6);
        $this->SetXY(self::MARGEN + $w - 93, self::MARGEN + 3.9);
        $this->Cell(90, 5, $this->t('Libro de Reclamaciones virtual · D.S. 011-2011-PCM'), 0, 0, 'R');

        // Correlativo y fecha
        $this->SetY(self::MARGEN + 13);
        $this->SetFillColor(...self::FONDO);
        $this->SetDrawColor(...self::LINEA);
        $this->Rect(self::MARGEN, $this->GetY(), $w, 9, 'FD');

        $this->SetTextColor(...self::TINTA);
        $this->SetFont('Helvetica', 'B', 9);
        $this->SetXY(self::MARGEN + 3, $this->GetY() + 2.4);
        $this->Cell(60, 4.5, $this->t('N.° ' . $this->hoja['correlativo']), 0, 0, 'L');

        $this->SetFont('Helvetica', '', 8);
        $this->SetTextColor(...self::GRIS);
        $this->SetXY(self::MARGEN + $w - 123, $this->GetY());
        $this->Cell(120, 4.5, $this->t('Fecha y hora de registro: ' . $this->hoja['fecha_larga']), 0, 0, 'R');

        $this->SetY(self::MARGEN + 25);
    }

    /** Rótulo de sección: barra gris con texto en versalitas. */
    private function seccion(string $titulo): void
    {
        $this->Ln(1.6);
        $this->SetFillColor(...self::TINTA);
        $this->SetTextColor(255, 255, 255);
        $this->SetFont('Helvetica', 'B', 7.6);
        $this->Cell($this->anchoUtil(), 5, ' ' . $this->t(mb_strtoupper($titulo)), 0, 1, 'L', true);
        $this->Ln(1.4);
        $this->SetTextColor(...self::TINTA);
    }

    /** Fila etiqueta/valor en una o dos columnas. */
    private function campo(string $rotulo, string $valor, float $ancho = 0, bool $saltar = true): void
    {
        $ancho = $ancho ?: $this->anchoUtil();
        $anchoRotulo = 33.0;

        $y = $this->GetY();
        $x = $this->GetX();

        $this->SetFont('Helvetica', 'B', $this->cuerpo);
        $this->SetTextColor(...self::GRIS);
        $this->Cell($anchoRotulo, 4.6, $this->t($rotulo), 0, 0, 'L');

        $this->SetFont('Helvetica', '', $this->cuerpo);
        $this->SetTextColor(...self::TINTA);
        $this->MultiCell($ancho - $anchoRotulo, 4.6, $this->t($valor !== '' ? $valor : '—'), 0, 'L');

        $yFin = $this->GetY();

        if (!$saltar) {
            // Vuelve al inicio para poder poner otra columna a la derecha
            $this->SetXY($x + $ancho, $y);
        } else {
            $this->SetY(max($yFin, $y + 4.6));
        }
    }

    private function bloqueProveedor(): void
    {
        $this->seccion('1. Identificación del proveedor');
        $this->campo('Razón social', $this->proveedor['razon_social']);
        $this->campo('RUC', $this->proveedor['ruc']);
        $this->campo('Establecimiento', $this->proveedor['direccion']);
    }

    private function bloqueConsumidor(): void
    {
        $h = $this->hoja;
        $mitad = $this->anchoUtil() / 2;

        $this->seccion('2. Identificación del consumidor reclamante');
        $this->campo('Nombre completo', $h['nombre']);
        $this->campo('Documento', $h['tipo_documento'] . ' ' . $h['documento']);
        $this->campo('Domicilio', $h['domicilio']);
        $this->campo('Teléfono', $h['telefono'], $mitad, false);
        $this->campo('Correo', $h['correo'], $mitad, true);
    }

    private function bloqueRepresentante(): void
    {
        $h = $this->hoja;
        $mitad = $this->anchoUtil() / 2;

        $this->seccion('2.1 Padre, madre o representante (consumidor menor de edad)');
        $this->campo('Nombre completo', $h['tutor_nombre']);
        $this->campo('Domicilio', $h['tutor_domicilio']);
        $this->campo('Teléfono', $h['tutor_telefono'], $mitad, false);
        $this->campo('Correo', $h['tutor_correo'], $mitad, true);
    }

    private function bloqueBien(): void
    {
        $h = $this->hoja;
        $mitad = $this->anchoUtil() / 2;

        $this->seccion('3. Identificación del bien contratado');
        $this->campo('Tipo', $h['tipo_bien'], $mitad, false);
        $this->campo('Monto reclamado', $h['monto'] !== null ? 'S/ ' . number_format((float) $h['monto'], 2) : 'No indica', $mitad, true);
        $this->campo('Descripción', $h['bien_desc']);
    }

    private function bloqueDetalle(): void
    {
        $h = $this->hoja;
        $esReclamo = $h['tipo_reclamo'] === 'Reclamo';

        $this->seccion('4. Detalle de la reclamación');

        // Marca visible del tipo, con su definición legal debajo
        $this->SetFont('Helvetica', 'B', $this->cuerpo + 0.6);
        $this->SetTextColor(...self::ROJO);
        $this->Cell(28, 5, $this->t(mb_strtoupper($h['tipo_reclamo'])), 0, 0, 'L');

        $this->SetFont('Helvetica', 'I', $this->cuerpo - 0.8);
        $this->SetTextColor(...self::GRIS);
        $this->MultiCell(
            $this->anchoUtil() - 28,
            3.6,
            $this->t($esReclamo
                ? 'Disconformidad relacionada a los bienes o servicios prestados.'
                : 'Disconformidad no relacionada a los bienes o servicios, o malestar respecto a la atención.'),
            0,
            'L'
        );
        $this->Ln(1.4);

        $this->SetTextColor(...self::TINTA);
        $this->SetFont('Helvetica', 'B', $this->cuerpo);
        $this->Cell($this->anchoUtil(), 4.4, $this->t('Detalle'), 0, 1, 'L');
        $this->SetFont('Helvetica', '', $this->cuerpo);
        $this->MultiCell($this->anchoUtil(), 4.0, $this->t($h['detalle']), 0, 'J');

        $this->Ln(1.6);
        $this->SetFont('Helvetica', 'B', $this->cuerpo);
        $this->Cell($this->anchoUtil(), 4.4, $this->t('Pedido del consumidor'), 0, 1, 'L');
        $this->SetFont('Helvetica', '', $this->cuerpo);
        $this->MultiCell($this->anchoUtil(), 4.0, $this->t($h['pedido']), 0, 'J');
    }

    private function bloqueProveedorAcciones(): void
    {
        // El Art. 5 exige un espacio para que el proveedor anote las acciones
        // adoptadas. En la hoja virtual queda reservado y en blanco.
        $this->seccion('5. Acciones adoptadas por el proveedor');

        $y = $this->GetY();

        // El recuadro se estira hasta donde empieza el pie legal. Así la hoja
        // no queda medio vacía y, sobre todo, queda sitio real para escribir
        // la respuesta si alguien la completa a mano sobre el impreso.
        $altoPie = 26.0;
        $disponible = $this->GetPageHeight() - self::MARGEN - $altoPie - $y;
        $alto = max(14.0, min($disponible, 62.0));

        $this->SetDrawColor(...self::LINEA);
        $this->SetFillColor(...self::FONDO);
        $this->Rect(self::MARGEN, $y, $this->anchoUtil(), $alto, 'FD');

        $this->SetFont('Helvetica', 'I', $this->cuerpo - 0.6);
        $this->SetTextColor(...self::GRIS);
        $this->SetXY(self::MARGEN + 2.5, $y + 2);
        $this->MultiCell(
            $this->anchoUtil() - 5,
            3.6,
            $this->t('Espacio reservado para la respuesta del proveedor, que será remitida al correo '
                   . 'del consumidor dentro del plazo legal.'),
            0,
            'L'
        );

        $this->SetY($y + $alto + 2.5);
    }

    private function pieLegal(): void
    {
        $this->SetDrawColor(...self::LINEA);
        $this->Line(self::MARGEN, $this->GetY(), self::MARGEN + $this->anchoUtil(), $this->GetY());
        $this->Ln(1.6);

        $this->SetFont('Helvetica', '', $this->cuerpo - 1.2);
        $this->SetTextColor(...self::GRIS);

        $texto = sprintf(
            'La formulación del reclamo no impide acudir a otras vías de solución de controversias ni es requisito '
            . 'previo para denunciar ante el INDECOPI. El proveedor debe dar respuesta en un plazo no mayor de %d días '
            . 'hábiles improrrogables (art. 24 de la Ley 29571, modificado por la Ley 31435). Esta hoja se conserva por '
            . 'dos años desde su registro (art. 12 del D.S. 011-2011-PCM). Registrada por medio virtual: el consumidor '
            . 'declaró su conformidad con el contenido, mecanismo que reemplaza la firma según el art. 5 del citado '
            . 'reglamento.',
            $this->plazoDias
        );

        $this->MultiCell($this->anchoUtil(), 3.2, $this->t($texto), 0, 'J');

        $this->Ln(1);
        $this->SetFont('Helvetica', 'B', $this->cuerpo - 1.2);
        $this->SetTextColor(...self::TINTA);
        $this->Cell(
            $this->anchoUtil(),
            3.6,
            $this->t('Documento generado automáticamente por ' . $this->proveedor['web']
                   . ' · Código de verificación: ' . $this->hoja['verificacion']),
            0,
            1,
            'L'
        );
    }

    private function anchoUtil(): float
    {
        return $this->GetPageWidth() - (self::MARGEN * 2);
    }

    /**
     * FPDF con las fuentes base trabaja en cp1252. Sin esta conversión los
     * acentos y la ñ salen como caracteres sueltos.
     */
    private function t(string $texto): string
    {
        $convertido = @iconv('UTF-8', 'windows-1252//TRANSLIT', $texto);
        return $convertido !== false ? $convertido : $texto;
    }
}
