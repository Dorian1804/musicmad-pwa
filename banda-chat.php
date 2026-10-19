<?php

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/social.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
function respuestaChatBanda(int $code, array $body): never
{
    http_response_code($code);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
if (!in_array($_SERVER['REQUEST_METHOD'], ['GET','POST'], true)) {
    header('Allow: GET, POST');
    respuestaChatBanda(405, ['ok' => false]);
}
if (!estaAutenticado()) {
    respuestaChatBanda(401, ['ok' => false,'mensaje' => 'Inicia sesión de nuevo.']);
}
$yo = (int)sesion('usuario_id');
try {
    if (!bandasWorkspaceDisponible()) {
        respuestaChatBanda(503, ['ok' => false,'mensaje' => 'Falta terminar la actualización de bandas.']);
    }
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $band = idCampo($_GET, 'con');
        $after = idCampo($_GET, 'after');
        $before = idCampo($_GET, 'before');
        if ($after && $before) {
            throw new InvalidArgumentException('Cursor no válido.');
        }
        session_write_close();
        limiteChat($yo, 'grupo_leer', 120);
        respuestaChatBanda(200, ['ok' => true] + leerChatBanda($yo, $band, $after, $before));
    }
    if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 8192) {
        respuestaChatBanda(413, ['ok' => false]);
    }
    $raw = file_get_contents('php://input', false, null, 0, 8193);
    if (!is_string($raw) || strlen($raw) > 8192) {
        respuestaChatBanda(413, ['ok' => false]);
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        throw new InvalidArgumentException('Solicitud no válida.');
    }
    $csrf = $_SERVER['HTTP_X_MUSICMAD_CSRF'] ?? '';
    if (!is_string($csrf) || $csrf === '' || !hash_equals(csrfToken(), $csrf)) {
        respuestaChatBanda(403, ['ok' => false,'mensaje' => 'Actualiza la página para renovar tu sesión.']);
    }
    session_write_close();
    $band = idCampo($data, 'con');
    if (textoCampo($data, 'accion') === 'enviar') {
        respuestaChatBanda(200, ['ok' => true,'mensaje' => enviarChatBanda($yo, $band, textoCampo($data, 'texto'), textoCampo($data, 'solicitud'), idCampo($data, 'pista'))]);
    }
    if (textoCampo($data, 'accion') === 'leer') {
        marcarChatBanda($yo, $band, idCampo($data, 'hasta'));
        respuestaChatBanda(200, ['ok' => true]);
    }
    throw new InvalidArgumentException('Acción no válida.');
} catch (OverflowException $error) {
    header('Retry-After: 60');
    respuestaChatBanda(429, ['ok' => false,'mensaje' => $error->getMessage()]);
} catch (OutOfBoundsException $error) {
    respuestaChatBanda(404, ['ok' => false,'mensaje' => $error->getMessage()]);
} catch (LogicException $error) {
    respuestaChatBanda(422, ['ok' => false,'mensaje' => $error->getMessage()]);
} catch (Throwable $error) {
    error_log('Band chat: ' . get_class($error));
    respuestaChatBanda(503, ['ok' => false,'mensaje' => 'No pudimos conectar. Conserva tu mensaje y reintenta.']);
}
