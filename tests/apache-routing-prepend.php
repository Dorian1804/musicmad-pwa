<?php

// Only the isolated, loopback Apache instances started by run-routing-apache.py.
if (PHP_SAPI !== 'apache2handler' || ($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1'
    || !in_array((int)($_SERVER['SERVER_PORT'] ?? 0), [8766, 8767], true)) {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/paths.php';
$qaEnvironment = parse_ini_file(musicmadQaRoot() . '/.env', false, INI_SCANNER_RAW);
define('MUSICMAD_TEST_RUN', true);
define('DB_HOST', '127.0.0.1;port=3308');
define('DB_NAME', 'musicmad_qa');
define('DB_USER', 'root');
define('DB_PASS', $qaEnvironment['MM_TEST_DB_PASS']);
define('APP_UPLOAD_ROOT', musicmadQaRoot() . '/uploads');
$qaBase = (int)$_SERVER['SERVER_PORT'] === 8766 ? '/MusicMad_PWA' : '';
putenv('MM_APP_URL=http://127.0.0.1:' . (int)$_SERVER['SERVER_PORT'] . $qaBase);
putenv('MM_PUBLIC_URL=https://127.0.0.1:' . (int)$_SERVER['SERVER_PORT'] . $qaBase);
putenv('MM_ENC_KEY=' . str_repeat('qa-apache-only-key-', 4));
putenv('MM_JWT_SECRET=' . str_repeat('qa-apache-only-jwt-', 4));
putenv('MM_ENC_KEY_LEGACY_UNTIL=0');
foreach (['MAIL_HOST', 'MAIL_USER', 'MAIL_PASS', 'MAIL_FROM', 'VAPID_PUBLIC_KEY', 'VAPID_PRIVATE_KEY', 'VAPID_SUBJECT'] as $qaSetting) {
    putenv('MM_' . $qaSetting);
}
