<?php

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/social.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store');
if (!estaAutenticado()) {
    http_response_code(401);
    echo '{"ok":false,"mensaje":"Inicia sesión de nuevo."}';
    exit;
}
if (!in_array($_SERVER['REQUEST_METHOD'], ['GET','POST'], true)) {
    header('Allow: GET, POST');
    http_response_code(405);
    echo '{"ok":false,"mensaje":"Método no permitido."}';
    exit;
}
$uid = (int)sesion('usuario_id');
try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $after = idCampo($_GET, 'despues');
        $before = idCampo($_GET, 'antes');
        if ($after && $before) {
            throw new InvalidArgumentException('Elige un cursor.');
        }
        session_write_close();
        $result = estadoSalaEscucha($uid, idCampo($_GET, 'id'), $after, $before);
    } else {
        $csrf = $_SERVER['HTTP_X_MUSICMAD_CSRF'] ?? '';
        $expected = $_SESSION['csrf'] ?? '';
        if (!is_string($csrf) || !is_string($expected) || $expected === '' || !hash_equals($expected, $csrf)) {
            http_response_code(403);
            echo '{"ok":false,"mensaje":"Recarga la sala para renovar tu sesión."}';
            exit;
        }
        session_write_close();
        $raw = file_get_contents('php://input', false, null, 0, 16385);
        if (strlen($raw) > 16384) {
            throw new InvalidArgumentException('Solicitud demasiado grande.');
        }
        $data = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new InvalidArgumentException('Solicitud no válida.');
        }
        $id = idCampo($data, 'id');
        $action = textoCampo($data, 'accion');
        if ($action === 'control') {
            $position = $data['posicion'] ?? null;
            if ((!is_int($position) && !is_float($position)) || !is_finite((float)$position)) {
                throw new InvalidArgumentException('Momento no válido.');
            }
            controlarSalaEscucha($uid, $id, idCampo($data, 'revision'), textoCampo($data, 'reproduccion'), (float)$position);
        } elseif ($action === 'mensaje') {
            enviarMensajeSala($uid, $id, textoCampo($data, 'texto'), textoCampo($data, 'solicitud'));
        } else {
            throw new InvalidArgumentException('Acción no válida.');
        }
        $result = estadoSalaEscucha($uid, $id, idCampo($data, 'despues'));
    }
    echo json_encode(['ok' => true,'sala' => $result], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
} catch (OutOfBoundsException $error) {
    http_response_code(404);
    echo json_encode(['ok' => false,'mensaje' => 'La sala no está disponible para tu cuenta.']);
} catch (UnexpectedValueException $error) {
    http_response_code(409);
    echo json_encode(['ok' => false,'mensaje' => $error->getMessage()]);
} catch (InvalidArgumentException|JsonException $error) {
    http_response_code(422);
    echo json_encode(['ok' => false,'mensaje' => 'Revisa los datos de la solicitud.']);
} catch (OverflowException $error) {
    http_response_code(429);
    echo json_encode(['ok' => false,'mensaje' => $error->getMessage()]);
} catch (Throwable $error) {
    error_log('Listening room endpoint: ' . get_class($error));
    http_response_code(503);
    echo '{"ok":false,"mensaje":"La sala no está disponible por el momento."}';
}
