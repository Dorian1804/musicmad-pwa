<?php

if (PHP_SAPI !== 'cli-server' || !defined('MUSICMAD_TEST_RUN') || !MUSICMAD_TEST_RUN) {
    http_response_code(404);
    exit;
}
require dirname(__DIR__) . '/includes/auth.php';
if (($_GET['modo'] ?? '') === 'json') {
    header('Content-Type: application/json');
}
if (($_GET['modo'] ?? '') === 'fatal') {
    trigger_error('FICTITIOUS_SECRET_DO_NOT_RENDER', E_USER_ERROR);
    exit;
}
ob_start();
echo '<h1>INCOMPLETE_PRIVATE_QA_OUTPUT</h1>';
throw new RuntimeException('FICTITIOUS_SECRET_DO_NOT_RENDER');
