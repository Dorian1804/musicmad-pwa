<?php

/** Bounded incremental transport; no daemon, private payloads or session writes on GET. */
function chatLiveDisponible(): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    $tables = ['musicmad_chat_preferences','musicmad_chat_activity','musicmad_chat_receipts','musicmad_chat_limits','musicmad_chat_seen'];
    $st = getDB()->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN (?,?,?,?,?)');
    $st->execute($tables);
    return $ready = (int)$st->fetchColumn() === count($tables) && social3Disponible();
}
function preferenciasChat(int $uid): array
{
    $st = getDB()->prepare('SELECT mostrar_presencia,mostrar_escribiendo,confirmar_lectura FROM musicmad_chat_preferences WHERE usuario_id=?');
    $st->execute([$uid]);
    return array_map('intval', $st->fetch() ?: ['mostrar_presencia' => 0,'mostrar_escribiendo' => 1,'confirmar_lectura' => 1]);
}
function autorizarHiloChat(int $yo, int $con): void
{
    if ($yo < 1 || $con < 1 || $yo === $con) {
        throw new OutOfBoundsException('Conversación no disponible.');
    }
    $st = getDB()->prepare('SELECT COUNT(*) FROM usuarios WHERE id IN (?,?) AND activo=1');
    $st->execute([$yo,$con]);
    if ((int)$st->fetchColumn() !== 2) {
        throw new OutOfBoundsException('Conversación no disponible.');
    }
    if (social3Disponible()) {
        $st = getDB()->prepare("SELECT 1 FROM usuarios_bloqueados WHERE modo='bloquear' AND ((usuario_id=? AND objetivo_id=?) OR (usuario_id=? AND objetivo_id=?)) LIMIT 1");
        $st->execute([$yo,$con,$con,$yo]);
        if ($st->fetchColumn()) {
            throw new OutOfBoundsException('Conversación no disponible.');
        }
    }
}
function limiteChat(int $uid, string $tipo, int $max): void
{
    $db = getDB();
    $db->prepare("INSERT INTO musicmad_chat_limits(usuario_id,bucket,tipo,solicitudes) VALUES(?,DATE_FORMAT(UTC_TIMESTAMP(),'%Y-%m-%d %H:%i:00'),?,1) ON DUPLICATE KEY UPDATE solicitudes=LEAST(solicitudes+1,65535)")->execute([$uid,$tipo]);
    $st = $db->prepare("SELECT solicitudes FROM musicmad_chat_limits WHERE usuario_id=? AND bucket=DATE_FORMAT(UTC_TIMESTAMP(),'%Y-%m-%d %H:%i:00') AND tipo=?");
    $st->execute([$uid,$tipo]);
    if ((int)$st->fetchColumn() > $max) {
        throw new OverflowException('Espera un momento antes de volver a intentar.');
    }
}
function mensajeChatVista(array $m, int $viewer): array
{
    $item = ['id' => (int)$m['id'],'de' => (int)$m['de_id'],'texto' => $m['texto'],'fecha' => $m['created_at'],'tiempo' => hace($m['created_at']),'pista' => null];
    $pub = (int)($m['publicacion_id'] ?? 0);
    if ($pub && puedeVerPublicacionSocial3($viewer, $pub)) {
        $st = getDB()->prepare('SELECT p.titulo FROM publicaciones p JOIN publicacion_audio a ON a.publicacion_id=p.id WHERE p.id=?');
        $st->execute([$pub]);
        $title = $st->fetchColumn();
        if ($title !== false) {
            $item['pista'] = ['id' => $pub,'titulo' => (string)$title,'url' => 'publicacion.php?id=' . $pub];
        }
    }
    return $item;
}
function leerChatIncremental(int $yo, int $con, int $after = 0, int $before = 0): array
{
    autorizarHiloChat($yo, $con);
    $db = getDB();
    $params = [$yo,$con,$con,$yo];
    $where = '((m.de_id=? AND m.para_id=?) OR (m.de_id=? AND m.para_id=?))';
    if ($after > 0) {
        $where .= ' AND m.id>?';
        $params[] = $after;
    } elseif ($before > 0) {
        $where .= ' AND m.id<?';
        $params[] = $before;
    }
    $ascending = $after > 0;
    $st = $db->prepare('SELECT m.id,m.de_id,m.texto,m.created_at,a.publicacion_id FROM mensajes m LEFT JOIN mensajes_adjuntos a ON a.mensaje_id=m.id WHERE ' . $where . ' ORDER BY m.id ' . ($ascending ? 'ASC' : 'DESC') . ' LIMIT 51');
    $st->execute($params);
    $rows = $st->fetchAll();
    $more = count($rows) > 50;
    $rows = array_slice($rows, 0, 50);
    if (!$ascending) {
        $rows = array_reverse($rows);
    }
    $prefs = preferenciasChat($con);
    $st = $db->prepare('SELECT visto_en>UTC_TIMESTAMP()-INTERVAL 45 SECOND AS online,escribiendo_a,escribiendo_hasta>UTC_TIMESTAMP() AS typing FROM musicmad_chat_activity WHERE usuario_id=?');
    $st->execute([$con]);
    $activity = $st->fetch() ?: [];
    $st = $db->prepare('SELECT mensaje_id FROM musicmad_chat_seen WHERE usuario_id=? AND con_id=?');
    $st->execute([$con,$yo]);
    return ['mensajes' => array_map(static fn ($m) => mensajeChatVista($m, $yo), $rows),'mas' => $more,'leido' => $prefs['confirmar_lectura'] ? (int)$st->fetchColumn() : 0,'en_linea' => (bool)($prefs['mostrar_presencia'] && ($activity['online'] ?? 0)),'escribiendo' => (bool)($prefs['mostrar_escribiendo'] && ($activity['typing'] ?? 0) && (int)($activity['escribiendo_a'] ?? 0) === $yo),'puede_enviar' => puedeEnviarMensajeSocial3($yo, $con)];
}
function enviarChatSeguro(int $yo, int $con, string $text, string $request, int $pub = 0): array
{
    $text = trim($text);
    if (!preg_match('/^[a-f0-9]{32}$/D', $request) || mb_strlen($text) > 1000 || ($text === '' && $pub === 0)) {
        throw new InvalidArgumentException('Mensaje no válido.');
    }
    $db = getDB();
    $owns = !$db->inTransaction();
    if ($owns) {
        $db->beginTransaction();
    }
    try {
        $st = $db->prepare('SELECT id FROM usuarios WHERE id=? AND activo=1 FOR UPDATE');
        $st->execute([$yo]);
        if (!$st->fetchColumn()) {
            throw new OutOfBoundsException('Conversación no disponible.');
        }
        autorizarHiloChat($yo, $con);
        $hash = hash('sha256', json_encode([$con,$text,$pub], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $st = $db->prepare('SELECT payload_hash,mensaje_id FROM musicmad_chat_receipts WHERE usuario_id=? AND solicitud=?');
        $st->execute([$yo,$request]);
        $receipt = $st->fetch();
        if ($receipt) {
            if (!hash_equals($receipt['payload_hash'], $hash)) {
                throw new LogicException('La solicitud cambió.');
            }
            $id = (int)$receipt['mensaje_id'];
        } else {
            if (!puedeEnviarMensajeSocial3($yo, $con)) {
                throw new OutOfBoundsException('Este músico no recibe mensajes de tu cuenta.');
            }
            if ($pub > 0) {
                if (!puedeVerPublicacionSocial3($yo, $pub) || !puedeVerPublicacionSocial3($con, $pub)) {
                    throw new OutOfBoundsException('Esa pista no está disponible para ambos.');
                }
                $st = $db->prepare('SELECT 1 FROM publicacion_audio WHERE publicacion_id=?');
                $st->execute([$pub]);
                if (!$st->fetchColumn()) {
                    throw new OutOfBoundsException('Pista no disponible.');
                }
            }
            limiteChat($yo, 'enviar', 30);
            $db->prepare('INSERT INTO mensajes(de_id,para_id,texto) VALUES(?,?,?)')->execute([$yo,$con,$text]);
            $id = (int)$db->lastInsertId();
            if ($pub > 0) {
                $db->prepare("INSERT INTO mensajes_adjuntos(mensaje_id,publicacion_id,tipo) VALUES(?,?,'pista')")->execute([$id,$pub]);
            }
            $db->prepare('INSERT INTO musicmad_chat_receipts(usuario_id,solicitud,payload_hash,mensaje_id) VALUES(?,?,?,?)')->execute([$yo,$request,$hash,$id]);
            try {
                encolarAvisoPush($con, $yo, 'mensajes', $id);
            } catch (Throwable $error) {
                error_log('DM push queue: ' . get_class($error));
            }
        }
        $st = $db->prepare('SELECT m.id,m.de_id,m.texto,m.created_at,a.publicacion_id FROM mensajes m LEFT JOIN mensajes_adjuntos a ON a.mensaje_id=m.id WHERE m.id=?');
        $st->execute([$id]);
        $item = mensajeChatVista($st->fetch(), $yo);
        if ($owns) {
            $db->commit();
        }
        return $item;
    } catch (Throwable $error) {
        if ($owns && $db->inTransaction()) {
            $db->rollBack();
        }
        throw $error;
    }
}
function marcarChatHasta(int $yo, int $con, int $hasta): void
{
    autorizarHiloChat($yo, $con);
    if ($hasta < 1) {
        return;
    }
    $db = getDB();
    $db->prepare('UPDATE mensajes SET leido=1 WHERE para_id=? AND de_id=? AND id<=? AND leido=0')->execute([$yo,$con,$hasta]);
    // Store only an actual received ID, so a forged cursor cannot acknowledge future messages.
    $st = $db->prepare('SELECT MAX(id) FROM mensajes WHERE para_id=? AND de_id=? AND id<=?');
    $st->execute([$yo,$con,$hasta]);
    $id = (int)$st->fetchColumn();
    if ($id) {
        $db->prepare('INSERT INTO musicmad_chat_seen(usuario_id,con_id,mensaje_id) VALUES(?,?,?) ON DUPLICATE KEY UPDATE mensaje_id=GREATEST(mensaje_id,VALUES(mensaje_id))')->execute([$yo,$con,$id]);
    }
}
function actividadChat(int $yo, int $con, bool $typing): void
{
    autorizarHiloChat($yo, $con);
    limiteChat($yo, 'actividad', 30);
    $prefs = preferenciasChat($yo);
    $db = getDB();
    $db->prepare('INSERT INTO musicmad_chat_activity(usuario_id,visto_en,escribiendo_a,escribiendo_hasta) VALUES(?,UTC_TIMESTAMP(),?,UTC_TIMESTAMP()+INTERVAL 5 SECOND) ON DUPLICATE KEY UPDATE visto_en=UTC_TIMESTAMP(),escribiendo_a=VALUES(escribiendo_a),escribiendo_hasta=VALUES(escribiendo_hasta)')->execute([$yo,$typing && $prefs['mostrar_escribiendo'] ? $con : null]);
}
function marcarAvisosHasta(int $yo, int $hasta): void
{
    if ($hasta < 1) {
        return;
    }
    getDB()->prepare('UPDATE notificaciones SET leida=1 WHERE usuario_id=? AND id<=? AND leida=0')->execute([$yo,$hasta]);
}


function chatStreamDisponible(): bool
{
    if (PHP_SAPI === 'cli-server' || getenv('MM_CHAT_SSE') !== '1' || !chatLiveDisponible() || !registroSesionesDisponible()) {
        return false;
    }
    $st = getDB()->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='musicmad_chat_stream_leases'");
    $st->execute();
    return (bool)$st->fetchColumn();
}
function reservarStreamChat(int $yo): string
{
    $lease = bin2hex(random_bytes(16));
    $db = getDB();
    $db->prepare('INSERT INTO musicmad_chat_stream_leases(usuario_id,lease,hasta) VALUES(?,?,UTC_TIMESTAMP()+INTERVAL 8 SECOND) ON DUPLICATE KEY UPDATE lease=IF(hasta<=UTC_TIMESTAMP(),VALUES(lease),lease),hasta=IF(hasta<=UTC_TIMESTAMP(),VALUES(hasta),hasta)')->execute([$yo,$lease]);
    $st = $db->prepare('SELECT lease FROM musicmad_chat_stream_leases WHERE usuario_id=?');
    $st->execute([$yo]);
    return hash_equals($lease, (string)$st->fetchColumn()) ? $lease : '';
}
function sesionStreamChatValida(int $yo, string $selector, string $hash, int $version): bool
{
    $st = getDB()->prepare('SELECT 1 FROM usuarios u JOIN musicmad_cuenta_seguridad c ON c.usuario_id=u.id JOIN sesiones_dispositivos d ON d.usuario_id=u.id JOIN musicmad_session_bindings b ON b.selector=d.selector WHERE u.id=? AND u.activo=1 AND c.version_acceso=? AND d.selector=? AND d.revocada_en IS NULL AND b.session_hash=? AND b.version_acceso=c.version_acceso AND b.expira_en>UTC_TIMESTAMP()');
    $st->execute([$yo,$version,$selector,$hash]);
    return (bool)$st->fetchColumn();
}


function contadoresChat(int $yo): array
{
    return ['mensajes' => contarMensajesSinLeer($yo),'avisos' => contarNoLeidas($yo) + contarSolicitudesPendientesSeguimiento($yo)];
}


function guardarPreferenciasChat(int $yo, array $data): void
{
    $values = [];
    foreach (['mostrar_presencia','mostrar_escribiendo','confirmar_lectura'] as $key) {
        if (!is_bool($data[$key] ?? null)) {
            throw new InvalidArgumentException('Preferencia no válida.');
        }
        $values[] = (int)$data[$key];
    }
    getDB()->prepare('INSERT INTO musicmad_chat_preferences(usuario_id,mostrar_presencia,mostrar_escribiendo,confirmar_lectura) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE mostrar_presencia=VALUES(mostrar_presencia),mostrar_escribiendo=VALUES(mostrar_escribiendo),confirmar_lectura=VALUES(confirmar_lectura)')->execute([$yo,...$values]);
}
