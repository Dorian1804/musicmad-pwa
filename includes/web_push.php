<?php

require_once __DIR__ . '/jobs.php';
require_once __DIR__ . '/push_controls.php';
require_once __DIR__ . '/i18n.php';
if (is_file(dirname(__DIR__) . '/vendor/autoload.php')) {
    require_once dirname(__DIR__) . '/vendor/autoload.php';
}
const PUSH_CATEGORIES = ['mensajes','aplausos','comentarios','seguidores','bandas','eventos'];
function pushSchemaDisponible(): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    $tables = ['musicmad_push_preferences','musicmad_push_bindings'];
    $st = getDB()->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN (?,?)');
    $st->execute($tables);
    return $ready = (int)$st->fetchColumn() === 2 && social3Disponible() && registroSesionesDisponible() && colaTrabajosDisponible();
}
function clavePushDecodificada(string $value, int $size): string
{
    if (!preg_match('/^[A-Za-z0-9_-]+={0,2}$/D', $value)) {
        throw new InvalidArgumentException('Clave push no válida.');
    }
    $raw = base64_decode(strtr($value, '-_', '+/'), true);
    if (!is_string($raw) || strlen($raw) !== $size) {
        throw new InvalidArgumentException('Clave push no válida.');
    }
    return $raw;
}
function validarPuntoPush(string $point): void
{
    $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $point;
    if (strlen($point) !== 65 || ord($point[0]) !== 4 || !openssl_pkey_get_public("-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n")) {
        throw new InvalidArgumentException('Clave push no válida.');
    }
}
function pushConfigurado(): bool
{
    if (!pushSchemaDisponible() || !extension_loaded('curl') || !extension_loaded('openssl') || !class_exists(\Minishlink\WebPush\WebPush::class)) {
        return false;
    }
    try {
        $public = clavePushDecodificada((string)getenv('MM_VAPID_PUBLIC_KEY'), 65);
        $private = clavePushDecodificada((string)getenv('MM_VAPID_PRIVATE_KEY'), 32);
        validarPuntoPush($public);
        if (!parVapidValido($public, $private)) {
            return false;
        }
    } catch (InvalidArgumentException $e) {
        return false;
    }
    $subject = (string)getenv('MM_VAPID_SUBJECT');
    return (str_starts_with($subject, 'mailto:') && filter_var(substr($subject, 7), FILTER_VALIDATE_EMAIL)) || (str_starts_with($subject, 'https://') && filter_var($subject, FILTER_VALIDATE_URL));
}
function validarEndpointPush(string $endpoint): void
{
    if (strlen($endpoint) > 2048 || preg_match('/[\x00-\x20\x7f\\\\]/', $endpoint)) {
        throw new InvalidArgumentException('Destino push no válido.');
    }
    $url = parse_url($endpoint);
    $host = strtolower($url['host'] ?? '');
    $allowed = in_array($host, ['fcm.googleapis.com','updates.push.services.mozilla.com'], true) || preg_match('/^[a-z0-9-]+\.push\.apple\.com$/D', $host) || preg_match('/^[a-z0-9-]+\.notify\.windows\.com$/D', $host);
    if (!$allowed || ($url['scheme'] ?? '') !== 'https' || isset($url['user']) || isset($url['pass']) || isset($url['fragment']) || (isset($url['port']) && $url['port'] !== 443) || empty($url['path'])) {
        throw new InvalidArgumentException('Destino push no admitido.');
    }
}
function preferenciasPush(int $yo): array
{
    $st = getDB()->prepare('SELECT mensajes,aplausos,comentarios,seguidores,bandas,eventos FROM musicmad_push_preferences WHERE usuario_id=?');
    $st->execute([$yo]);
    return array_map('intval', $st->fetch() ?: array_fill_keys(PUSH_CATEGORIES, 1));
}
function registrarPush(int $yo, string $device, array $subscription): string
{
    $endpoint = textoCampo($subscription, 'endpoint');
    validarEndpointPush($endpoint);
    $keys = $subscription['keys'] ?? [];
    if (!is_array($keys)) {
        throw new InvalidArgumentException('Suscripción no válida.');
    }
    $public = textoCampo($keys, 'p256dh');
    $auth = textoCampo($keys, 'auth');
    $point = clavePushDecodificada($public, 65);
    clavePushDecodificada($auth, 16);
    validarPuntoPush($point);
    $db = getDB();
    $owns = !$db->inTransaction();
    if ($owns) {
        $db->beginTransaction();
    }
    try {
        $lock = $db->prepare('SELECT id FROM usuarios WHERE id=? AND activo=1 FOR UPDATE');
        $lock->execute([$yo]);
        if (!$lock->fetchColumn()) {
            throw new OutOfBoundsException('Acceso no disponible.');
        }
        $st = $db->prepare('SELECT selector FROM sesiones_dispositivos WHERE usuario_id=? AND selector=? AND revocada_en IS NULL FOR UPDATE');
        $st->execute([$yo,$device]);
        if (!$st->fetchColumn()) {
            throw new OutOfBoundsException('Acceso no disponible.');
        }
        $hash = hash('sha256', $endpoint);
        $st = $db->prepare('SELECT * FROM push_subscriptions WHERE endpoint_hash=? FOR UPDATE');
        $st->execute([$hash]);
        $existing = $st->fetch();
        if ($existing && (int)$existing['usuario_id'] !== $yo && (!hash_equals($existing['clave_publica'], $public) || !hash_equals($existing['clave_auth'], $auth))) {
            throw new LogicException('Suscripción no válida.');
        }
        $st = $db->prepare('SELECT COUNT(*) FROM push_subscriptions WHERE usuario_id=? AND endpoint_hash<>?');
        $st->execute([$yo,$hash]);
        if ((int)$st->fetchColumn() >= 5) {
            throw new OverflowException('Ya tienes cinco dispositivos de avisos. Desactiva uno antes de agregar otro.');
        }
        $db->prepare('INSERT INTO push_subscriptions(usuario_id,endpoint_hash,endpoint,clave_publica,clave_auth) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE usuario_id=VALUES(usuario_id),endpoint=VALUES(endpoint),clave_publica=VALUES(clave_publica),clave_auth=VALUES(clave_auth),updated_at=CURRENT_TIMESTAMP')->execute([$yo,$hash,$endpoint,$public,$auth]);
        $st = $db->prepare('SELECT id FROM push_subscriptions WHERE endpoint_hash=?');
        $st->execute([$hash]);
        $id = (int)$st->fetchColumn();
        $token = bin2hex(random_bytes(32));
        $db->prepare('INSERT INTO musicmad_push_bindings(subscription_id,device_selector,token) VALUES(?,?,?) ON DUPLICATE KEY UPDATE device_selector=VALUES(device_selector),token=VALUES(token)')->execute([$id,$device,$token]);
        if ($owns) {
            $db->commit();
        }
        return $token;
    } catch (Throwable $error) {
        if ($owns && $db->inTransaction()) {
            $db->rollBack();
        }
        throw $error;
    }
}
function encolarAvisoPush(int $yo, int $actor, string $category, int $reference): void
{
    if (dentroSilencioPush(horarioSilenciosoPush($yo))) {
        return;
    }
    if (!in_array($category, PUSH_CATEGORIES, true) || ($yo === $actor && !in_array($category, ['bandas','eventos'], true)) || !pushConfigurado() || !preferenciasPush($yo)[$category]) {
        return;
    }
    [$block,$params] = filtroBloqueoInteraccion($yo, 'u.id', 'push_actor');
    $st = getDB()->prepare('SELECT id FROM usuarios u WHERE u.id=:actor AND u.activo=1 AND ' . $block);
    $st->execute([':actor' => $actor] + $params);
    if (!$st->fetchColumn()) {
        return;
    }
    $st = getDB()->prepare('SELECT s.id FROM push_subscriptions s JOIN musicmad_push_bindings b ON b.subscription_id=s.id JOIN sesiones_dispositivos d ON d.selector=b.device_selector JOIN musicmad_session_bindings sb ON sb.selector=d.selector JOIN musicmad_cuenta_seguridad c ON c.usuario_id=s.usuario_id WHERE s.usuario_id=? AND d.revocada_en IS NULL AND sb.expira_en>UTC_TIMESTAMP() AND sb.version_acceso=c.version_acceso LIMIT 5');
    $st->execute([$yo]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $subscription) {
        encolarTrabajo('push', ['suscripcion' => (int)$subscription,'categoria' => $category,'actor' => $actor,'referencia' => $reference], hash('sha256', 'push:' . $yo . ':' . $subscription . ':' . $category . ':' . $actor . ':' . $reference), $yo);
    }
}
/** Generic text only; never include a private DM or profile data on a lock screen. */
function prepararAvisoPush(array $payload): ?array
{
    $id = idCampo($payload, 'suscripcion');
    $category = textoCampo($payload, 'categoria');
    $actor = idCampo($payload, 'actor');
    $ref = idCampo($payload, 'referencia');
    if (!in_array($category, [...PUSH_CATEGORIES,'prueba'], true)) {
        throw new InvalidArgumentException('Categoría no válida.');
    }
    $st = getDB()->prepare('SELECT s.*,b.token FROM push_subscriptions s JOIN musicmad_push_bindings b ON b.subscription_id=s.id JOIN sesiones_dispositivos d ON d.selector=b.device_selector AND d.usuario_id=s.usuario_id JOIN musicmad_session_bindings sb ON sb.selector=d.selector JOIN musicmad_cuenta_seguridad c ON c.usuario_id=s.usuario_id JOIN usuarios u ON u.id=s.usuario_id AND u.activo=1 WHERE s.id=? AND d.revocada_en IS NULL AND sb.expira_en>UTC_TIMESTAMP() AND sb.version_acceso=c.version_acceso');
    $st->execute([$id]);
    $row = $st->fetch();
    if (!$row || ($category !== 'prueba' && !preferenciasPush((int)$row['usuario_id'])[$category]) || dentroSilencioPush(horarioSilenciosoPush((int)$row['usuario_id']))) {
        return null;
    }
    if ($category === 'prueba') {
        if ($actor !== (int)$row['usuario_id'] || $ref < time() - 300 || $ref > time() + 30) {
            return null;
        }
        validarEndpointPush($row['endpoint']);
        $row['payload'] = ['idioma' => idiomaCuentaMusicMad((int)$row['usuario_id']),'token' => $row['token'],'titulo' => 'MusicMad','texto' => mmTexto('Tu prueba de avisos está lista.', [], idiomaCuentaMusicMad((int)$row['usuario_id'])),'destino' => 'avisos-dispositivo.php','tag' => 'musicmad-prueba'];
        return $row;
    }
    [$block,$params] = filtroBloqueoInteraccion((int)$row['usuario_id'], 'u.id', 'push_deliver');
    $st = getDB()->prepare('SELECT 1 FROM usuarios u WHERE u.id=:actor AND u.activo=1 AND ' . $block);
    $st->execute([':actor' => $actor] + $params);
    if (!$st->fetchColumn()) {
        return null;
    }
    if ($category === 'mensajes') {
        $st = getDB()->prepare('SELECT 1 FROM mensajes WHERE id=? AND para_id=? AND de_id=? AND leido=0');
        $st->execute([$ref,$row['usuario_id'],$actor]);
    } elseif ($category === 'eventos') {
        $st = getDB()->prepare('SELECT 1 FROM notificaciones_ext WHERE id=? AND usuario_id=? AND actor_id=? AND leida_en IS NULL');
        $st->execute([$ref,$row['usuario_id'],$actor]);
    } else {
        $st = getDB()->prepare('SELECT 1 FROM notificaciones WHERE id=? AND usuario_id=? AND actor_id=? AND leida=0');
        $st->execute([$ref,$row['usuario_id'],$actor]);
    }
    if (!$st->fetchColumn()) {
        return null;
    }
    if ($category === 'bandas' && bandasWorkspaceDisponible()) {
        $st = getDB()->prepare('SELECT n.*,bn.subtipo,bn.referencia_id FROM notificaciones n LEFT JOIN musicmad_band_notifications bn ON bn.notificacion_id=n.id WHERE n.id=?');
        $st->execute([$ref]);
        $notice = $st->fetch();
        if (!$notice || !avisosBandaDisponibles((int)$row['usuario_id'], $notice)) {
            return null;
        }
    }
    if ($category === 'eventos') {
        $st = getDB()->prepare('SELECT * FROM notificaciones_ext WHERE id=?');
        $st->execute([$ref]);
        $notice = $st->fetch();
        if (!eventosWorkspaceDisponible() || !$notice || !avisoEventoVigente((int)$row['usuario_id'], $notice)) {
            return null;
        }
    }
    validarEndpointPush($row['endpoint']);
    $row['payload'] = ['idioma' => idiomaCuentaMusicMad((int)$row['usuario_id']),'token' => $row['token'],'titulo' => 'MusicMad','texto' => mmTexto($category === 'mensajes' ? 'Tienes un nuevo mensaje.' : 'Hay novedades en tu comunidad.', [], idiomaCuentaMusicMad((int)$row['usuario_id'])),'destino' => destinoPushSeguro($category === 'mensajes' ? 'mensajes.php?con=' . $actor : 'notificaciones.php'),'tag' => 'musicmad-' . $category];
    return $row;
}
function enviarTrabajoPush(array $payload, ?\Psr\Http\Client\ClientInterface $qaClient = null): void
{
    if (!pushConfigurado()) {
        throw new RuntimeException('Push no configurado.');
    }
    $row = prepararAvisoPush($payload);
    if (!$row) {
        return;
    }
    if ($qaClient && (!defined('MUSICMAD_TEST_RUN') || !MUSICMAD_TEST_RUN || PHP_SAPI !== 'cli')) {
        throw new LogicException('Transporte QA no disponible.');
    }
    $client = $qaClient ?? new \GuzzleHttp\Client(['timeout' => 8,'connect_timeout' => 3,'allow_redirects' => false,'verify' => true]);
    $push = new \Minishlink\WebPush\WebPush(['VAPID' => ['subject' => getenv('MM_VAPID_SUBJECT'),'publicKey' => getenv('MM_VAPID_PUBLIC_KEY'),'privateKey' => getenv('MM_VAPID_PRIVATE_KEY')]], ['TTL' => 300,'urgency' => 'normal'], $client);
    $subscription = \Minishlink\WebPush\Subscription::create(['endpoint' => $row['endpoint'],'contentEncoding' => 'aes128gcm','keys' => ['p256dh' => $row['clave_publica'],'auth' => $row['clave_auth']]]);
    $report = $push->sendOneNotification($subscription, json_encode($row['payload'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    if ($report->isSubscriptionExpired()) {
        getDB()->prepare('DELETE FROM push_subscriptions WHERE id=?')->execute([$row['id']]);
        return;
    }
    $status = $report->getResponse()?->getStatusCode() ?? 0;
    if (!$report->isSuccess() || $status < 200 || $status >= 300) {
        throw new RuntimeException('Servicio push temporalmente no disponible.');
    }
}
