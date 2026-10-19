<?php

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/social.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store');
function respuestaMapa(int $status, array $body): never
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    respuestaMapa(405, ['ok' => false]);
}
if (!estaAutenticado()) {
    respuestaMapa(401, ['ok' => false,'mensaje' => 'Inicia sesión para explorar la escena.']);
}
$yo = (int)sesion('usuario_id');
session_write_close();
try {
    limiteChat($yo, 'mapa_leer', 60);
    respuestaMapa(200, ['ok' => true,'puntos' => puntosMapaEscena($yo, $_GET, textoCampo($_GET, 'tipo') ?: 'todos', textoCampo($_GET, 'ciudad'))]);
} catch (OverflowException $e) {
    respuestaMapa(429, ['ok' => false,'mensaje' => 'Espera un momento antes de explorar otra zona.']);
} catch (LogicException $e) {
    respuestaMapa(422, ['ok' => false,'mensaje' => 'Revisa el área y los filtros del mapa.']);
} catch (Throwable $e) {
    error_log('Scene map: ' . get_class($e));
    respuestaMapa(503, ['ok' => false,'mensaje' => 'No pudimos cargar la zona. Reintenta.']);
}
