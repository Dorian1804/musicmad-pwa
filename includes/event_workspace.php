<?php
function eventosWorkspaceDisponible(): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    $st = getDB()->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('musicmad_event_details','musicmad_event_preferences','musicmad_event_reminders')");
    $st->execute();
    $n = (int)$st->fetchColumn();
    if ($n > 0 && $n < 3) {
        throw new RuntimeException('Actualización de eventos incompleta.');
    }
    return $ready = $n === 3 && social3Disponible();
}
function eventoDisponiblePara(int $yo, int $id, bool $cancelled = false): ?array
{
    [$policy,$params] = filtroAccesoContenido($yo, 'ev.creador_id', 'event_access');
    $st = getDB()->prepare('SELECT ev.*,u.nombre,u.apellido FROM eventos_musicales ev JOIN usuarios u ON u.id=ev.creador_id AND u.activo=1 WHERE ev.id=:id AND ' . ($cancelled ? '1=1' : "ev.estado='publicado'") . ' AND ' . $policy);
    $st->execute([':id' => $id] + $params);
    $row = $st->fetch();
    if (!$row) {
        return null;
    }
    $st = getDB()->prepare('SELECT inicio_utc,fin_utc,zona,revision,cartel FROM musicmad_event_details WHERE evento_id=?');
    $st->execute([$id]);
    $details = $st->fetch();
    if (!$details) {
        $date = new DateTimeImmutable($row['inicio'], new DateTimeZone('America/Mexico_City'));
        $details = ['inicio_utc' => $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),'fin_utc' => $date->modify('+2 hours')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),'zona' => 'America/Mexico_City','revision' => 1,'cartel' => null];
    }
    return $row + $details;
}
function transaccionEvento(int $yo, int $id, callable $operation): mixed
{
    $db = getDB();
    $owns = !$db->inTransaction();
    if ($owns) {
        $db->beginTransaction();
    }
    try {
        $st = $db->prepare('SELECT id FROM usuarios WHERE id=? AND activo=1 FOR UPDATE');
        $st->execute([$yo]);
        if (!$st->fetchColumn()) {
            throw new OutOfBoundsException('Cuenta no disponible.');
        }
        if ($id) {
            $st = $db->prepare('SELECT id FROM eventos_musicales WHERE id=? FOR UPDATE');
            $st->execute([$id]);
            if (!$st->fetchColumn()) {
                throw new OutOfBoundsException('Evento no disponible.');
            }
        }
        $result = $operation($db);
        if ($owns) {
            $db->commit();
        }
        return $result;
    } catch (Throwable $e) {
        if ($owns && $db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}
function registrarAvisoEvento(int $yo, int $actor, int $event, string $type): int
{
    if (!in_array($type, ['evento_recordatorio','evento_cambio','evento_cancelado'], true)) {
        throw new InvalidArgumentException('Aviso no válido.');
    }
    $db = getDB();
    $db->prepare('INSERT INTO notificaciones_ext(usuario_id,actor_id,tipo,referencia_id) VALUES(?,?,?,?)')->execute([$yo,$actor,$type,$event]);
    $id = (int)$db->lastInsertId();
    try {
        encolarAvisoPush($yo, $actor, 'eventos', $id);
    } catch (Throwable $e) {
        error_log('Event notification queue: ' . get_class($e));
    }
    return $id;
}
function guardarEventoWorkspace(int $yo, array $data, int $id = 0): int
{
    $title = trim(textoCampo($data, 'titulo'));
    $desc = trim(textoCampo($data, 'descripcion'));
    $place = trim(textoCampo($data, 'sede'));
    $city = trim(textoCampo($data, 'ciudad'));
    $zone = textoCampo($data, 'zona') ?: 'America/Mexico_City';
    $start = fechaEnsayo(textoCampo($data, 'inicio'), $zone);
    $duration = array_key_exists('duracion', $data) ? idCampo($data, 'duracion') : 120;
    if (mb_strlen($title) < 2 || mb_strlen($title) > 120 || mb_strlen($desc) > 1000 || mb_strlen($city) > 80 || $city === '' || mb_strlen($place) > 160 || $duration < 30 || $duration > 720 || $start->getTimestamp() <= time() || $start->getTimestamp() > time() + 86400 * 730) {
        throw new InvalidArgumentException('Revisa nombre, ciudad, fecha y duración del evento.');
    }
    $utc = $start->setTimezone(new DateTimeZone('UTC'));
    $end = $utc->modify('+' . $duration . ' minutes');
    return transaccionEvento($yo, $id, function (PDO $db) use ($yo, $id, $title, $desc, $place, $city, $zone, $start, $utc, $end) {
        limiteChat($yo, 'evento_guardar', 10);
        if ($id) {
            $event = eventoDisponiblePara($yo, $id);
            if (!$event || (int)$event['creador_id'] !== $yo) {
                throw new OutOfBoundsException('No puedes editar este evento.');
            }
            $db->prepare('UPDATE eventos_musicales SET titulo=?,descripcion=?,sede=?,ciudad=?,inicio=? WHERE id=?')->execute([$title,$desc,$place,$city,$start->setTimezone(new DateTimeZone('America/Mexico_City'))->format('Y-m-d H:i:s'),$id]);
        } else {
            $db->prepare('INSERT INTO eventos_musicales(creador_id,titulo,descripcion,sede,ciudad,inicio) VALUES(?,?,?,?,?,?)')->execute([$yo,$title,$desc,$place,$city,$start->setTimezone(new DateTimeZone('America/Mexico_City'))->format('Y-m-d H:i:s')]);
            $id = (int)$db->lastInsertId();
        }
        $db->prepare('INSERT INTO musicmad_event_details(evento_id,inicio_utc,fin_utc,zona) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE inicio_utc=VALUES(inicio_utc),fin_utc=VALUES(fin_utc),zona=VALUES(zona),revision=revision+1')->execute([$id,$utc->format('Y-m-d H:i:s'),$end->format('Y-m-d H:i:s'),$zone]);
        $st = $db->prepare('SELECT usuario_id FROM evento_asistentes WHERE evento_id=? AND usuario_id<>? LIMIT 500');
        $st->execute([$id,$yo]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $uid) {
            if (puedeVerPerfilSocial3((int)$uid, $yo)) {
                registrarAvisoEvento((int)$uid, $yo, $id, 'evento_cambio');
            }
        }
        return $id;
    });
}
function responderEventoWorkspace(int $yo, int $id, string $status, bool $reminder): void
{
    if (!in_array($status, ['asistire','interesado','quitar'], true)) {
        throw new InvalidArgumentException('Respuesta no válida.');
    }
    transaccionEvento($yo, $id, function (PDO $db) use ($yo, $id, $status, $reminder) {
        $event = eventoDisponiblePara($yo, $id);
        if (!$event || strtotime($event['inicio_utc'] . ' UTC') <= time()) {
            throw new OutOfBoundsException('Evento no disponible.');
        }
        if ($status === 'quitar') {
            $db->prepare('DELETE FROM evento_asistentes WHERE evento_id=? AND usuario_id=?')->execute([$id,$yo]);
            return;
        }
        limiteChat($yo, 'evento_rsvp', 30);
        $db->prepare('INSERT INTO evento_asistentes(evento_id,usuario_id,estado) VALUES(?,?,?) ON DUPLICATE KEY UPDATE estado=VALUES(estado)')->execute([$id,$yo,$status]);
        $db->prepare('INSERT INTO musicmad_event_preferences(evento_id,usuario_id,aviso) VALUES(?,?,?) ON DUPLICATE KEY UPDATE aviso=VALUES(aviso)')->execute([$id,$yo,(int)$reminder]);
    });
}
function cancelarEventoWorkspace(int $yo, int $id): void
{
    transaccionEvento($yo, $id, function (PDO $db) use ($yo, $id) {
        $event = eventoDisponiblePara($yo, $id);
        if (!$event || (int)$event['creador_id'] !== $yo) {
            throw new OutOfBoundsException('No puedes cancelar este evento.');
        }
        $db->prepare("UPDATE eventos_musicales SET estado='cancelado' WHERE id=?")->execute([$id]);
        $st = $db->prepare('SELECT usuario_id FROM evento_asistentes WHERE evento_id=? AND usuario_id<>? LIMIT 500');
        $st->execute([$id,$yo]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $uid) {
            if (puedeVerPerfilSocial3((int)$uid, $yo)) {
                registrarAvisoEvento((int)$uid, $yo, $id, 'evento_cancelado');
            }
        }
    });
}
function avisoEventoVigente(int $yo, array $notice): bool
{
    $id = (int)$notice['referencia_id'];
    $event = eventoDisponiblePara($yo, $id, $notice['tipo'] === 'evento_cancelado');
    if (!$event || (int)$notice['actor_id'] !== (int)$event['creador_id']) {
        return false;
    }
    if ($notice['tipo'] === 'evento_cancelado' && $event['estado'] !== 'cancelado') {
        return false;
    }
    $st = getDB()->prepare('SELECT 1 FROM evento_asistentes WHERE evento_id=? AND usuario_id=?');
    $st->execute([$id,$yo]);
    if (!$st->fetchColumn()) {
        return false;
    }
    if ($notice['tipo'] === 'evento_recordatorio') {
        if (strtotime($event['inicio_utc'] . ' UTC') <= time()) {
            return false;
        }
        $st = getDB()->prepare('SELECT 1 FROM musicmad_event_reminders r JOIN musicmad_event_preferences p ON p.evento_id=r.evento_id AND p.usuario_id=r.usuario_id WHERE r.notificacion_id=? AND r.usuario_id=? AND r.revision=? AND p.aviso=1');
        $st->execute([$notice['id'],$yo,$event['revision']]);
        return (bool)$st->fetchColumn();
    }
    return true;
}
function generarRecordatoriosEventos(int $max = 50): int
{
    if (PHP_SAPI !== 'cli') {
        throw new RuntimeException('Solo cron.');
    }
    if (!eventosWorkspaceDisponible()) {
        return 0;
    }
    $deadline = microtime(true) + 5;
    $db = getDB();
    $st = $db->prepare("SELECT e.id,e.creador_id,d.revision,p.usuario_id,IF(d.inicio_utc>UTC_TIMESTAMP()+INTERVAL 23 HOUR,'24h','1h') AS ventana FROM eventos_musicales e JOIN musicmad_event_details d ON d.evento_id=e.id JOIN musicmad_event_preferences p ON p.evento_id=e.id AND p.aviso=1 JOIN evento_asistentes a ON a.evento_id=e.id AND a.usuario_id=p.usuario_id JOIN usuarios u ON u.id=p.usuario_id AND u.activo=1 WHERE e.estado='publicado' AND d.inicio_utc>UTC_TIMESTAMP() AND (d.inicio_utc<=UTC_TIMESTAMP()+INTERVAL 1 HOUR OR d.inicio_utc BETWEEN UTC_TIMESTAMP()+INTERVAL 23 HOUR AND UTC_TIMESTAMP()+INTERVAL 24 HOUR) AND NOT EXISTS(SELECT 1 FROM musicmad_event_reminders r WHERE r.evento_id=e.id AND r.usuario_id=p.usuario_id AND r.revision=d.revision AND r.ventana=IF(d.inicio_utc>UTC_TIMESTAMP()+INTERVAL 23 HOUR,'24h','1h')) ORDER BY d.inicio_utc,e.id,p.usuario_id LIMIT " . max(1, min(100, $max)));
    $st->execute();
    $count = 0;
    foreach ($st->fetchAll() as $row) {
        if (microtime(true) > $deadline) {
            break;
        }
        try {
            $made = transaccionEvento((int)$row['usuario_id'], (int)$row['id'], function (PDO $db) use ($row) {
                $event = eventoDisponiblePara((int)$row['usuario_id'], (int)$row['id']);
                if (!$event || (int)$event['revision'] !== (int)$row['revision'] || strtotime($event['inicio_utc'] . ' UTC') <= time()) {
                    return false;
                }
                $delta = strtotime($event['inicio_utc'] . ' UTC') - time();
                if (($row['ventana'] === '1h' && $delta > 3600) || ($row['ventana'] === '24h' && ($delta < 82800 || $delta > 86400))) {
                    return false;
                }
                $st = $db->prepare('SELECT 1 FROM musicmad_event_preferences p JOIN evento_asistentes a ON a.evento_id=p.evento_id AND a.usuario_id=p.usuario_id WHERE p.evento_id=? AND p.usuario_id=? AND p.aviso=1');
                $st->execute([$row['id'],$row['usuario_id']]);
                if (!$st->fetchColumn()) {
                    return false;
                }
                $st = $db->prepare('INSERT IGNORE INTO musicmad_event_reminders(evento_id,usuario_id,revision,ventana) VALUES(?,?,?,?)');
                $st->execute([$row['id'],$row['usuario_id'],$row['revision'],$row['ventana']]);
                if (!$st->rowCount()) {
                    return false;
                }
                $notice = registrarAvisoEvento((int)$row['usuario_id'], (int)$row['creador_id'], (int)$row['id'], 'evento_recordatorio');
                $db->prepare('UPDATE musicmad_event_reminders SET notificacion_id=? WHERE evento_id=? AND usuario_id=? AND revision=? AND ventana=?')->execute([$notice,$row['id'],$row['usuario_id'],$row['revision'],$row['ventana']]);
                return true;
            });
            if ($made) {
                $count++;
            }
        } catch (OutOfBoundsException $e) {/* Revoked while selecting. */
        }
    }
    return $count;
}


require_once __DIR__ . '/event_notices.php';


function listarEventosWorkspace(int $yo, array $filters = [], int $max = 60): array
{
    [$policy,$params] = filtroAccesoContenido($yo, 'ev.creador_id', 'event_list');
    $params[':rsvp'] = $yo;
    $now = (new DateTimeImmutable('now', new DateTimeZone('America/Mexico_City')))->format('Y-m-d H:i:s');
    $where = $policy;
    if (($filters['ver'] ?? '') === 'agenda') {
        $params[':owner'] = $yo;
        $params[':attendee'] = $yo;
        $params[':past'] = (new DateTimeImmutable('-7 days', new DateTimeZone('America/Mexico_City')))->format('Y-m-d H:i:s');
        $where .= ' AND (ev.creador_id=:owner OR EXISTS(SELECT 1 FROM evento_asistentes a WHERE a.evento_id=ev.id AND a.usuario_id=:attendee)) AND ev.inicio>=:past';
    } else {
        $where .= " AND ev.estado='publicado' AND ev.inicio>=:now";
        $params[':now'] = $now;
    }
    if (!empty($filters['ciudad'])) {
        $where .= ' AND ev.ciudad LIKE :city';
        $params[':city'] = '%' . addcslashes(mb_substr((string)$filters['ciudad'], 0, 80), '%_\\\\') . '%';
    }
    $st = getDB()->prepare("SELECT ev.*,u.nombre,u.apellido,d.inicio_utc,d.fin_utc,d.zona,d.revision,d.cartel,a.estado AS mi_estado,COALESCE(p.aviso,0) AS aviso,(SELECT COUNT(*) FROM evento_asistentes c JOIN usuarios cu ON cu.id=c.usuario_id AND cu.activo=1 WHERE c.evento_id=ev.id AND c.estado='asistire') AS asistentes,(SELECT COUNT(*) FROM evento_asistentes c JOIN usuarios cu ON cu.id=c.usuario_id AND cu.activo=1 WHERE c.evento_id=ev.id AND c.estado='interesado') AS interesados FROM eventos_musicales ev JOIN usuarios u ON u.id=ev.creador_id AND u.activo=1 LEFT JOIN musicmad_event_details d ON d.evento_id=ev.id LEFT JOIN evento_asistentes a ON a.evento_id=ev.id AND a.usuario_id=:rsvp LEFT JOIN musicmad_event_preferences p ON p.evento_id=ev.id AND p.usuario_id=a.usuario_id WHERE " . $where . ' ORDER BY ev.inicio,ev.id LIMIT ' . max(1, min(100, $max)));
    $st->execute($params);
    $rows = $st->fetchAll();
    foreach ($rows as &$event) {
        if (!$event['inicio_utc']) {
            $start = new DateTimeImmutable($event['inicio'], new DateTimeZone('America/Mexico_City'));
            $event['inicio_utc'] = $start->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            $event['fin_utc'] = $start->modify('+2 hours')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            $event['zona'] = 'America/Mexico_City';
            $event['revision'] = 1;
        }
    }
    unset($event);
    return $rows;
}
function formularioEventoWorkspace(?array $event = null): void
{
    $zone = $event['zona'] ?? 'America/Mexico_City';
    $start = $event ? (new DateTimeImmutable($event['inicio_utc'], new DateTimeZone('UTC')))->setTimezone(new DateTimeZone($zone)) : null;
    $duration = $event ? (int)((strtotime($event['fin_utc'] . ' UTC') - strtotime($event['inicio_utc'] . ' UTC')) / 60) : 120;
    ?><form method="POST" action="<?= e(rutaAplicacion('evento-accion.php', 'POST')) ?>" class="discover-form"><?= csrfCampo() ?><input type="hidden" name="accion" value="<?= $event ? 'editar' : 'crear' ?>"><input type="hidden" name="id" value="<?= (int)($event['id'] ?? 0) ?>"><label>Nombre del evento<input name="titulo" maxlength="120" minlength="2" required value="<?= e($event['titulo'] ?? '') ?>" placeholder="Jam, concierto, encuentro…"></label><label>Descripción<textarea name="descripcion" maxlength="1000" rows="3"><?= e($event['descripcion'] ?? '') ?></textarea></label><div class="discover-field-row"><label>Fecha y hora<input type="datetime-local" name="inicio" required value="<?= $start ? e($start->format('Y-m-d\\TH:i')) : '' ?>"></label><label>Duración (minutos)<input type="number" name="duracion" min="30" max="720" step="15" required value="<?= $duration ?>"></label></div><label>Zona horaria<select name="zona"><?php foreach (['America/Mexico_City' => 'Ciudad de México','America/Tijuana' => 'Tijuana','America/Hermosillo' => 'Hermosillo','America/Cancun' => 'Cancún','America/Monterrey' => 'Monterrey','Europe/Madrid' => 'Madrid','America/Bogota' => 'Bogotá','America/Argentina/Buenos_Aires' => 'Buenos Aires','America/New_York' => 'Nueva York','UTC' => 'UTC'] as $key => $label): ?><option value="<?= $key ?>" <?= $zone === $key ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select></label><div class="discover-field-row"><label>Ciudad<input name="ciudad" maxlength="80" required value="<?= e($event['ciudad'] ?? '') ?>"></label><label>Sede<input name="sede" maxlength="160" value="<?= e($event['sede'] ?? '') ?>" placeholder="Foro o sala"></label></div><button type="submit" class="btn btn-primary">Guardar evento</button></form><?php
}
