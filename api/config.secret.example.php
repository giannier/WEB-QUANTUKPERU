<?php
/**
 * Plantilla del archivo de credenciales.
 *
 * AL DESPLEGAR:
 *   1. Copiar este archivo como  api/config.secret.php
 *   2. Reemplazar los textos de abajo por los valores reales.
 *
 * config.secret.php está excluido del repositorio a propósito: las
 * credenciales nunca deben entrar al historial de Git.
 */

return [
    // Contraseña del buzón reclamaciones@quantukperu.com
    'smtp_password' => 'PEGAR-AQUI-LA-CONTRASENA',

    // hCaptcha (https://dashboard.hcaptcha.com). El sitio debe tener
    // registrado quantukperu.com. Sin estas claves el envío se rechaza.
    'hcaptcha_site_key' => 'TU_SITE_KEY_AQUI',
    'hcaptcha_secret'   => 'TU_SECRET_KEY_AQUI',
];
