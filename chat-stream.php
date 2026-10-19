<?php

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/social.php';
require_once __DIR__ . '/includes/chat_live.php';
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    exit;
}
if (!estaAutenticado()) {
    http_response_code(401);
    exit;
}
if (!chatStreamDisponible()) {
    http_response_code(503);
    exit;
}
$yo = (int)sesion('usuario_id');
$con = idCampo($_GET, 'con');
$after = idCampo($_GET, 'after');
$selector = (string)($_SESSION['device_selector'] ?? '');
$hash = hash_hmac('sha256', session_id(), prepararClaveLimites());
$version = (int)($_SESSION['auth_version'] ?? -1);
session_write_close();
$lease = '';
try {
    autorizarHiloChat($yo, $con);
    limiteChat($yo, 'lectura', 120);
    $lease = reservarStreamChat($yo);
    if ($lease === '') {
        http_response_code(429);
        header('Retry-After: 8');
        exit;
    }
    header('Content-Type: text/event-stream; charset=utf-8');
    header('X-Accel-Buffering: no');
    // Each Apache request ends after six seconds. Session locks are already released.
    while (ob_get_level() > 0) {
        ob_end_flush();
    }
    $start = microtime(true);
    $previous = '';
    do {
        if (!sesionStreamChatValida($yo, $selector, $hash, $version)) {
            echo "event: auth\ndata: {}\n\n";
            flush();
            break;
        }
        $result = leerChatIncremental($yo, $con, $after);
        $body = json_encode(['ok' => true] + $result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if ($body !== $previous) {
            echo "event: chat\ndata: " . $body . "\n\n";
            flush();
            $previous = $body;
            foreach ($result['mensajes'] as $message) {
                $after = max($after, $message['id']);
            }
        } else {
            echo ": keepalive\n\n";
            flush();
        }
        if (connection_aborted() || microtime(true) - $start >= 4) {
            break;
        }
        if (!$result['mas']) {
            usleep(2000000);
        }
    } while (microtime(true) - $start < 6);
    echo "event: done\ndata: {}\n\n";
    flush();
} catch (LogicException|OverflowException $error) {
    if (!headers_sent()) {
        http_response_code(404);
    } else {
        echo "event: unavailable\ndata: {}\n\n";
        flush();
    }
} catch (Throwable $error) {
    error_log('Chat stream: ' . get_class($error));
    if (!headers_sent()) {
        http_response_code(503);
    }
} finally {
    if ($lease !== '') {
        getDB()->prepare('DELETE FROM musicmad_chat_stream_leases WHERE usuario_id=? AND lease=?')->execute([$yo,$lease]);
    }
}
