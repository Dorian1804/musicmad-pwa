<?php

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/social.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store');
function respuestaOnda(int $status, array $data): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    exit;
}
if (!estaAutenticado()) {
    respuestaOnda(401, ['ok' => false,'mensaje' => 'Inicia sesión.']);
}
if (!in_array($_SERVER['REQUEST_METHOD'], ['GET','POST'], true)) {
    header('Allow: GET, POST');
    respuestaOnda(405, ['ok' => false]);
}
$uid = (int)sesion('usuario_id');
try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        session_write_close();
        limiteChat($uid, 'onda_leer', 120);
        respuestaOnda(200, ['ok' => true] + datosFormaOnda($uid, idCampo($_GET, 'id')));
    }
    $csrf = $_SERVER['HTTP_X_MUSICMAD_CSRF'] ?? '';
    $expected = $_SESSION['csrf'] ?? '';
    if (!is_string($csrf) || !is_string($expected) || $expected === '' || !hash_equals($expected, $csrf)) {
        respuestaOnda(403, ['ok' => false,'mensaje' => 'Recarga la página.']);
    }
    session_write_close();
    $raw = file_get_contents('php://input', false, null, 0, 12001);
    if (strlen($raw) > 12000) {
        throw new InvalidArgumentException('Solicitud grande.');
    }
    $data = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
    if (!is_array($data)) {
        throw new InvalidArgumentException('Datos inválidos.');
    }
    $duration = $data['duracion'] ?? null;
    if (!is_int($duration) && !is_float($duration)) {
        throw new InvalidArgumentException('Duración inválida.');
    }
    limiteChat($uid, 'onda_guardar', 5);
    guardarFormaOnda($uid, idCampo($data, 'id'), validarPicosOnda($data['picos'] ?? null), (float)$duration, textoCampo($data, 'sha256'));
    respuestaOnda(200, ['ok' => true]);
} catch (OutOfBoundsException $e) {
    respuestaOnda(404, ['ok' => false,'mensaje' => 'La pista no está disponible.']);
} catch (InvalidArgumentException|JsonException $e) {
    respuestaOnda(422, ['ok' => false,'mensaje' => 'No se pudo validar la forma de onda.']);
} catch (OverflowException $e) {
    header('Retry-After: 60');
    respuestaOnda(429, ['ok' => false,'mensaje' => 'Espera un minuto y reintenta.']);
} catch (Throwable $e) {
    error_log('Audio waveform: ' . get_class($e));
    respuestaOnda(503, ['ok' => false,'mensaje' => 'No se pudo cargar la forma de onda.']);
}
