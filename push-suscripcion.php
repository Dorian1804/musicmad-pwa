<?php

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/social.php';
require_once __DIR__ . '/includes/chat_live.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
function responderPush(int $code, array $body): never
{
    http_response_code($code);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
if (!in_array($_SERVER['REQUEST_METHOD'], ['GET','POST'], true)) {
    header('Allow: GET, POST');
    responderPush(405, ['ok' => false]);
}
if (!estaAutenticado()) {
    responderPush(401, ['ok' => false]);
}
if (!pushSchemaDisponible()) {
    responderPush(503, ['ok' => false,'mensaje' => 'Los avisos del dispositivo no están disponibles todavía.']);
}
$yo = (int)sesion('usuario_id');
$device = (string)($_SESSION['device_selector'] ?? '');
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    responderPush(200, ['ok' => true,'disponible' => pushConfigurado(),'clave_publica' => pushConfigurado() ? (string)getenv('MM_VAPID_PUBLIC_KEY') : '','preferencias' => preferenciasPush($yo),'silencio' => horarioSilenciosoPush($yo),'controles' => controlesPushDisponibles(),'cron_reciente' => cronPushReciente()]);
}
if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 8192) {
    responderPush(413, ['ok' => false]);
}
$raw = file_get_contents('php://input', false, null, 0, 8193);
if (!is_string($raw) || strlen($raw) > 8192) {
    responderPush(413, ['ok' => false]);
}
$data = json_decode($raw, true);
if (!is_array($data)) {
    responderPush(422, ['ok' => false]);
}
$csrf = $_SERVER['HTTP_X_MUSICMAD_CSRF'] ?? '';
if (!is_string($csrf) || $csrf === '' || !hash_equals(csrfToken(), $csrf)) {
    responderPush(403, ['ok' => false,'mensaje' => 'Actualiza tu sesión.']);
}
session_write_close();
try {
    limiteChat($yo, 'push', 15);
    $action = textoCampo($data, 'accion');
    if ($action === 'activar') {
        if (!pushConfigurado()) {
            responderPush(503, ['ok' => false,'mensaje' => 'Los avisos no están habilitados aún.']);
        }
        $subscription = $data['suscripcion'] ?? null;
        if (!is_array($subscription)) {
            throw new InvalidArgumentException('Suscripción no válida.');
        }
        $token = registrarPush($yo, $device, $subscription);
        responderPush(200, ['ok' => true,'token' => $token]);
    }
    if ($action === 'desactivar') {
        getDB()->prepare('DELETE s FROM push_subscriptions s JOIN musicmad_push_bindings b ON b.subscription_id=s.id WHERE s.usuario_id=? AND b.device_selector=?')->execute([$yo,$device]);
        responderPush(200, ['ok' => true]);
    }
    if ($action === 'prueba') {
        $queued = encolarPruebaPush($yo, $device);
        responderPush(200, ['ok' => true,'encolada' => $queued,'mensaje' => $queued ? 'Prueba en cola. Puede tardar hasta el siguiente minuto.' : 'Ya pediste una prueba este minuto.']);
    }
    if ($action === 'preferencias') {
        $values = [];
        foreach (PUSH_CATEGORIES as $category) {
            if (!is_bool($data[$category] ?? null)) {
                throw new InvalidArgumentException('Preferencia no válida.');
            }
            $values[] = (int)$data[$category];
        }
        if (isset($data['silencio'])) {
            if (!is_array($data['silencio'])) {
                throw new InvalidArgumentException('Horario no válido.');
            }
            validarSilencioPush($data['silencio']);
        }
        $db = getDB();
        $db->beginTransaction();
        try {
            getDB()->prepare('INSERT INTO musicmad_push_preferences(usuario_id,mensajes,aplausos,comentarios,seguidores,bandas,eventos) VALUES(?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE mensajes=VALUES(mensajes),aplausos=VALUES(aplausos),comentarios=VALUES(comentarios),seguidores=VALUES(seguidores),bandas=VALUES(bandas),eventos=VALUES(eventos)')->execute([$yo,...$values]);
            if (isset($data['silencio'])) {
                guardarSilencioPush($yo, $data['silencio']);
            }
            $db->commit();
        } catch (Throwable $error) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $error;
        }
        responderPush(200, ['ok' => true]);
    }
    throw new InvalidArgumentException('Acción no válida.');
} catch (OverflowException $error) {
    responderPush(429, ['ok' => false,'mensaje' => $error->getMessage()]);
} catch (LogicException $error) {
    responderPush(422, ['ok' => false,'mensaje' => $error->getMessage()]);
} catch (Throwable $error) {
    error_log('Push subscription: ' . get_class($error));
    responderPush(503, ['ok' => false,'mensaje' => 'No pudimos guardar tus avisos. Reintenta.']);
}
