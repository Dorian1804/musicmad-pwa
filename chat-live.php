<?php

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/social.php';
require_once __DIR__ . '/includes/chat_live.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
function respuestaChat(int $code, array $body): never
{
    http_response_code($code);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
if (!in_array($_SERVER['REQUEST_METHOD'], ['GET','POST'], true)) {
    header('Allow: GET, POST');
    respuestaChat(405, ['ok' => false]);
}
if (!estaAutenticado()) {
    respuestaChat(401, ['ok' => false,'mensaje' => 'Inicia sesión de nuevo.']);
}
if (!chatLiveDisponible()) {
    respuestaChat(503, ['ok' => false,'mensaje' => 'El chat necesita terminar una actualización.']);
}
$yo = (int)sesion('usuario_id');
try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $con = idCampo($_GET, 'con');
        $after = idCampo($_GET, 'after');
        $before = idCampo($_GET, 'before');
        if ($after && $before) {
            throw new InvalidArgumentException('Cursor no válido.');
        }
        // Release the PHP lock before database work; uploads and navigation remain available.
        session_write_close();
        limiteChat($yo, 'lectura', 120);
        $result = leerChatIncremental($yo, $con, $after, $before);
        respuestaChat(200, ['ok' => true] + $result);
    }
    if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 8192) {
        respuestaChat(413, ['ok' => false]);
    }
    $raw = file_get_contents('php://input', false, null, 0, 8193);
    if (!is_string($raw) || strlen($raw) > 8192) {
        respuestaChat(413, ['ok' => false]);
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        throw new InvalidArgumentException('Solicitud no válida.');
    }
    $csrf = $_SERVER['HTTP_X_MUSICMAD_CSRF'] ?? '';
    if (!is_string($csrf) || $csrf === '' || !hash_equals(csrfToken(), $csrf)) {
        respuestaChat(403, ['ok' => false,'mensaje' => 'Actualiza la página para renovar tu sesión.']);
    }
    session_write_close();
    $action = textoCampo($data, 'accion');
    $con = idCampo($data, 'con');
    if ($action === 'enviar') {
        respuestaChat(200, ['ok' => true,'mensaje' => enviarChatSeguro($yo, $con, textoCampo($data, 'texto'), textoCampo($data, 'solicitud'), idCampo($data, 'pista'))]);
    }
    if ($action === 'leer') {
        marcarChatHasta($yo, $con, idCampo($data, 'hasta'));
        respuestaChat(200, ['ok' => true,'contadores' => contadoresChat($yo)]);
    }
    if ($action === 'actividad') {
        actividadChat($yo, $con, ($data['escribiendo'] ?? false) === true);
        respuestaChat(200, ['ok' => true]);
    }
    if ($action === 'avisos') {
        marcarAvisosHasta($yo, idCampo($data, 'hasta'));
        marcarAvisosEventosHasta($yo, idCampo($data, 'eventos'));
        respuestaChat(200, ['ok' => true,'contadores' => contadoresChat($yo)]);
    }
    if ($action === 'preferencias') {
        guardarPreferenciasChat($yo, $data);
        respuestaChat(200, ['ok' => true]);
    }
    throw new InvalidArgumentException('Acción no válida.');
} catch (OverflowException $error) {
    header('Retry-After: 60');
    respuestaChat(429, ['ok' => false,'mensaje' => $error->getMessage()]);
} catch (OutOfBoundsException $error) {
    respuestaChat(404, ['ok' => false,'mensaje' => $error->getMessage()]);
} catch (LogicException $error) {
    respuestaChat(422, ['ok' => false,'mensaje' => $error->getMessage()]);
} catch (Throwable $error) {
    error_log('Chat transport: ' . get_class($error));
    respuestaChat(503, ['ok' => false,'mensaje' => 'No pudimos conectar. Conserva tu mensaje y reintenta.']);
}
