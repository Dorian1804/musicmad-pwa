<?php

/** Optional additive push controls. No endpoint, key or personal data in diagnostics. */
function controlesPushDisponibles(): bool
{
    static $ready;
    if ($ready !== null) {
        return $ready;
    }
    $statement = getDB()->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN (?,?)');
    $statement->execute(['musicmad_push_quiet','musicmad_job_health']);
    return $ready = (int) $statement->fetchColumn() === 2;
}

function validarSilencioPush(array $data): array
{
    if (!is_bool($data['activo'] ?? null)) {
        throw new InvalidArgumentException('Elige si quieres horario silencioso.');
    }
    foreach (['desde','hasta'] as $key) {
        if (!is_string($data[$key] ?? null) || !preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D', $data[$key])) {
            throw new InvalidArgumentException('Hora no válida.');
        }
    }
    $zone = $data['zona'] ?? null;
    if (!is_string($zone) || !in_array($zone, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true)) {
        throw new InvalidArgumentException('Zona horaria no válida.');
    }
    if ($data['activo'] && $data['desde'] === $data['hasta']) {
        throw new InvalidArgumentException('Elige horas diferentes para comenzar y terminar.');
    }
    return ['activo' => $data['activo'],'desde' => $data['desde'],'hasta' => $data['hasta'],'zona' => $zone];
}

function horarioSilenciosoPush(int $uid): array
{
    $default = ['activo' => false,'desde' => '22:00','hasta' => '08:00','zona' => 'America/Mexico_City'];
    if (!controlesPushDisponibles()) {
        return $default;
    }
    $statement = getDB()->prepare('SELECT activo,desde,hasta,zona FROM musicmad_push_quiet WHERE usuario_id=?');
    $statement->execute([$uid]);
    $row = $statement->fetch();
    if (!$row) {
        return $default;
    }
    return ['activo' => (bool)$row['activo'],'desde' => substr($row['desde'], 0, 5),'hasta' => substr($row['hasta'], 0, 5),'zona' => $row['zona']];
}

function dentroSilencioPush(array $schedule, ?DateTimeImmutable $now = null): bool
{
    $schedule = validarSilencioPush($schedule);
    if (!$schedule['activo']) {
        return false;
    }
    $time = ($now ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))->setTimezone(new DateTimeZone($schedule['zona']))->format('H:i');
    return $schedule['desde'] < $schedule['hasta']
        ? $time >= $schedule['desde'] && $time < $schedule['hasta']
        : $time >= $schedule['desde'] || $time < $schedule['hasta'];
}

function guardarSilencioPush(int $uid, array $schedule): void
{
    if (!controlesPushDisponibles()) {
        throw new LogicException('Actualiza las preferencias de avisos antes de guardarlas.');
    }
    $schedule = validarSilencioPush($schedule);
    getDB()->prepare('INSERT INTO musicmad_push_quiet(usuario_id,activo,desde,hasta,zona) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE activo=VALUES(activo),desde=VALUES(desde),hasta=VALUES(hasta),zona=VALUES(zona)')->execute([$uid,(int)$schedule['activo'],$schedule['desde'],$schedule['hasta'],$schedule['zona']]);
}

function destinoPushSeguro(string $destination): string
{
    return preg_match('/^(?:mensajes\.php(?:\?con=[1-9][0-9]{0,9})?|notificaciones\.php|avisos-dispositivo\.php|mensajes(?:\/[a-f0-9]{32})?|notificaciones|avisos-dispositivo)$/D', $destination)
        ? $destination : 'notificaciones.php';
}

function encolarPruebaPush(int $uid, string $device): bool
{
    if (!controlesPushDisponibles() || !pushConfigurado()) {
        throw new LogicException('Los avisos todavía no están configurados.');
    }
    if (dentroSilencioPush(horarioSilenciosoPush($uid))) {
        throw new LogicException('Estás en horario silencioso. Cambia el horario antes de probar.');
    }
    $statement = getDB()->prepare('SELECT s.id FROM push_subscriptions s JOIN musicmad_push_bindings b ON b.subscription_id=s.id JOIN sesiones_dispositivos d ON d.selector=b.device_selector AND d.usuario_id=s.usuario_id JOIN musicmad_session_bindings sb ON sb.selector=d.selector JOIN musicmad_cuenta_seguridad c ON c.usuario_id=s.usuario_id WHERE s.usuario_id=? AND b.device_selector=? AND d.revocada_en IS NULL AND sb.expira_en>UTC_TIMESTAMP() AND sb.version_acceso=c.version_acceso LIMIT 1');
    $statement->execute([$uid,$device]);
    $subscription = (int) $statement->fetchColumn();
    if ($subscription < 1) {
        throw new LogicException('Activa primero los avisos en este dispositivo.');
    }
    return encolarTrabajo('push', ['suscripcion' => $subscription,'categoria' => 'prueba','actor' => $uid,'referencia' => time()], hash('sha256', 'push-test:' . $uid . ':' . $subscription . ':' . intdiv(time(), 60)), $uid);
}

function registrarSaludTrabajos(bool $ok, array $counts = []): void
{
    if (!controlesPushDisponibles()) {
        return;
    }
    getDB()->prepare('INSERT INTO musicmad_job_health(id,ejecutado_en,correcto,completos,reintentos) VALUES(1,UTC_TIMESTAMP(),?,?,?) ON DUPLICATE KEY UPDATE ejecutado_en=VALUES(ejecutado_en),correcto=VALUES(correcto),completos=VALUES(completos),reintentos=VALUES(reintentos)')->execute([(int)$ok,(int)($counts['completos'] ?? 0),(int)($counts['reintentos'] ?? 0)]);
}

function cronPushReciente(): bool
{
    if (!controlesPushDisponibles()) {
        return false;
    }
    return (bool) getDB()->query('SELECT 1 FROM musicmad_job_health WHERE id=1 AND correcto=1 AND ejecutado_en>=UTC_TIMESTAMP()-INTERVAL 5 MINUTE')->fetchColumn();
}

function parVapidValido(string $public, string $private): bool
{
    if (strlen($public) !== 65 || strlen($private) !== 32) {
        return false;
    }
    $der = hex2bin('30310201010420') . $private . hex2bin('a00a06082a8648ce3d030107');
    $key = openssl_pkey_get_private("-----BEGIN EC PRIVATE KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END EC PRIVATE KEY-----\n");
    if (!$key) {
        return false;
    }
    $details = openssl_pkey_get_details($key);
    $ec = $details['ec'] ?? [];
    return isset($ec['x'],$ec['y']) && hash_equals($public, chr(4) . str_pad($ec['x'], 32, chr(0), STR_PAD_LEFT) . str_pad($ec['y'], 32, chr(0), STR_PAD_LEFT));
}
