<?php

/** Member-only agenda and chat. Every mutation serializes against membership changes. */
function fechaEnsayo(string $value, string $zone): DateTimeImmutable
{
    if (!in_array($zone, DateTimeZone::listIdentifiers(), true) || !preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d$/D', $value)) {
        throw new InvalidArgumentException('Fecha o zona horaria no válida.');
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value, new DateTimeZone($zone));
    $errors = DateTimeImmutable::getLastErrors();
    if (!$date || ($errors && ($errors['warning_count'] || $errors['error_count'])) || $date->format('Y-m-d\TH:i') !== $value) {
        throw new InvalidArgumentException('Esa fecha no existe en la zona elegida.');
    }
    return $date;
}
function guardarEnsayoBanda(int $yo, int $band, array $data, int $id = 0): int
{
    $title = trim(textoCampo($data, 'titulo'));
    $place = trim(textoCampo($data, 'lugar'));
    $notes = trim(textoCampo($data, 'notas'));
    $zone = textoCampo($data, 'zona');
    $start = fechaEnsayo(textoCampo($data, 'inicio'), $zone);
    $duration = idCampo($data, 'duracion');
    if (mb_strlen($title) < 2 || mb_strlen($title) > 100 || mb_strlen($place) > 160 || mb_strlen($notes) > 500 || $duration < 30 || $duration > 360 || $start->getTimestamp() < time() - 86400 || $start->getTimestamp() > time() + 86400 * 730) {
        throw new InvalidArgumentException('Revisa título, fecha y duración (30 a 360 minutos).');
    }
    $utc = $start->setTimezone(new DateTimeZone('UTC'));
    $end = $utc->modify('+' . $duration . ' minutes');
    return transaccionBanda($yo, $band, function (PDO $db) use ($yo, $band, $id, $title, $place, $notes, $zone, $utc, $end) {
        exigirIntegranteBanda($yo, $band, ['admin','coordinador']);
        if ($id) {
            $st = $db->prepare("SELECT 1 FROM bandas_ensayos e JOIN musicmad_band_rehearsals r ON r.ensayo_id=e.id WHERE e.id=? AND e.banda_id=? AND r.estado='programado'");
            $st->execute([$id,$band]);
            if (!$st->fetchColumn()) {
                throw new OutOfBoundsException('Ensayo no disponible.');
            }
            $db->prepare('UPDATE bandas_ensayos SET titulo=?,lugar=?,inicio=?,notas=? WHERE id=?')->execute([$title,$place,$utc->format('Y-m-d H:i:s'),$notes,$id]);
            $db->prepare('UPDATE musicmad_band_rehearsals SET fin=?,zona=?,revision=revision+1 WHERE ensayo_id=?')->execute([$end->format('Y-m-d H:i:s'),$zone,$id]);
        } else {
            $st = $db->prepare("SELECT COUNT(*) FROM bandas_ensayos e JOIN musicmad_band_rehearsals r ON r.ensayo_id=e.id WHERE e.banda_id=? AND r.estado='programado' AND r.fin>=UTC_TIMESTAMP()");
            $st->execute([$band]);
            if ((int)$st->fetchColumn() >= 50) {
                throw new OverflowException('Ya tienes cincuenta ensayos próximos.');
            }
            $db->prepare('INSERT INTO bandas_ensayos(banda_id,creado_por,titulo,lugar,inicio,notas) VALUES(?,?,?,?,?,?)')->execute([$band,$yo,$title,$place,$utc->format('Y-m-d H:i:s'),$notes]);
            $id = (int)$db->lastInsertId();
            $db->prepare('INSERT INTO musicmad_band_rehearsals(ensayo_id,fin,zona) VALUES(?,?,?)')->execute([$id,$end->format('Y-m-d H:i:s'),$zone]);
        }
        foreach (miembrosBandaWorkspace($band) as $member) {
            notificarActividadBanda((int)$member['usuario_id'], $yo, $band, 'ensayo', $id);
        }
        return $id;
    });
}
function cancelarEnsayoBanda(int $yo, int $band, int $id): void
{
    transaccionBanda($yo, $band, function (PDO $db) use ($yo, $band, $id) {
        exigirIntegranteBanda($yo, $band, ['admin','coordinador']);
        $st = $db->prepare("UPDATE musicmad_band_rehearsals r JOIN bandas_ensayos e ON e.id=r.ensayo_id SET r.estado='cancelado',r.revision=r.revision+1 WHERE e.banda_id=? AND e.id=? AND r.estado='programado'");
        $st->execute([$band,$id]);
        if (!$st->rowCount()) {
            throw new OutOfBoundsException('Ensayo no disponible.');
        }
        foreach (miembrosBandaWorkspace($band) as $member) {
            notificarActividadBanda((int)$member['usuario_id'], $yo, $band, 'ensayo_cancelado', $id);
        }
    });
}
function responderEnsayoBanda(int $yo, int $band, int $id, string $status, bool $reminder): void
{
    if (!in_array($status, ['si','no','tal_vez'], true)) {
        throw new InvalidArgumentException('Respuesta no válida.');
    }
    transaccionBanda($yo, $band, function (PDO $db) use ($yo, $band, $id, $status, $reminder) {
        exigirIntegranteBanda($yo, $band);
        $st = $db->prepare("SELECT 1 FROM bandas_ensayos e JOIN musicmad_band_rehearsals r ON r.ensayo_id=e.id WHERE e.id=? AND e.banda_id=? AND r.estado='programado' AND r.fin>UTC_TIMESTAMP()");
        $st->execute([$id,$band]);
        if (!$st->fetchColumn()) {
            throw new OutOfBoundsException('Ensayo no disponible.');
        }
        $db->prepare('INSERT INTO musicmad_band_rsvp(ensayo_id,usuario_id,estado,aviso) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE estado=VALUES(estado),aviso=VALUES(aviso)')->execute([$id,$yo,$status,(int)$reminder]);
    });
}
function ensayosBanda(int $yo, int $band): array
{
    return transaccionBanda($yo, $band, static fn () => ensayosBandaDatos($yo, $band));
}
function ensayosBandaDatos(int $yo, int $band): array
{
    exigirIntegranteBanda($yo, $band);
    $st = getDB()->prepare("SELECT e.*,r.fin,r.zona,r.estado,r.revision,a.estado AS respuesta,COALESCE(a.aviso,1) AS aviso,(SELECT COUNT(*) FROM musicmad_band_rsvp x JOIN banda_miembros m ON m.banda_id=e.banda_id AND m.usuario_id=x.usuario_id JOIN usuarios u ON u.id=x.usuario_id AND u.activo=1 WHERE x.ensayo_id=e.id AND x.estado='si') AS asistentes FROM bandas_ensayos e JOIN musicmad_band_rehearsals r ON r.ensayo_id=e.id LEFT JOIN musicmad_band_rsvp a ON a.ensayo_id=e.id AND a.usuario_id=? WHERE e.banda_id=? AND r.fin>UTC_TIMESTAMP()-INTERVAL 7 DAY ORDER BY e.inicio LIMIT 50");
    $st->execute([$yo,$band]);
    return $st->fetchAll();
}
function mensajeBandaVista(array $m, int $yo): array
{
    $item = mensajeChatVista(['id' => $m['id'],'de_id' => $m['usuario_id'],'texto' => $m['texto'],'created_at' => $m['created_at'],'publicacion_id' => $m['publicacion_id'] ?? null], $yo);
    $item['autor'] = trim($m['nombre'] . ' ' . $m['apellido']);
    return $item;
}
function leerChatBanda(int $yo, int $band, int $after = 0, int $before = 0): array
{
    return transaccionBanda($yo, $band, static fn () => leerChatBandaDatos($yo, $band, $after, $before));
}
function leerChatBandaDatos(int $yo, int $band, int $after = 0, int $before = 0): array
{
    exigirIntegranteBanda($yo, $band);
    [$block,$params] = filtroBloqueoInteraccion($yo, 'u.id', 'group_chat');
    $params[':band'] = $band;
    $where = 'm.banda_id=:band AND ' . $block;
    if ($after) {
        $where .= ' AND m.id>:cursor';
        $params[':cursor'] = $after;
    } elseif ($before) {
        $where .= ' AND m.id<:cursor';
        $params[':cursor'] = $before;
    }
    $st = getDB()->prepare('SELECT m.*,u.nombre,u.apellido,t.publicacion_id FROM mensajes_banda m JOIN usuarios u ON u.id=m.usuario_id AND u.activo=1 LEFT JOIN musicmad_band_chat_tracks t ON t.mensaje_id=m.id WHERE ' . $where . ' ORDER BY m.id ' . ($after ? 'ASC' : 'DESC') . ' LIMIT 51');
    $st->execute($params);
    $rows = $st->fetchAll();
    $more = count($rows) > 50;
    $rows = array_slice($rows, 0, 50);
    if (!$after) {
        $rows = array_reverse($rows);
    }
    [$readerBlock,$rp] = filtroBloqueoInteraccion($yo, 'u.id', 'group_read');
    $rs = getDB()->prepare('SELECT r.mensaje_id FROM musicmad_band_chat_reads r JOIN usuarios u ON u.id=r.usuario_id AND u.activo=1 LEFT JOIN musicmad_chat_preferences p ON p.usuario_id=u.id WHERE r.banda_id=:band AND r.usuario_id<>:yo AND COALESCE(p.confirmar_lectura,1)=1 AND ' . $readerBlock);
    $rs->execute([':band' => $band,':yo' => $yo] + $rp);
    $cursors = array_map('intval', $rs->fetchAll(PDO::FETCH_COLUMN));
    $reads = [];
    foreach ($rows as $m) {
        if ((int)$m['usuario_id'] === $yo) {
            $reads[(int)$m['id']] = count(array_filter($cursors, static fn ($cursor) => $cursor >= (int)$m['id']));
        }
    }
    return ['mensajes' => array_map(static fn ($m) => mensajeBandaVista($m, $yo), $rows),'mas' => $more,'lecturas' => (object)$reads,'cursores_lectura' => $cursors,'leido' => 0,'en_linea' => false,'escribiendo' => false,'puede_enviar' => true];
}
function enviarChatBanda(int $yo, int $band, string $text, string $request, int $pub = 0): array
{
    $text = trim($text);
    if (!preg_match('/^[a-f0-9]{32}$/D', $request) || mb_strlen($text) > 1000 || ($text === '' && !$pub)) {
        throw new InvalidArgumentException('Mensaje no válido.');
    }
    return transaccionBanda($yo, $band, function (PDO $db) use ($yo, $band, $text, $request, $pub) {
        exigirIntegranteBanda($yo, $band);
        $hash = hash('sha256', json_encode([$band,$text,$pub], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $st = $db->prepare('SELECT payload_hash,mensaje_id FROM musicmad_band_chat_receipts WHERE usuario_id=? AND solicitud=?');
        $st->execute([$yo,$request]);
        $receipt = $st->fetch();
        if ($receipt) {
            if (!hash_equals($receipt['payload_hash'], $hash)) {
                throw new LogicException('La solicitud cambió.');
            }
            $id = (int)$receipt['mensaje_id'];
        } else {
            if ($pub) {
                if (!puedeVerPublicacionSocial3($yo, $pub)) {
                    throw new OutOfBoundsException('Pista no disponible.');
                }
                $st = $db->prepare('SELECT 1 FROM publicacion_audio WHERE publicacion_id=?');
                $st->execute([$pub]);
                if (!$st->fetchColumn()) {
                    throw new OutOfBoundsException('Pista no disponible.');
                }
            }
            limiteChat($yo, 'grupo_enviar', 30);
            $db->prepare('INSERT INTO mensajes_banda(banda_id,usuario_id,texto) VALUES(?,?,?)')->execute([$band,$yo,$text]);
            $id = (int)$db->lastInsertId();
            if ($pub) {
                $db->prepare('INSERT INTO musicmad_band_chat_tracks(mensaje_id,publicacion_id) VALUES(?,?)')->execute([$id,$pub]);
            }
            $db->prepare('INSERT INTO musicmad_band_chat_receipts(usuario_id,solicitud,payload_hash,mensaje_id) VALUES(?,?,?,?)')->execute([$yo,$request,$hash,$id]);
        }
        $st = $db->prepare('SELECT m.*,u.nombre,u.apellido,t.publicacion_id FROM mensajes_banda m JOIN usuarios u ON u.id=m.usuario_id LEFT JOIN musicmad_band_chat_tracks t ON t.mensaje_id=m.id WHERE m.id=?');
        $st->execute([$id]);
        return mensajeBandaVista($st->fetch(), $yo);
    });
}
function marcarChatBanda(int $yo, int $band, int $until): void
{
    transaccionBanda($yo, $band, function (PDO $db) use ($yo, $band, $until) {
        exigirIntegranteBanda($yo, $band);
        if ($until < 1) {
            return;
        }[$block,$params] = filtroBloqueoInteraccion($yo, 'u.id', 'group_seen');
        $st = $db->prepare('SELECT MAX(m.id) FROM mensajes_banda m JOIN usuarios u ON u.id=m.usuario_id AND u.activo=1 WHERE m.banda_id=:band AND m.id<=:until AND ' . $block);
        $st->execute([':band' => $band,':until' => $until] + $params);
        $id = (int)$st->fetchColumn();
        if ($id) {
            $db->prepare('INSERT INTO musicmad_band_chat_reads(banda_id,usuario_id,mensaje_id) VALUES(?,?,?) ON DUPLICATE KEY UPDATE mensaje_id=GREATEST(mensaje_id,VALUES(mensaje_id))')->execute([$band,$yo,$id]);
        }
    });
}
function publicacionesBanda(int $yo, int $band, int $before = 0): array
{
    if (!bandaDisponible($yo, $band)) {
        throw new OutOfBoundsException('Banda no disponible.');
    }
    // Use the same viewer policy and renderer data as the feed, then constrain the group association.
    return listarFeed($yo, 'todo', ['grupo' => $band], 20, $before ?: null);
}
function invitacionesAdministradasBanda(int $yo, int $band): array
{
    exigirIntegranteBanda($yo, $band, ['admin']);
    $st = getDB()->prepare("SELECT i.invitado_id,u.nombre,u.apellido,i.expira_en FROM musicmad_band_invites i JOIN usuarios u ON u.id=i.invitado_id AND u.activo=1 WHERE i.banda_id=? AND i.estado='pendiente' AND i.expira_en>UTC_TIMESTAMP() ORDER BY i.created_at DESC LIMIT 100");
    $st->execute([$band]);
    return $st->fetchAll();
}
function cancelarInvitacionBanda(int $yo, int $band, int $target): void
{
    transaccionBanda($yo, $band, function (PDO $db) use ($yo, $band, $target) {
        exigirIntegranteBanda($yo, $band, ['admin']);
        $db->prepare("UPDATE musicmad_band_invites SET estado='cancelada',respondida_en=UTC_TIMESTAMP() WHERE banda_id=? AND invitado_id=? AND estado='pendiente'")->execute([$band,$target]);
    });
}


function avisosBandaDisponibles(int $yo, array $notice): bool
{
    $band = (int)$notice['banda_id'];
    if (!bandaDisponible($yo, $band)) {
        return false;
    }
    $type = $notice['subtipo'] ?? '';
    if ($type === 'invitacion') {
        $st = getDB()->prepare("SELECT 1 FROM musicmad_band_invites i JOIN banda_miembros m ON m.banda_id=i.banda_id AND m.usuario_id=i.invitado_por AND m.rol='admin' WHERE i.banda_id=? AND i.invitado_id=? AND i.invitado_por=? AND i.estado='pendiente' AND i.expira_en>UTC_TIMESTAMP()");
        $st->execute([$band,$yo,$notice['actor_id']]);
        return (bool)$st->fetchColumn();
    }
    if (rolEnBanda($yo, $band) === null) {
        return false;
    }
    if (in_array($type, ['ensayo','ensayo_cancelado','recordatorio'], true)) {
        $st = getDB()->prepare('SELECT e.inicio,r.estado,r.revision FROM bandas_ensayos e JOIN musicmad_band_rehearsals r ON r.ensayo_id=e.id WHERE e.id=? AND e.banda_id=?');
        $st->execute([$notice['referencia_id'],$band]);
        $event = $st->fetch();
        if (!$event || $event['estado'] !== ($type === 'ensayo_cancelado' ? 'cancelado' : 'programado')) {
            return false;
        }
        if ($type === 'recordatorio') {
            if (strtotime($event['inicio'] . ' UTC') <= time()) {
                return false;
            }
            $st = getDB()->prepare("SELECT 1 FROM musicmad_band_reminders m JOIN musicmad_band_rsvp a ON a.ensayo_id=m.ensayo_id AND a.usuario_id=m.usuario_id WHERE m.notificacion_id=? AND m.usuario_id=? AND m.revision=? AND a.aviso=1 AND a.estado IN ('si','tal_vez')");
            $st->execute([$notice['id'],$yo,$event['revision']]);
            return (bool)$st->fetchColumn();
        }
    }
    return true;
}
function recordatoriosBandaDisponibles(): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    $st = getDB()->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='musicmad_band_reminders'");
    $st->execute();
    return $ready = (bool)$st->fetchColumn() && bandasWorkspaceDisponible();
}
function generarRecordatoriosBandas(int $max = 50): int
{
    if (PHP_SAPI !== 'cli') {
        throw new RuntimeException('Los recordatorios se ejecutan desde cron.');
    }
    if (!recordatoriosBandaDisponibles()) {
        return 0;
    }
    $deadline = microtime(true) + 5;
    $st = getDB()->prepare("SELECT e.id,e.banda_id,e.creado_por,a.usuario_id,r.revision,IF(e.inicio>UTC_TIMESTAMP()+INTERVAL 23 HOUR,'24h','1h') AS ventana FROM bandas_ensayos e JOIN musicmad_band_rehearsals r ON r.ensayo_id=e.id AND r.estado='programado' JOIN musicmad_band_rsvp a ON a.ensayo_id=e.id AND a.aviso=1 AND a.estado IN ('si','tal_vez') JOIN banda_miembros m ON m.banda_id=e.banda_id AND m.usuario_id=a.usuario_id JOIN usuarios u ON u.id=m.usuario_id AND u.activo=1 LEFT JOIN musicmad_band_settings s ON s.banda_id=e.banda_id WHERE s.archivada_en IS NULL AND e.inicio>UTC_TIMESTAMP() AND (e.inicio<=UTC_TIMESTAMP()+INTERVAL 1 HOUR OR e.inicio BETWEEN UTC_TIMESTAMP()+INTERVAL 23 HOUR AND UTC_TIMESTAMP()+INTERVAL 24 HOUR) AND NOT EXISTS(SELECT 1 FROM musicmad_band_reminders n WHERE n.ensayo_id=e.id AND n.usuario_id=a.usuario_id AND n.revision=r.revision AND n.ventana=IF(e.inicio>UTC_TIMESTAMP()+INTERVAL 23 HOUR,'24h','1h')) ORDER BY e.inicio,e.id,a.usuario_id LIMIT " . max(1, min(100, $max)));
    $st->execute();
    $count = 0;
    foreach ($st->fetchAll() as $candidate) {
        if (microtime(true) > $deadline) {
            break;
        }
        try {
            $made = transaccionBanda((int)$candidate['usuario_id'], (int)$candidate['banda_id'], function (PDO $db) use ($candidate) {
                $st = $db->prepare("SELECT e.creado_por,r.revision FROM bandas_ensayos e JOIN musicmad_band_rehearsals r ON r.ensayo_id=e.id JOIN musicmad_band_rsvp a ON a.ensayo_id=e.id AND a.usuario_id=? WHERE e.id=? AND e.banda_id=? AND r.estado='programado' AND r.revision=? AND a.aviso=1 AND a.estado IN ('si','tal_vez') AND e.inicio>UTC_TIMESTAMP() AND " . ($candidate['ventana'] === '1h' ? "e.inicio<=UTC_TIMESTAMP()+INTERVAL 1 HOUR" : "e.inicio BETWEEN UTC_TIMESTAMP()+INTERVAL 23 HOUR AND UTC_TIMESTAMP()+INTERVAL 24 HOUR"));
                $st->execute([$candidate['usuario_id'],$candidate['id'],$candidate['banda_id'],$candidate['revision']]);
                if (!$st->fetch()) {
                    return false;
                }
                exigirIntegranteBanda((int)$candidate['usuario_id'], (int)$candidate['banda_id']);
                $st = $db->prepare('INSERT IGNORE INTO musicmad_band_reminders(ensayo_id,usuario_id,revision,ventana) VALUES(?,?,?,?)');
                $st->execute([$candidate['id'],$candidate['usuario_id'],$candidate['revision'],$candidate['ventana']]);
                if (!$st->rowCount()) {
                    return false;
                }
                $notice = notificarActividadBanda((int)$candidate['usuario_id'], (int)$candidate['creado_por'], (int)$candidate['banda_id'], 'recordatorio', (int)$candidate['id']);
                $db->prepare('UPDATE musicmad_band_reminders SET notificacion_id=? WHERE ensayo_id=? AND usuario_id=? AND revision=? AND ventana=?')->execute([$notice,$candidate['id'],$candidate['usuario_id'],$candidate['revision'],$candidate['ventana']]);
                return true;
            });
            if ($made) {
                $count++;
            }
        } catch (OutOfBoundsException $error) {/* Membership or archive changed since selection. */
        }
    }
    return $count;
}


function filtroNotificacionesBandas(int $yo, string $prefix): array
{
    if (!bandasWorkspaceDisponible()) {
        return ['1=1',[]];
    }
    [$block,$params] = filtroBloqueoInteraccion($yo, 'bc.id', $prefix . '_creator');
    $owner = ':' . $prefix . '_owner';
    $params[$owner] = $yo;
    $invite = ':' . $prefix . '_invite';
    $params[$invite] = $yo;
    return ["(n.tipo<>'banda' OR EXISTS(SELECT 1 FROM bandas b JOIN usuarios bc ON bc.id=b.creador_id AND bc.activo=1 LEFT JOIN musicmad_band_settings bs ON bs.banda_id=b.id WHERE b.id=n.banda_id AND bs.archivada_en IS NULL AND " . $block . " AND (EXISTS(SELECT 1 FROM banda_miembros bm WHERE bm.banda_id=b.id AND bm.usuario_id=" . $owner . ") OR EXISTS(SELECT 1 FROM musicmad_band_notifications bn JOIN musicmad_band_invites bi ON bi.banda_id=b.id AND bi.invitado_id=" . $invite . " AND bi.invitado_por=n.actor_id JOIN banda_miembros admin ON admin.banda_id=bi.banda_id AND admin.usuario_id=bi.invitado_por AND admin.rol='admin' WHERE bn.notificacion_id=n.id AND bn.subtipo='invitacion' AND bi.estado='pendiente' AND bi.expira_en>UTC_TIMESTAMP()))))",$params];
}


function candidatosInvitacionBanda(int $yo, int $band, string $query = ''): array
{
    exigirIntegranteBanda($yo, $band, ['admin']);
    [$policy,$params] = filtroAccesoContenido($yo, 'u.id', 'band_invite_candidate');
    $st = getDB()->prepare('SELECT u.id,u.nombre,u.apellido,pm.instrumento FROM usuarios u LEFT JOIN perfiles_musicales pm ON pm.usuario_id=u.id WHERE u.activo=1 AND u.id<>:yo AND (NOT EXISTS(SELECT 1 FROM banda_miembros m WHERE m.banda_id=:band AND m.usuario_id=u.id)) AND (' . $policy . ') AND CONCAT_WS(" ",u.nombre,u.apellido,pm.instrumento) LIKE :query ORDER BY EXISTS(SELECT 1 FROM seguidores f WHERE f.seguidor_id=:follower AND f.seguido_id=u.id) DESC,u.nombre,u.id LIMIT 30');
    $st->execute([':yo' => $yo,':band' => $band,':query' => '%' . addcslashes(mb_substr(trim($query), 0, 80), '%_\\\\') . '%',':follower' => $yo] + $params);
    return $st->fetchAll();
}
