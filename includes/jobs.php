<?php

require_once __DIR__ . '/totp.php';
function colaTrabajosDisponible(): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    try {
        getDB()->query('SELECT id FROM musicmad_jobs LIMIT 0');
        return $ready = true;
    } catch (PDOException $error) {
        if ($error->getCode() === '42S02') {
            return $ready = false;
        }
        throw $error;
    }
}
function encolarTrabajo(string $type, array $payload, string $dedupe, ?int $uid = null): bool
{
    if (!in_array($type, ['correo','push','audio'], true) || !preg_match('/^[a-f0-9]{64}$/D', $dedupe)) {
        throw new InvalidArgumentException('Trabajo no válido.');
    }
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    if (strlen($json) > 65536) {
        throw new LengthException('Trabajo demasiado grande.');
    }
    $cipher = cifrarSecretoMusicMad($json, 'jobs', $dedupe);
    $st = getDB()->prepare('INSERT IGNORE INTO musicmad_jobs(usuario_id,tipo,dedupe,payload,disponible_en,created_at) VALUES(?,?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())');
    $st->execute([$uid,$type,$dedupe,$cipher]);
    return $st->rowCount() === 1;
}
function reservarTrabajo(): ?array
{
    $db = getDB();
    $db->beginTransaction();
    try {
        $st = $db->prepare("SELECT * FROM musicmad_jobs WHERE estado IN ('pendiente','procesando') AND disponible_en<=UTC_TIMESTAMP() AND (reservado_hasta IS NULL OR reservado_hasta<UTC_TIMESTAMP()) ORDER BY disponible_en,id LIMIT 1 FOR UPDATE");
        $st->execute();
        $row = $st->fetch();
        if (!$row) {
            $db->commit();
            return null;
        }
        $lease = bin2hex(random_bytes(16));
        $db->prepare("UPDATE musicmad_jobs SET estado='procesando',lease_key=?,reservado_hasta=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 120 SECOND),intentos=LEAST(intentos+1,255) WHERE id=?")->execute([$lease,$row['id']]);
        $db->commit();
        $row['lease_key'] = $lease;
        $row['intentos'] = (int)$row['intentos'] + 1;
        return $row;
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $error;
    }
}
function terminarTrabajo(array $job, ?Throwable $error = null): void
{
    $db = getDB();
    if ($error === null) {
        $db->prepare("UPDATE musicmad_jobs SET estado='completo',terminado_en=UTC_TIMESTAMP(),payload='',lease_key=NULL,reservado_hasta=NULL,error_clase=NULL WHERE id=? AND lease_key=? AND estado='procesando'")->execute([$job['id'],$job['lease_key']]);
        return;
    }
    $failed = $job['intentos'] >= 5;
    $delay = min(3600, 30 * (2 ** min(7, $job['intentos'])));
    $db->prepare('UPDATE musicmad_jobs SET estado=?,lease_key=NULL,reservado_hasta=NULL,error_clase=?,disponible_en=DATE_ADD(UTC_TIMESTAMP(),INTERVAL ' . $delay . ' SECOND) WHERE id=? AND lease_key=? AND estado=\'procesando\'')->execute([$failed ? 'fallido' : 'pendiente',substr(get_class($error), 0, 100),$job['id'],$job['lease_key']]);
}
function ejecutarTrabajosAcotados(array $handlers, int $max = 5, int $seconds = 20): array
{
    if (PHP_SAPI !== 'cli') {
        throw new RuntimeException('La cola solo se ejecuta desde cron.');
    }
    $end = microtime(true) + max(1, min(25, $seconds));
    $completed = 0;
    $failed = 0;
    for ($i = 0;$i < max(1, min(10, $max)) && microtime(true) < $end;$i++) {
        $job = reservarTrabajo();
        if (!$job) {
            break;
        }
        try {
            if (!isset($handlers[$job['tipo']])) {
                throw new RuntimeException('Tipo de trabajo no habilitado.');
            }
            $payload = json_decode(descifrarSecretoMusicMad($job['payload'], 'jobs', $job['dedupe']), true, 512, JSON_THROW_ON_ERROR);
            $handlers[$job['tipo']]($payload);
            terminarTrabajo($job);
            $completed++;
        } catch (Throwable $error) {
            terminarTrabajo($job, $error);
            $failed++;
        }
    }
    return ['completos' => $completed,'reintentos' => $failed];
}
