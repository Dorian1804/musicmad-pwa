<?php

function salasEscuchaDisponibles(): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    $tables = ['musicmad_room_state','musicmad_room_messages','musicmad_room_receipts'];
    $st = getDB()->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN (?,?,?)');
    $st->execute($tables);
    $n = (int)$st->fetchColumn();
    if ($n === 0) {
        return $ready = false;
    }
    return $ready = $n === 3 && social3Disponible() && chatLiveDisponible();
}
function salaDisponiblePara(int $uid, int $id): ?array
{
    if (!funcionalidadMusicMad('salas', $uid)) {
        return null;
    }
    if (!salasEscuchaDisponibles() || $uid < 1) {
        return null;
    }
    [$block,$params] = filtroBloqueoInteraccion($uid, 'h.id', 'room_host');
    $st = getDB()->prepare("SELECT s.id,s.nombre,s.anfitrion_id,s.publicacion_id,s.codigo,r.modo,r.reproduccion,r.posicion,r.revision,r.actualizada_utc,r.expira_en,p.titulo,m.duracion,CONCAT(h.nombre,' ',h.apellido) AS anfitrion,CONCAT(a.nombre,' ',a.apellido) AS artista FROM salas_escucha s JOIN musicmad_room_state r ON r.sala_id=s.id JOIN usuarios h ON h.id=s.anfitrion_id AND h.activo=1 JOIN publicaciones p ON p.id=s.publicacion_id JOIN usuarios a ON a.id=p.usuario_id AND a.activo=1 JOIN publicacion_audio pa ON pa.publicacion_id=p.id JOIN publicacion_medios m ON m.publicacion_id=p.id WHERE s.id=:id AND s.estado='activa' AND r.expira_en>UTC_TIMESTAMP() AND " . $block . ' LIMIT 1');
    $st->execute([':id' => $id] + $params);
    $row = $st->fetch();
    if (!$row || !is_finite((float)$row['duracion']) || (float)$row['duracion'] <= 0 || !puedeVerPublicacionSocial3($uid, (int)$row['publicacion_id'])) {
        return null;
    }
    return $row;
}
function transaccionSala(int $uid, int $id, callable $operation): mixed
{
    $db = getDB();
    $owns = !$db->inTransaction();
    if ($owns) {
        $db->beginTransaction();
    }
    try {
        $st = $db->prepare('SELECT id FROM usuarios WHERE id=? AND activo=1 FOR UPDATE');
        $st->execute([$uid]);
        if (!$st->fetchColumn()) {
            throw new OutOfBoundsException('Acceso no disponible.');
        }
        $st = $db->prepare('SELECT id FROM salas_escucha WHERE id=? FOR UPDATE');
        $st->execute([$id]);
        $row = $st->fetchColumn() ? salaDisponiblePara($uid, $id) : null;
        if (!$row) {
            throw new OutOfBoundsException('Esta sala no está disponible.');
        }
        $result = $operation($db, $row);
        if ($owns) {
            $db->commit();
        }
        return $result;
    } catch (Throwable $error) {
        if ($owns && $db->inTransaction()) {
            $db->rollBack();
        }
        throw $error;
    }
}
function integranteSala(int $uid, int $id): bool
{
    $st = getDB()->prepare('SELECT 1 FROM salas_integrantes WHERE sala_id=? AND usuario_id=?');
    $st->execute([$id,$uid]);
    return (bool)$st->fetchColumn();
}
function exigirIntegranteSala(int $uid, int $id): void
{
    if (!integranteSala($uid, $id)) {
        throw new OutOfBoundsException('Entra a la sala para escuchar y escribir.');
    }
}
function metadatosParaSala(int $uid, int $pub): array
{
    if (!puedeVerPublicacionSocial3($uid, $pub)) {
        throw new OutOfBoundsException('Pista no disponible.');
    }
    $st = getDB()->prepare('SELECT a.archivo,m.duracion FROM publicaciones p JOIN publicacion_audio a ON a.publicacion_id=p.id LEFT JOIN publicacion_medios m ON m.publicacion_id=p.id WHERE p.id=?');
    $st->execute([$pub]);
    $row = $st->fetch();
    if (!$row) {
        throw new OutOfBoundsException('Elige una pista con audio subido.');
    }
    $file = (string)$row['archivo'];
    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,70}\.(mp3|m4a|ogg|wav)$/Di', $file)) {
        throw new OutOfBoundsException('Pista no disponible.');
    }
    $directory = musicmadMediaDirectoryForFile('audio', $file);
    $base = realpath($directory);
    $path = realpath($directory . '/' . $file);
    if (is_link($directory) || is_link($directory . '/' . $file) || !$base || !$path || dirname($path) !== $base) {
        throw new OutOfBoundsException('Pista no disponible.');
    }
    if (!is_file($path) || filesize($path) < 1 || filesize($path) > AUDIO_MAX_BYTES) {
        throw new OutOfBoundsException('No se encuentra un archivo de audio válido para esa pista.');
    }
    if ($row['duracion'] === null) {
        $metadata = analizarMedioLocal($path, 'audio');
        $row['duracion'] = $metadata['duracion'];
        $row['metadata'] = $metadata;
    }
    $duration = (float)$row['duracion'];
    if (!is_finite($duration) || $duration <= 0 || $duration > 21600) {
        throw new InvalidArgumentException('La pista debe tener una duración legible de hasta seis horas.');
    }
    return $row;
}
function crearSalaEscucha(int $uid, array $data): int
{
    exigirFuncionMusicMad('salas', $uid);
    if (!salasEscuchaDisponibles()) {
        throw new RuntimeException('Importa la actualización de salas.');
    }
    $name = trim(textoCampo($data, 'nombre'));
    $mode = textoCampo($data, 'modo');
    $pub = idCampo($data, 'publicacion');
    if (mb_strlen($name) < 2 || mb_strlen($name) > 100 || !in_array($mode, ['publica','enlace'], true) || !$pub) {
        throw new InvalidArgumentException('Revisa el nombre, la pista y el acceso.');
    }
    limiteChat($uid, 'sala_crear', 5);
    $metadata = metadatosParaSala($uid, $pub);
    $db = getDB();
    $db->beginTransaction();
    try {
        $st = $db->prepare('SELECT id FROM usuarios WHERE id=? AND activo=1 FOR UPDATE');
        $st->execute([$uid]);
        if (!$st->fetchColumn()) {
            throw new OutOfBoundsException('Acceso no disponible.');
        }
        $st = $db->prepare('SELECT id FROM publicaciones WHERE id=? FOR UPDATE');
        $st->execute([$pub]);
        if (!$st->fetchColumn() || !puedeVerPublicacionSocial3($uid, $pub)) {
            throw new OutOfBoundsException('Pista no disponible.');
        }
        $st = $db->prepare("SELECT COUNT(*) FROM salas_escucha s JOIN musicmad_room_state r ON r.sala_id=s.id WHERE s.anfitrion_id=? AND s.estado='activa' AND r.expira_en>UTC_TIMESTAMP()");
        $st->execute([$uid]);
        if ((int)$st->fetchColumn() >= 3) {
            throw new OverflowException('Puedes tener hasta tres salas activas.');
        }
        if (isset($metadata['metadata'])) {
            $st = $db->prepare('SELECT duracion FROM publicacion_medios WHERE publicacion_id=? FOR UPDATE');
            $st->execute([$pub]);
            $existing = $st->fetch();
            if (!$existing) {
                guardarMetadatosMedio($pub, $metadata['metadata']);
            } elseif ($existing['duracion'] === null) {
                $db->prepare('UPDATE publicacion_medios SET duracion=? WHERE publicacion_id=?')->execute([$metadata['duracion'],$pub]);
            }
        }
        $db->prepare('INSERT INTO salas_escucha(anfitrion_id,publicacion_id,nombre,codigo) VALUES(?,?,?,?)')->execute([$uid,$pub,$name,bin2hex(random_bytes(16))]);
        $id = (int)$db->lastInsertId();
        $db->prepare('INSERT INTO musicmad_room_state(sala_id,modo,actualizada_utc,expira_en) VALUES(?,?,UTC_TIMESTAMP(3),UTC_TIMESTAMP()+INTERVAL 1 DAY)')->execute([$id,$mode]);
        $db->prepare('INSERT INTO salas_integrantes(sala_id,usuario_id,visto_en) VALUES(?,?,UTC_TIMESTAMP())')->execute([$id,$uid]);
        $db->commit();
        return $id;
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $error;
    }
}
function entrarSalaEscucha(int $uid, int $id, string $code = ''): void
{
    transaccionSala($uid, $id, function (PDO $db, array $row) use ($uid, $id, $code) {
        if (integranteSala($uid, $id)) {
            return;
        }
        if ($row['modo'] === 'enlace' && !hash_equals($row['codigo'], $code)) {
            throw new OutOfBoundsException('Necesitas el enlace de invitación para entrar.');
        }
        $st = $db->prepare('SELECT COUNT(*) FROM salas_integrantes WHERE sala_id=?');
        $st->execute([$id]);
        if ((int)$st->fetchColumn() >= 30) {
            throw new OverflowException('Esta sala tiene treinta integrantes.');
        }
        limiteChat($uid, 'sala_entrar', 10);
        $db->prepare('INSERT INTO salas_integrantes(sala_id,usuario_id,visto_en) VALUES(?,?,UTC_TIMESTAMP())')->execute([$id,$uid]);
    });
}
function posicionSalaActual(array $row, float $now): float
{
    $updated = (float)(DateTimeImmutable::createFromFormat('Y-m-d H:i:s.u', str_pad($row['actualizada_utc'], 26, '0'), new DateTimeZone('UTC')) ?: new DateTimeImmutable($row['actualizada_utc'], new DateTimeZone('UTC')))->format('U.u');
    $pos = (float)$row['posicion'];
    if ($row['reproduccion'] === 'reproduciendo') {
        $pos += max(0, $now - $updated);
    }
    return min((float)$row['duracion'], max(0, $pos));
}
function controlarSalaEscucha(int $uid, int $id, int $revision, string $state, float $pos): int
{
    if (!in_array($state, ['pausada','reproduciendo'], true) || !is_finite($pos) || $pos < 0) {
        throw new InvalidArgumentException('Control de reproducción no válido.');
    }
    return transaccionSala($uid, $id, function (PDO $db, array $row) use ($uid, $id, $revision, $state, $pos) {
        if ((int)$row['anfitrion_id'] !== $uid) {
            throw new OutOfBoundsException('Solo el anfitrión controla esta sala.');
        }
        if ((int)$row['revision'] !== $revision) {
            throw new UnexpectedValueException('La reproducción cambió. Actualiza la sala.');
        }
        if ($pos > (float)$row['duracion']) {
            throw new InvalidArgumentException('Ese momento está fuera de la pista.');
        }
        limiteChat($uid, 'sala_control', 60);
        $db->prepare('UPDATE musicmad_room_state SET reproduccion=?,posicion=?,revision=revision+1,actualizada_utc=UTC_TIMESTAMP(3) WHERE sala_id=?')->execute([$state,$pos,$id]);
        $db->prepare('UPDATE salas_escucha SET posicion_segundos=?,actualizada_en=UTC_TIMESTAMP() WHERE id=?')->execute([(int)$pos,$id]);
        return (int)$row['revision'] + 1;
    });
}
function cerrarOSalirSala(int $uid, int $id, bool $close): void
{
    transaccionSala($uid, $id, function (PDO $db, array $row) use ($uid, $id, $close) {
        exigirIntegranteSala($uid, $id);
        if ($close) {
            if ((int)$row['anfitrion_id'] !== $uid) {
                throw new OutOfBoundsException('Solo el anfitrión puede cerrar esta sala.');
            }
            $db->prepare("UPDATE salas_escucha SET estado='cerrada' WHERE id=?")->execute([$id]);
            $db->prepare("UPDATE musicmad_room_state SET reproduccion='pausada',revision=revision+1 WHERE sala_id=?")->execute([$id]);
        } else {
            if ((int)$row['anfitrion_id'] === $uid) {
                throw new LogicException('Como anfitrión, termina la sala para salir.');
            }
            $db->prepare('DELETE FROM salas_integrantes WHERE sala_id=? AND usuario_id=?')->execute([$id,$uid]);
        }
    });
}
function enviarMensajeSala(int $uid, int $id, string $text, string $request): int
{
    $text = trim($text);
    if ($text === '' || mb_strlen($text) > 500 || !preg_match('/^[a-f0-9]{32}$/D', $request)) {
        throw new InvalidArgumentException('Revisa el mensaje.');
    }
    $hash = hash('sha256', $text);
    return transaccionSala($uid, $id, function (PDO $db) use ($uid, $id, $text, $request, $hash) {
        exigirIntegranteSala($uid, $id);
        $st = $db->prepare('SELECT mensaje_id,payload_hash FROM musicmad_room_receipts WHERE sala_id=? AND usuario_id=? AND solicitud=?');
        $st->execute([$id,$uid,$request]);
        $receipt = $st->fetch();
        if ($receipt) {
            if (!hash_equals($receipt['payload_hash'], $hash)) {
                throw new UnexpectedValueException('Ese envío ya se usó para otro mensaje.');
            }
            return (int)$receipt['mensaje_id'];
        }
        limiteChat($uid, 'sala_chat', 30);
        $db->prepare('INSERT INTO musicmad_room_messages(sala_id,usuario_id,texto,created_at) VALUES(?,?,?,UTC_TIMESTAMP())')->execute([$id,$uid,$text]);
        $msg = (int)$db->lastInsertId();
        $db->prepare('INSERT INTO musicmad_room_receipts(sala_id,usuario_id,solicitud,payload_hash,mensaje_id) VALUES(?,?,?,?,?)')->execute([$id,$uid,$request,$hash,$msg]);
        return $msg;
    });
}
function estadoSalaEscucha(int $uid, int $id, int $after = 0, int $before = 0): array
{
    return transaccionSala($uid, $id, function (PDO $db, array $row) use ($uid, $id, $after, $before) {
        exigirIntegranteSala($uid, $id);
        limiteChat($uid, 'sala_leer', 60);
        $db->prepare('UPDATE salas_integrantes SET visto_en=UTC_TIMESTAMP() WHERE sala_id=? AND usuario_id=?')->execute([$id,$uid]);
        [$block,$params] = filtroBloqueoInteraccion($uid, 'u.id', 'room_messages');
        $cursor = $before > 0 ? 'm.id<:cursor' : ($after > 0 ? 'm.id>:cursor' : '1=1');
        $order = $before > 0 || $after === 0 ? 'DESC' : 'ASC';
        $st = $db->prepare("SELECT m.id,m.usuario_id,m.texto,m.created_at,CONCAT(u.nombre,' ',u.apellido) AS autor FROM musicmad_room_messages m JOIN usuarios u ON u.id=m.usuario_id AND u.activo=1 WHERE m.sala_id=:sala AND " . $cursor . ' AND ' . $block . ' ORDER BY m.id ' . $order . ' LIMIT 50');
        $st->execute([':sala' => $id] + ($after > 0 || $before > 0 ? [':cursor' => $before > 0 ? $before : $after] : []) + $params);
        $messages = $st->fetchAll();
        if ($order === 'DESC') {
            $messages = array_reverse($messages);
        }
        foreach ($messages as &$msg) {
            $msg['id'] = (int)$msg['id'];
            $msg['usuario_id'] = (int)$msg['usuario_id'];
        }
        unset($msg);
        [$block,$params] = filtroBloqueoInteraccion($uid, 'u.id', 'room_members');
        $st = $db->prepare('SELECT u.id,CONCAT(u.nombre,\' \',u.apellido) AS nombre FROM salas_integrantes m JOIN usuarios u ON u.id=m.usuario_id AND u.activo=1 WHERE m.sala_id=:sala AND m.visto_en>UTC_TIMESTAMP()-INTERVAL 90 SECOND AND ' . $block . ' ORDER BY u.id LIMIT 30');
        $st->execute([':sala' => $id] + $params);
        $members = $st->fetchAll();
        foreach ($members as &$member) {
            $member['id'] = (int)$member['id'];
        }
        unset($member);
        $now = microtime(true);
        $position = posicionSalaActual($row, $now);
        return ['id' => $id,'nombre' => $row['nombre'],'anfitrion_id' => (int)$row['anfitrion_id'],'pista' => ['id' => (int)$row['publicacion_id'],'titulo' => $row['titulo'],'artista' => $row['artista'],'duracion' => (float)$row['duracion']],'revision' => (int)$row['revision'],'reproduccion' => $position >= (float)$row['duracion'] ? 'pausada' : $row['reproduccion'],'posicion' => $position,'servidor_ms' => (int)round($now * 1000),'mensajes' => $messages,'integrantes' => $members,'hasta' => $messages ? (int)end($messages)['id'] : $after];
    });
}
function listarSalasEscucha(int $uid): array
{
    if (!funcionalidadMusicMad('salas', $uid)) {
        return [];
    }
    if (!salasEscuchaDisponibles()) {
        return [];
    }[$access,$params] = filtroAccesoContenido($uid, 'p.usuario_id', 'room_list_track');
    [$block,$bp] = filtroBloqueoInteraccion($uid, 'h.id', 'room_list_host');
    $st = getDB()->prepare("SELECT s.id,s.nombre,s.publicacion_id,s.anfitrion_id,p.titulo,CONCAT(h.nombre,' ',h.apellido) AS anfitrion,r.modo FROM salas_escucha s JOIN musicmad_room_state r ON r.sala_id=s.id JOIN usuarios h ON h.id=s.anfitrion_id AND h.activo=1 JOIN publicaciones p ON p.id=s.publicacion_id JOIN usuarios a ON a.id=p.usuario_id AND a.activo=1 WHERE s.estado='activa' AND r.expira_en>UTC_TIMESTAMP() AND (r.modo='publica' OR s.anfitrion_id=:host OR EXISTS(SELECT 1 FROM salas_integrantes mi WHERE mi.sala_id=s.id AND mi.usuario_id=:member)) AND " . $access . ' AND ' . $block . ' ORDER BY s.id DESC LIMIT 50');
    $st->execute([':host' => $uid,':member' => $uid] + $params + $bp);
    return $st->fetchAll();
}


/** Ends expired rooms promptly; removes only room/chat rows after seven days. */
function limpiarSalasEscucha(): array
{
    if (!salasEscuchaDisponibles()) {
        return ['cerradas' => 0,'eliminadas' => 0];
    }
    $db = getDB();
    $expired = $db->query("SELECT s.id FROM salas_escucha s JOIN musicmad_room_state r ON r.sala_id=s.id WHERE s.estado='activa' AND r.expira_en<=UTC_TIMESTAMP() ORDER BY r.expira_en LIMIT 100")->fetchAll(PDO::FETCH_COLUMN);
    $closed = 0;
    foreach ($expired as $id) {
        $st = $db->prepare("UPDATE salas_escucha s JOIN musicmad_room_state r ON r.sala_id=s.id SET s.estado='cerrada',r.reproduccion='pausada' WHERE s.id=? AND s.estado='activa' AND r.expira_en<=UTC_TIMESTAMP()");
        $st->execute([(int)$id]);
        $closed += $st->rowCount();
    }
    $st = $db->query("SELECT sala_id FROM musicmad_room_state WHERE expira_en<UTC_TIMESTAMP()-INTERVAL 7 DAY ORDER BY expira_en LIMIT 100");
    $ids = $st->fetchAll(PDO::FETCH_COLUMN);
    $removed = 0;
    foreach ($ids as $id) {
        $delete = $db->prepare("DELETE s FROM salas_escucha s JOIN musicmad_room_state r ON r.sala_id=s.id WHERE s.id=? AND r.expira_en<UTC_TIMESTAMP()-INTERVAL 7 DAY");
        $delete->execute([(int)$id]);
        $removed += $delete->rowCount();
    }
    return ['cerradas' => $closed,'eliminadas' => $removed];
}
