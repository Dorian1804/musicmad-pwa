<?php

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/social.php';
if (!in_array($_SERVER['REQUEST_METHOD'], ['GET','HEAD'], true)) {
    header('Allow: GET, HEAD');
    http_response_code(405);
    exit;
}
if (!estaAutenticado()) {
    http_response_code(401);
    exit;
}
header('Cache-Control: private, no-store');
try {
    limiteChat((int)sesion('usuario_id'), 'clip_medio', 120);
    $clip = clipDisponiblePara((int)sesion('usuario_id'), idCampo($_GET, 'id'));
} catch (OverflowException $e) {
    header('Retry-After: 60');
    http_response_code(429);
    exit;
} catch (Throwable $e) {
    error_log('Clip media: ' . get_class($e));
    http_response_code(503);
    exit;
}
if (!$clip) {
    http_response_code(404);
    header('Cache-Control: private, no-store');
    exit;
}
$_GET['id'] = (string)$clip['publicacion_id'];
require __DIR__ . ($clip['tipo'] === 'audio' ? '/audio.php' : '/video.php');
