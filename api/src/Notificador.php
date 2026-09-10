<?php
/**
 * Envío de la Hoja de Reclamación por correo.
 *
 * Un solo mensaje: va al consumidor y con copia oculta a la bandeja interna.
 * Se usa copia oculta y no copia visible para no exponer la dirección interna
 * en el correo que recibe el consumidor.
 */

declare(strict_types=1);

require_once __DIR__ . '/../lib/PHPMailer/Exception.php';
require_once __DIR__ . '/../lib/PHPMailer/PHPMailer.php';
require_once __DIR__ . '/../lib/PHPMailer/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

final class Notificador
{
    private array $smtp;
    private array $proveedor;
    private int $plazoDias;

    public function __construct(array $config)
    {
        $this->smtp      = $config['smtp'];
        $this->proveedor = $config['proveedor'];
        $this->plazoDias = $config['plazo_respuesta_dias_habiles'];
    }

    public function configurado(): bool
    {
        return $this->smtp['password'] !== '';
    }

    /**
     * @throws RuntimeException si el envío falla
     */
    public function enviar(array $hoja, string $pdf): void
    {
        if (!$this->configurado()) {
            throw new RuntimeException('SMTP sin contraseña configurada.');
        }

        $mail = new PHPMailer(true);

        try {
            $mail->isSMTP();
            $mail->Host       = $this->smtp['host'];
            $mail->Port       = $this->smtp['port'];
            $mail->SMTPAuth   = true;
            $mail->Username   = $this->smtp['usuario'];
            $mail->Password   = $this->smtp['password'];
            $mail->SMTPSecure = $this->smtp['seguridad'] === 'ssl'
                ? PHPMailer::ENCRYPTION_SMTPS
                : PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Timeout    = $this->smtp['timeout'];
            $mail->CharSet    = 'UTF-8';
            $mail->Encoding   = 'base64';

            if (!$this->smtp['verificar_certificado']) {
                // Solo si el servidor de correo tiene certificado autofirmado
                $mail->SMTPOptions = ['ssl' => [
                    'verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true,
                ]];
            }

            $mail->setFrom($this->smtp['usuario'], $this->smtp['remitente_nombre']);
            $mail->addAddress($hoja['correo'], $hoja['nombre']);

            // Copia oculta a la bandeja interna
            $mail->addBCC($this->smtp['copia_interna']);

            // Las respuestas del consumidor deben caer en la bandeja interna
            $mail->addReplyTo($this->smtp['copia_interna'], $this->proveedor['razon_social']);

            $mail->Subject = sprintf(
                '%s %s registrado — %s',
                $hoja['tipo_reclamo'],
                $hoja['correlativo'],
                $this->proveedor['razon_social']
            );

            $mail->isHTML(true);
            $mail->Body    = $this->cuerpoHtml($hoja);
            $mail->AltBody = $this->cuerpoTexto($hoja);

            $mail->addStringAttachment(
                $pdf,
                'Hoja-de-Reclamacion-' . $hoja['correlativo'] . '.pdf',
                PHPMailer::ENCODING_BASE64,
                'application/pdf'
            );

            $mail->send();
        } catch (PHPMailerException $e) {
            // El mensaje de PHPMailer puede incluir detalles del servidor:
            // se propaga hacia el log, nunca hacia el navegador.
            throw new RuntimeException('Fallo SMTP: ' . $e->getMessage(), 0, $e);
        }
    }

    private function cuerpoHtml(array $h): string
    {
        $e = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $filas = '';
        foreach ([
            'Número'           => $h['correlativo'],
            'Tipo'             => $h['tipo_reclamo'],
            'Fecha de registro'=> $h['fecha_larga'],
            'Consumidor'       => $h['nombre'],
            'Documento'        => $h['tipo_documento'] . ' ' . $h['documento'],
            'Bien contratado'  => $h['tipo_bien'] . ' — ' . $h['bien_desc'],
        ] as $rotulo => $valor) {
            $filas .= '<tr>'
                . '<td style="padding:7px 14px 7px 0;color:#6e747c;font-size:13px;white-space:nowrap;vertical-align:top">' . $e($rotulo) . '</td>'
                . '<td style="padding:7px 0;color:#1a1a1c;font-size:13px;font-weight:600">' . $e($valor) . '</td>'
                . '</tr>';
        }

        return '<!DOCTYPE html><html lang="es"><head><meta charset="utf-8"></head>'
            . '<body style="margin:0;padding:24px;background:#f6f7f9;font-family:Arial,Helvetica,sans-serif">'
            . '<div style="max-width:560px;margin:0 auto;background:#fff;border-radius:10px;overflow:hidden;border:1px solid #e3e6ea">'

            . '<div style="background:#0e0e10;padding:20px 26px">'
            . '<div style="color:#fff;font-size:17px;font-weight:700">' . $e($this->proveedor['razon_social']) . '</div>'
            . '<div style="color:#ed1c24;font-size:13px;font-weight:700;margin-top:3px">Libro de Reclamaciones</div>'
            . '</div>'

            . '<div style="padding:26px">'
            . '<p style="margin:0 0 16px;color:#1a1a1c;font-size:15px">Hola ' . $e($h['nombre']) . ',</p>'
            . '<p style="margin:0 0 20px;color:#4a4f57;font-size:14px;line-height:1.6">'
            . 'Registramos tu ' . $e(mb_strtolower($h['tipo_reclamo'])) . ' en nuestro Libro de Reclamaciones. '
            . 'Adjuntamos la Hoja de Reclamación en PDF como constancia.</p>'

            . '<table style="width:100%;border-collapse:collapse;background:#f6f7f9;border-radius:8px;padding:8px">'
            . '<tbody>' . $filas . '</tbody></table>'

            . '<p style="margin:20px 0 0;color:#4a4f57;font-size:14px;line-height:1.6">'
            . 'Te responderemos en un plazo no mayor de <strong>' . $this->plazoDias . ' días hábiles</strong>, '
            . 'conforme al artículo 24 de la Ley 29571.</p>'

            . '<p style="margin:16px 0 0;color:#6e747c;font-size:12px;line-height:1.6">'
            . 'Presentar este reclamo no limita tu derecho a acudir a otras vías de solución de controversias '
            . 'ni es requisito previo para denunciar ante el INDECOPI.</p>'
            . '</div>'

            . '<div style="padding:16px 26px;border-top:1px solid #e3e6ea;color:#6e747c;font-size:12px">'
            . $e($this->proveedor['direccion']) . '<br>'
            . 'RUC ' . $e($this->proveedor['ruc']) . ' · ' . $e($this->proveedor['web'])
            . '</div>'

            . '</div></body></html>';
    }

    private function cuerpoTexto(array $h): string
    {
        return implode("\n", [
            $this->proveedor['razon_social'] . ' — Libro de Reclamaciones',
            '',
            'Hola ' . $h['nombre'] . ',',
            '',
            'Registramos tu ' . mb_strtolower($h['tipo_reclamo']) . '. Adjuntamos la Hoja de Reclamación en PDF.',
            '',
            'Número: ' . $h['correlativo'],
            'Tipo: ' . $h['tipo_reclamo'],
            'Fecha de registro: ' . $h['fecha_larga'],
            'Consumidor: ' . $h['nombre'] . ' (' . $h['tipo_documento'] . ' ' . $h['documento'] . ')',
            'Bien contratado: ' . $h['tipo_bien'] . ' - ' . $h['bien_desc'],
            '',
            'Te responderemos en un plazo no mayor de ' . $this->plazoDias . ' dias habiles,',
            'conforme al articulo 24 de la Ley 29571.',
            '',
            'Presentar este reclamo no limita tu derecho a acudir a otras vias de solucion',
            'de controversias ni es requisito previo para denunciar ante el INDECOPI.',
            '',
            $this->proveedor['direccion'],
            'RUC ' . $this->proveedor['ruc'] . ' - ' . $this->proveedor['web'],
        ]);
    }
}
