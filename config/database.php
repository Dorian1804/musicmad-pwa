<?php

require_once dirname(__DIR__) . '/includes/error_response.php';
// ============================================================
//  MusicMad â€“ ConfiguraciÃ³n de base de datos
//  config/database.php
//
//  Valores por defecto para XAMPP (root, sin contraseÃ±a).
//  Para cambiarlos NO edites este archivo: crea config/local.php
//  (copia de config/local.example.php) o usa variables de entorno.
// ============================================================

require_once dirname(__DIR__) . '/includes/private_environment.php';

// Prefer configuration outside every public root. Keep the legacy fallback
// during migration; the hosting operator can move it after this file is deployed.
$testOnlyMusicMad = defined('MUSICMAD_TEST_RUN') && MUSICMAD_TEST_RUN === true
    && (in_array(PHP_SAPI, ['cli', 'cli-server'], true)
        || (PHP_SAPI === 'apache2handler' && defined('DB_NAME') && DB_NAME === 'musicmad_qa'
            && defined('DB_HOST') && DB_HOST === '127.0.0.1;port=3308'));
$envFileMusicMad = null;
if (!$testOnlyMusicMad) {
    $envFileMusicMad = musicmadMigratePublicEnvironment(dirname(__DIR__), (string) ($_SERVER['DOCUMENT_ROOT'] ?? ''))
        ?? dirname(__DIR__) . '/.env';
}
if ($envFileMusicMad !== null && is_file($envFileMusicMad) && !is_link($envFileMusicMad)) {
    $envMusicMad = @parse_ini_file($envFileMusicMad, false, INI_SCANNER_RAW);
    if (is_array($envMusicMad)) {
        foreach ($envMusicMad as $envKeyMusicMad => $envValueMusicMad) {
            if (preg_match('/^MM_(DB_HOST|DB_NAME|DB_USER|DB_PASS|GOOGLE_CLIENT_ID|GOOGLE_CLIENT_SECRET|GOOGLE_REDIRECT_URI|JWT_SECRET|ENC_KEY|ENC_KEY_LEGACY_UNTIL|VAPID_PUBLIC_KEY|VAPID_PRIVATE_KEY|VAPID_SUBJECT|APP_URL|PUBLIC_URL|FFMPEG_PATH|TRUST_PROXY|CHAT_SSE|FEATURE_FLAGS|MEDIA_BACKUP_DIR|MAIL_HOST|MAIL_PORT|MAIL_USER|MAIL_PASS|MAIL_FROM|MAIL_NAME|MAIL_ENCRYPTION|MAIL_TRANSPORT)$/', (string) $envKeyMusicMad)
                && is_string($envValueMusicMad) && !str_contains($envValueMusicMad, "\0")) {
                if (getenv($envKeyMusicMad) === false) {
                    putenv($envKeyMusicMad . '=' . $envValueMusicMad);
                    $_ENV[$envKeyMusicMad] = $envValueMusicMad;
                }
            }
        }
    }
}

$_local = __DIR__ . '/local.php';
if (!$testOnlyMusicMad && is_file($_local)) {
    require_once $_local;
}

$hostHeader = strtolower((string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
$peer = (string)($_SERVER['REMOTE_ADDR'] ?? '');
$loopback = $peer === '' || $peer === '::1' || preg_match('/^127\\.\\d{1,3}\\.\\d{1,3}\\.\\d{1,3}$/D', $peer);
$esLocal = $loopback && preg_match('/^(localhost|127(?:\\.\\d{1,3}){3}|\\[::1\\])(?::\\d+)?$/', $hostHeader) === 1;
if (!$esLocal) {
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
}
$hayConfigCompleta = defined('DB_HOST') && defined('DB_NAME') && defined('DB_USER') && defined('DB_PASS');
$hayEnvCompleto = getenv('MM_DB_HOST') !== false && getenv('MM_DB_NAME') !== false
               && getenv('MM_DB_USER') !== false && getenv('MM_DB_PASS') !== false;

if (!$esLocal && !$hayConfigCompleta && !$hayEnvCompleto) {
    error_log('MusicMad database configuration unavailable.');
    responderErrorMusicMad(500);
}

if (!defined('DB_HOST')) {
    define('DB_HOST', getenv('MM_DB_HOST') ?: ($esLocal ? '127.0.0.1' : ''));
}
if (!defined('DB_NAME')) {
    define('DB_NAME', getenv('MM_DB_NAME') ?: ($esLocal ? 'REPLACE_WITH_LOCAL_VALUE' : ''));
}
if (!defined('DB_USER')) {
    define('DB_USER', getenv('MM_DB_USER') ?: ($esLocal ? 'root' : ''));
}
if (!defined('DB_PASS')) {
    define('DB_PASS', getenv('MM_DB_PASS') !== false ? getenv('MM_DB_PASS') : '');
}
if (!defined('DB_CHARSET')) {
    define('DB_CHARSET', 'utf8mb4');
}

if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}

require_once APP_ROOT . '/includes/security_headers.php';
require_once APP_ROOT . '/includes/rate_limit.php';

// URL base de la app, calculada sola: funciona en
// http://localhost/MusicMad, en un dominio raÃ­z o en cualquier subcarpeta.
if (!defined('APP_URL')) {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
           || (getenv('MM_TRUST_PROXY') === '1' && ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
    if (!preg_match('/^(?:[a-z0-9](?:[a-z0-9.-]{0,251}[a-z0-9])?|\\[::1\\])(?::[0-9]{1,5})?$/iD', $host)) {
        http_response_code(400);
        exit('Dominio no vÃ¡lido.');
    }
    $carpeta = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    $carpeta = preg_replace('#/api/v1$#', '', $carpeta);
    $canonical = rtrim((string)(getenv('MM_APP_URL') ?: ''), '/');
    if ($canonical !== '' && (!filter_var($canonical, FILTER_VALIDATE_URL) || !in_array(parse_url($canonical, PHP_URL_SCHEME), ['https','http'], true) || parse_url($canonical, PHP_URL_QUERY) !== null || parse_url($canonical, PHP_URL_FRAGMENT) !== null || parse_url($canonical, PHP_URL_USER) !== null)) {
        http_response_code(503);
        exit('La direcciÃ³n configurada no es vÃ¡lida.');
    }
    define('APP_URL', $canonical !== '' ? $canonical : ($https ? 'https' : 'http') . '://' . $host . $carpeta);
}

/**
 * ConexiÃ³n PDO reutilizable (singleton).
 */
function getDB(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $isolatedTest = defined('MUSICMAD_TEST_RUN') && MUSICMAD_TEST_RUN === true;
        $host = $isolatedTest ? DB_HOST : musicmadConfigValue('DB_HOST');
        $database = $isolatedTest ? DB_NAME : musicmadConfigValue('DB_NAME');
        $user = $isolatedTest ? DB_USER : musicmadConfigValue('DB_USER');
        $REPLACE_WITH_LOCAL_VALUE = $isolatedTest ? DB_PASS : musicmadConfigValue('DB_PASS');
        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', $host, $database, DB_CHARSET);
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];

        try {
            $pdo = new PDO($dsn, $user, $REPLACE_WITH_LOCAL_VALUE, $options);
        } catch (PDOException $e) {
            error_log('MusicMad database connection failed: ' . get_class($e));
            throw new RuntimeException('MusicMad database unavailable.', 0, $e);
        }
    }

    return $pdo;
}


require_once APP_ROOT . '/includes/media_storage.php';
if (!$testOnlyMusicMad) {
    require_once APP_ROOT . '/includes/public_artifacts.php';
    try {
        musicmadRetirePublicArtifacts();
    } catch (Throwable $error) {
        error_log('MusicMad public artifact maintenance unavailable.');
    }
}
