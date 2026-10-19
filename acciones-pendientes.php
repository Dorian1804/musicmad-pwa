<?php

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/social.php';
require_once __DIR__ . '/includes/action_receipts.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
function respuestaSincronizacion(int $code, array $body): never
{
    http_response_code($code);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
$method = $_SERVER['REQUEST_METHOD'];
if (!in_array($method, ['GET','POST'], true)) {
    header('Allow: GET, POST');
    respuestaSincronizacion(405, ['ok' => false]);
}
if (!estaAutenticado()) {
    respuestaSincronizacion(401, ['ok' => false,'mensaje' => 'Inicia sesión para enviar las acciones pendientes.']);
}
if (!sincronizacionDisponible()) {
    respuestaSincronizacion(503, ['ok' => false,'mensaje' => 'La sincronización no está disponible todavía.']);
}
if (empty($_SESSION['offline_scope'])) {
    $_SESSION['offline_scope'] = bin2hex(random_bytes(32));
}
if ($method === 'GET') {
    respuestaSincronizacion(200, ['ok' => true,'scope' => $_SESSION['offline_scope'],'usuario' => (int)sesion('usuario_id'),'csrf' => csrfToken()]);
}
if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 65536) {
    respuestaSincronizacion(413, ['ok' => false]);
}
$raw = file_get_contents('php://input', false, null, 0, 65537);
if (!is_string($raw) || strlen($raw) > 65536) {
    respuestaSincronizacion(413, ['ok' => false]);
}
$data = json_decode($raw, true);
if (!is_array($data) || !is_array($data['acciones'] ?? null) || !array_is_list($data['acciones']) || count($data['acciones']) < 1 || count($data['acciones']) > 20) {
    respuestaSincronizacion(422, ['ok' => false,'mensaje' => 'Lote de acciones no válido.']);
}
$csrf = $_SERVER['HTTP_X_MUSICMAD_CSRF'] ?? '';
if (!is_string($csrf) || $csrf === '' || !hash_equals(csrfToken(), $csrf)) {
    respuestaSincronizacion(403, ['ok' => false,'mensaje' => 'Actualiza tu sesión antes de enviar.']);
}
$scope = $data['scope'] ?? '';
if (idCampo($data, 'owner') !== (int)sesion('usuario_id')) {
    respuestaSincronizacion(409, ['ok' => false,'mensaje' => 'La cuenta cambió.']);
}
if (!is_string($scope) || !hash_equals($_SESSION['offline_scope'], $scope)) {
    respuestaSincronizacion(409, ['ok' => false,'mensaje' => 'La cuenta cambió. Revisa tus acciones pendientes.']);
}
$results = [];
foreach ($data['acciones'] as $input) {
    try {
        if (!is_array($input)) {
            throw new InvalidArgumentException('Acción no válida.');
        }
        $results[] = ejecutarAccionPendiente((int)sesion('usuario_id'), $input);
    } catch (OverflowException $error) {
        respuestaSincronizacion(429, ['ok' => false,'resultados' => $results,'mensaje' => $error->getMessage()]);
    } catch (DomainException $error) {
        respuestaSincronizacion(401, ['ok' => false,'resultados' => $results,'mensaje' => $error->getMessage()]);
    } catch (LogicException $error) {
        $results[] = ['ok' => false,'solicitud' => is_array($input) ? textoCampo($input, 'solicitud') : '','mensaje' => $error->getMessage(),'definitivo' => true];
    } catch (Throwable $error) {
        error_log('Action synchronization failed: ' . get_class($error));
        respuestaSincronizacion(503, ['ok' => false,'resultados' => $results,'mensaje' => 'No pudimos terminar la sincronización. Puedes reintentar.']);
    }
}
respuestaSincronizacion(200, ['ok' => true,'resultados' => $results]);
