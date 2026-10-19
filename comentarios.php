<?php

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/social.php';
require_once __DIR__ . '/includes/comments_engine.php';
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
    echo json_encode(['ok' => false,'mensaje' => 'Inicia sesión para ver comentarios.']);
    exit;
}
try {
    $result = paginaComentarios((int)sesion('usuario_id'), idCampo($_GET, 'pub'), idCampo($_GET, 'antes'), idCampo($_GET, 'comentario'));
    echo json_encode(['ok' => true] + $result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (OutOfBoundsException $error) {
    http_response_code(404);
    echo json_encode(['ok' => false,'mensaje' => $error->getMessage()], JSON_UNESCAPED_UNICODE);
}
