<?php

// ============================================================
//  MusicMad – Configuración de correo
//  config/mail.php
//
//  Las credenciales NO van aquí: se definen en config/local.php.
//  Si MAIL_USER / MAIL_PASS están vacíos, el sitio funciona igual
//  pero no se envían correos.
// ============================================================
require_once __DIR__ . '/database.php';   // carga local.php

if (!defined('MAIL_USER')) {
    define('MAIL_USER', getenv('MM_MAIL_USER') ?: '');
}
if (!defined('MAIL_PASS')) {
    define('MAIL_PASS', getenv('MM_MAIL_PASS') ?: '');
}
if (!defined('MAIL_OWNER')) {
    define('MAIL_OWNER', getenv('MM_MAIL_OWNER') ?: MAIL_USER);
}
if (!defined('MAIL_NAME')) {
    define('MAIL_NAME', 'MusicMad');
}
