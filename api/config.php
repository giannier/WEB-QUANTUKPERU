<?php
/**
 * Libro de Reclamaciones — configuración
 *
 * ┌───────────────────────────────────────────────────────────────────────┐
 * │  ANTES DE PUBLICAR: completar SMTP_PASSWORD con la contraseña de la   │
 * │  cuenta reclamaciones@quantukperu.com. Sin eso el envío falla y el     │
 * │  reclamo queda solo guardado en el servidor.                           │
 * └───────────────────────────────────────────────────────────────────────┘
 *
 * Este archivo contiene credenciales. El .htaccess de esta carpeta impide
 * que se sirva por HTTP, pero conviene además no versionarlo con la
 * contraseña dentro.
 */

declare(strict_types=1);

return [

    // ---------------------------------------------------------------------
    // Datos del proveedor — se imprimen en cada Hoja de Reclamación.
    // El Art. 5 del D.S. 011-2011-PCM exige nombre del proveedor y dirección
    // del establecimiento donde se encuentra el Libro.
    // ---------------------------------------------------------------------
    'proveedor' => [
        'razon_social' => 'QUANTUK PERÚ S.A.C.',
        'ruc'          => '20614465728',
        'direccion'    => 'Av. República de Chile 324, Int. 601, Urb. Santa Beatriz '
                        . '(Edificio Polaris), Jesús María, Lima',
        'web'          => 'quantukperu.com',
        'telefono'     => '+51 943704298',
    ],

    // ---------------------------------------------------------------------
    // Correo saliente
    // ---------------------------------------------------------------------
    'smtp' => [
        'host'       => 'mail.quantukperu.com',
        'port'       => 465,
        'seguridad'  => 'ssl',            // 465 = SSL implícito; 587 sería 'tls'
        'usuario'    => 'reclamaciones@quantukperu.com',

        /*
         * La contraseña NO va acá.
         *
         * Va en api/config.secret.php, que está excluido del repositorio.
         * Si se escribiera en este archivo, tarde o temprano alguien lo
         * commitea y la contraseña queda en el historial de Git para
         * siempre: borrarla del archivo después no la borra del historial.
         *
         * Al desplegar, copiar config.secret.example.php a
         * config.secret.php y poner la contraseña ahí dentro.
         */
        'password'   => (static function (): string {
            $secreto = __DIR__ . '/config.secret.php';
            if (!is_file($secreto)) {
                return '';
            }
            $valor = require $secreto;
            return is_string($valor) ? $valor : '';
        })(),

        'remitente_nombre' => 'Quantuk Perú — Libro de Reclamaciones',

        // Copia interna de cada reclamo. Es la bandeja que hay que atender
        // dentro del plazo legal.
        'copia_interna'    => 'reclamaciones@quantukperu.com',

        // Verificación estricta del certificado TLS. Dejar en true.
        // Ponerlo en false solo si el hosting tiene un certificado
        // autofirmado en el servidor de correo, y entendiendo el riesgo.
        'verificar_certificado' => true,

        'timeout' => 20,
    ],

    // ---------------------------------------------------------------------
    // Límites de envío por IP.
    // Un Libro de Reclamaciones legítimo recibe pocos registros por persona;
    // estos números frenan el abuso sin estorbar a un reclamante real.
    // ---------------------------------------------------------------------
    'limites' => [
        'por_hora'          => 3,
        'por_dia'           => 8,
        'global_por_hora'   => 120,   // freno de emergencia ante un flood
        'segundos_minimos'  => 6,     // llenar el formulario más rápido = bot
        'vigencia_token'    => 7200,  // 2 h de validez del token del formulario
    ],

    // ---------------------------------------------------------------------
    // Plazo legal de respuesta.
    // Ley 31435 (2022) modificó el art. 24 de la Ley 29571: son 15 días
    // hábiles IMPRORROGABLES. El D.S. 011-2011-PCM decía 30 días calendario
    // y quedó desactualizado en ese punto: muchos formatos que circulan
    // por internet todavía arrastran la cifra vieja.
    // ---------------------------------------------------------------------
    'plazo_respuesta_dias_habiles' => 15,

    // ---------------------------------------------------------------------
    // Rutas de almacenamiento
    // ---------------------------------------------------------------------
    'storage' => __DIR__ . '/storage',

    // El Art. 12 obliga a conservar las hojas dos años desde el registro
    'conservacion_anios' => 2,

    // Zona horaria: la fecha y hora del registro tienen valor probatorio
    'timezone' => 'America/Lima',
];
