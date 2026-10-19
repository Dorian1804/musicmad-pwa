<?php

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/social.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
function responderEscucha(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    responderEscucha(['ok' => false], 405);
}
if (!estaAutenticado()) {
    responderEscucha(['ok' => false], 401);
}
$token = $_SERVER['HTTP_X_MUSICMAD_CSRF'] ?? '';
if (!is_string($token) || !hash_equals(csrfToken(), $token)) {
    responderEscucha(['ok' => false], 403);
}
$account = $_SERVER['HTTP_X_MUSICMAD_ACCOUNT'] ?? '';
if (!is_string($account) || !preg_match('/^[a-f0-9]{64}$/D', $account) || !hash_equals((string)($_SESSION['offline_scope'] ?? ''), $account)) {
    responderEscucha(['ok' => false], 409);
}
$uid = (int)sesion('usuario_id');
session_write_close();
try {
    if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 4096) {
        responderEscucha(['ok' => false], 413);
    }
    $raw = file_get_contents('php://input', false, null, 0, 4097);
    if (!is_string($raw) || strlen($raw) > 4096) {
        responderEscucha(['ok' => false], 413);
    }
    $input = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
    if (!is_array($input) || array_is_list($input)) {
        throw new InvalidArgumentException('Solicitud inválida.');
    }
    $pub = idCampo($input, 'publicacion');
    $request = textoCampo($input, 'solicitud');
    $action = textoCampo($input, 'accion');
    $elapsed = filter_var($input['escuchados_ms'] ?? 0, FILTER_VALIDATE_INT);
    if ($elapsed === false) {
        throw new InvalidArgumentException('Tiempo inválido.');
    }
    $result = match($action) {
        'iniciar' => iniciarEscuchaEstadistica($uid, $pub, $request),'confirmar' => confirmarEscuchaEstadistica($uid, $pub, $request, $elapsed),default => throw new InvalidArgumentException('Acción inválida.')
    };
    responderEscucha(['ok' => true] + $result);
} catch (OutOfBoundsException $e) {
    responderEscucha(['ok' => false], 404);
} catch (OverflowException $e) {
    responderEscucha(['ok' => false], 429);
} catch (UnexpectedValueException|DomainException $e) {
    responderEscucha(['ok' => false], 409);
} catch (InvalidArgumentException|JsonException $e) {
    responderEscucha(['ok' => false], 422);
} catch (Throwable $e) {
    error_log('Listening statistics: ' . get_class($e));
    responderEscucha(['ok' => false], 503);
}
