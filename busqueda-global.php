<?php

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/social.php';
require_once __DIR__ . '/includes/chat_live.php';
require_once __DIR__ . '/includes/global_search.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    echo '{"ok":false}';
    exit;
}
if (!estaAutenticado()) {
    http_response_code(401);
    echo '{"ok":false}';
    exit;
}
$yo = (int)sesion('usuario_id');
session_write_close();
try {
    if (chatLiveDisponible()) {
        limiteChat($yo, 'buscar', 90);
    }
    $results = buscarGlobal($yo, textoCampo($_GET, 'q'));
    echo json_encode(['ok' => true,'resultados' => $results], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (OverflowException $error) {
    http_response_code(429);
    echo '{"ok":false,"mensaje":"Espera un momento antes de volver a buscar."}';
} catch (Throwable $error) {
    error_log('Global search: ' . get_class($error));
    http_response_code(503);
    echo '{"ok":false,"mensaje":"No pudimos buscar ahora. Reintenta con conexión."}';
}
