<?php
/**
 * Emite el token que el formulario debe devolver al enviarse.
 *
 * Se pide por fetch al cargar la página: así el token nace en el momento en
 * que una persona abre el formulario y queda ligado a su sesión. Un bot que
 * haga POST directo al endpoint sin pasar por acá no tiene token válido.
 */

declare(strict_types=1);

$config = require __DIR__ . '/config.php';
date_default_timezone_set($config['timezone']);

require_once __DIR__ . '/src/Seguridad.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$seguridad = new Seguridad($config);
$emision = $seguridad->emitirToken();

echo json_encode([
    'token' => $emision['token'],
    // El navegador usa esto para no dejar enviar antes de tiempo
    'espera_minima' => $config['limites']['segundos_minimos'],
], JSON_UNESCAPED_UNICODE);
