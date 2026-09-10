<?php
/**
 * Endpoint del Libro de Reclamaciones.
 *
 * Orden de las comprobaciones, de la más barata a la más cara: primero se
 * descartan los envíos ilegítimos y recién al final se generan el PDF y el
 * correo, que son las operaciones costosas.
 *
 * Los mensajes que salen hacia el navegador son deliberadamente genéricos.
 * El detalle técnico va al log del servidor: describirle a un atacante qué
 * comprobación falló es regalarle el mapa.
 */

declare(strict_types=1);

// -------------------------------------------------------------------------
// Endurecimiento del entorno
// -------------------------------------------------------------------------
ini_set('display_errors', '0');          // nunca mostrar trazas al visitante
ini_set('log_errors', '1');
error_reporting(E_ALL);

$config = require __DIR__ . '/config.php';

date_default_timezone_set($config['timezone']);
ini_set('error_log', $config['storage'] . '/log/php-error.log');

require_once __DIR__ . '/src/Seguridad.php';
require_once __DIR__ . '/src/Validador.php';
require_once __DIR__ . '/src/Correlativo.php';
require_once __DIR__ . '/src/HojaPdf.php';
require_once __DIR__ . '/src/Notificador.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
header('Referrer-Policy: same-origin');

/** Respuesta JSON y fin. */
function responder(int $codigo, array $datos): never
{
    http_response_code($codigo);
    echo json_encode($datos, JSON_UNESCAPED_UNICODE);
    exit;
}

/** Anota en el log interno sin exponer nada al cliente. */
function anotar(array $config, string $nivel, string $mensaje): void
{
    $dir = $config['storage'] . '/log';
    if (!is_dir($dir)) {
        mkdir($dir, 0750, true);
    }
    @file_put_contents(
        $dir . '/reclamos.log',
        sprintf("[%s] %-7s %s\n", date('c'), $nivel, $mensaje),
        FILE_APPEND | LOCK_EX
    );
}

$seguridad = new Seguridad($config);
$ip = $seguridad->ip();

// -------------------------------------------------------------------------
// 1. Método y tamaño
// -------------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    responder(405, ['ok' => false, 'mensaje' => 'Método no permitido.']);
}

// 64 KB alcanza de sobra para este formulario; por encima, se corta
if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 65536) {
    anotar($config, 'BLOCK', "cuerpo excesivo desde $ip");
    responder(413, ['ok' => false, 'mensaje' => 'La solicitud es demasiado grande.']);
}

// -------------------------------------------------------------------------
// 2. Mismo origen
//    El formulario solo se envía desde el propio sitio. Un POST llegado de
//    otro dominio no tiene por qué ser atendido.
// -------------------------------------------------------------------------
$origen = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origen !== '') {
    $hostOrigen = parse_url($origen, PHP_URL_HOST) ?: '';
    $hostPropio = $_SERVER['HTTP_HOST'] ?? '';
    if (strcasecmp($hostOrigen, $hostPropio) !== 0
        && strcasecmp($hostOrigen, 'www.' . $hostPropio) !== 0) {
        anotar($config, 'BLOCK', "origen cruzado '$origen' desde $ip");
        responder(403, ['ok' => false, 'mensaje' => 'Solicitud no autorizada.']);
    }
}

// -------------------------------------------------------------------------
// 3. Trampa para robots
// -------------------------------------------------------------------------
if (!$seguridad->honeypotLimpio($_POST)) {
    anotar($config, 'BOT', "honeypot relleno desde $ip");
    // Se responde como si hubiera salido bien: si el bot detecta el rechazo,
    // ajusta su script y vuelve.
    responder(200, ['ok' => true, 'correlativo' => 'LR-0000-000000']);
}

// -------------------------------------------------------------------------
// 4. Token de sesión y tiempo de llenado
// -------------------------------------------------------------------------
$motivo = null;
if (!$seguridad->tokenValido((string) ($_POST['token'] ?? ''), $motivo)) {
    anotar($config, 'BLOCK', "token invalido ($motivo) desde $ip");

    if ($motivo === 'token_vencido') {
        responder(419, ['ok' => false, 'mensaje' => 'El formulario expiró. Recarga la página e inténtalo de nuevo.']);
    }
    responder(403, ['ok' => false, 'mensaje' => 'No pudimos validar el formulario. Recarga la página e inténtalo de nuevo.']);
}

// -------------------------------------------------------------------------
// 5. Límite por IP
// -------------------------------------------------------------------------
if (!$seguridad->permitido($ip, $motivo)) {
    anotar($config, 'LIMIT', "limite '$motivo' alcanzado por $ip");
    responder(429, [
        'ok' => false,
        'mensaje' => 'Has registrado varios reclamos en poco tiempo. '
                   . 'Inténtalo más tarde o escríbenos a ' . $config['smtp']['copia_interna'] . '.',
    ]);
}

// -------------------------------------------------------------------------
// 6. Validación de los campos
// -------------------------------------------------------------------------
$validador = new Validador();

if (!$validador->validar($_POST)) {
    responder(422, [
        'ok'      => false,
        'mensaje' => 'Revisa los campos marcados.',
        'errores' => $validador->errores(),
    ]);
}

$datos = $validador->datos();

// -------------------------------------------------------------------------
// 7. Registro de la hoja
// -------------------------------------------------------------------------
try {
    $correlativo = (new Correlativo($config['storage']))->siguiente();
} catch (Throwable $e) {
    anotar($config, 'ERROR', 'correlativo: ' . $e->getMessage());
    responder(500, ['ok' => false, 'mensaje' => 'No pudimos registrar el reclamo. Inténtalo en unos minutos.']);
}

$meses = ['', 'enero','febrero','marzo','abril','mayo','junio',
          'julio','agosto','septiembre','octubre','noviembre','diciembre'];

$hoja = $datos + [
    'correlativo'  => $correlativo,
    'fecha_iso'    => date('c'),
    'fecha_larga'  => sprintf('%s de %s de %s, %s',
                        date('j'), $meses[(int) date('n')], date('Y'), date('H:i')),
    // Código corto para que el consumidor pueda citar su hoja sin exponer datos
    'verificacion' => strtoupper(substr(hash('sha256', $correlativo . $datos['correo'] . date('c')), 0, 10)),
];

// -------------------------------------------------------------------------
// 8. PDF, auditado a una sola página
// -------------------------------------------------------------------------
try {
    $auditoria = null;
    $pdf = HojaPdf::generar($config['proveedor'], $hoja, $config['plazo_respuesta_dias_habiles'], $auditoria);

    if (($auditoria['paginas'] ?? 0) !== 1) {
        anotar($config, 'WARN', sprintf('%s ocupa %d paginas', $correlativo, $auditoria['paginas']));
    }
    if (!empty($auditoria['recortado'])) {
        anotar($config, 'WARN', $correlativo . ' con texto recortado para entrar en una hoja');
    }
} catch (Throwable $e) {
    anotar($config, 'ERROR', 'pdf: ' . $e->getMessage());
    responder(500, ['ok' => false, 'mensaje' => 'No pudimos generar la constancia. Inténtalo en unos minutos.']);
}

// -------------------------------------------------------------------------
// 9. Archivo en el servidor
//    Se guarda ANTES de enviar el correo: si el SMTP falla, el reclamo no se
//    pierde. El Art. 12 obliga a conservarlo dos años.
// -------------------------------------------------------------------------
$dirHojas = $config['storage'] . '/hojas/' . date('Y');
if (!is_dir($dirHojas)) {
    mkdir($dirHojas, 0750, true);
}

@file_put_contents($dirHojas . '/' . $correlativo . '.pdf', $pdf, LOCK_EX);
@file_put_contents(
    $dirHojas . '/' . $correlativo . '.json',
    json_encode($hoja + ['ip_hash' => hash('sha256', $ip)], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
    LOCK_EX
);

$seguridad->registrar($ip);
$seguridad->consumirToken();

// -------------------------------------------------------------------------
// 10. Envío
// -------------------------------------------------------------------------
$notificador = new Notificador($config);
$enviado = false;

if (!$notificador->configurado()) {
    anotar($config, 'ERROR', $correlativo . ' NO enviado: falta la contraseña SMTP en config.php');
} else {
    try {
        $notificador->enviar($hoja, $pdf);
        $enviado = true;
        anotar($config, 'OK', $correlativo . ' enviado a ' . $hoja['correo']);
    } catch (Throwable $e) {
        anotar($config, 'ERROR', $correlativo . ' fallo de envio: ' . $e->getMessage());
    }
}

// El reclamo quedó registrado aunque el correo falle: para el consumidor la
// operación fue exitosa, y el aviso del fallo va al log para que lo atienda
// el proveedor.
responder(200, [
    'ok'          => true,
    'correlativo' => $correlativo,
    'enviado'     => $enviado,
    'mensaje'     => $enviado
        ? 'Tu reclamo quedó registrado. Te enviamos la constancia en PDF a tu correo.'
        : 'Tu reclamo quedó registrado con el número ' . $correlativo
          . '. No pudimos enviarte el correo en este momento; guarda este número como constancia.',
]);
