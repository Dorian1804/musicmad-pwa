<?php

require_once __DIR__ . '/paths.php';
if (PHP_SAPI !== 'cli') {
    exit;
}
define('MUSICMAD_TEST_RUN', true);
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['SCRIPT_NAME'] = '/MusicMad_PWA/tests/qa';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/social.php';
try {
    $result = analizarMedioLocal(musicmadQaRoot() . '/qa-video.webm', 'video');
    echo json_encode(['mime' => $result['mime'],'ancho' => $result['ancho'],'alto' => $result['alto'],'codec' => $result['codec'],'duracion' => $result['duracion']], JSON_UNESCAPED_UNICODE) . "\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
