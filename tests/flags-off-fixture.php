<?php

if (!defined('MUSICMAD_TEST_RUN') || MUSICMAD_TEST_RUN !== true || PHP_SAPI !== 'cli-server') {
    http_response_code(404);
    exit;
}
putenv('MM_FEATURE_FLAGS={"playlists_equipo":false}');
$_SERVER['SCRIPT_NAME'] = '/playlist-equipo.php';
require dirname(__DIR__) . '/includes/auth.php';
throw new RuntimeException('Disabled feature guard did not stop the request.');
