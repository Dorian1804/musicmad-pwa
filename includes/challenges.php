<?php

/** Weekly prompts are community activities, without purchases or monetary prizes. */
function retosDisponibles(): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    $st = getDB()->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN (?,?,?,?)');
    $st->execute(['musicmad_challenge_details','musicmad_challenge_tracks','musicmad_challenge_receipts','musicmad_challenge_schedule']);
    return $ready = (int)$st->fetchColumn() === 4;
}
function faseReto(array $row): string
{
    if ($row['cancelado']) {
        return 'cancelado';
    }
    if ($row['estado'] === 'borrador') {
        return 'borrador';
    }
    $now = gmdate('Y-m-d H:i:s');
    if ($row['estado'] === 'cerrado' || $now >= $row['fin']) {
        return 'resultados';
    }
    if ($now < $row['inicio']) {
        return 'proximo';
    }
    return $now < $row['votacion_desde'] ? 'participacion' : 'votacion';
}
function retoPara(int $uid, int $id): ?array
{
    if (!retosDisponibles()) {
        return null;
    }
    $st = getDB()->prepare('SELECT r.*,d.votacion_desde,d.cancelado,d.semana FROM retos_musicales r JOIN musicmad_challenge_details d ON d.reto_id=r.id WHERE r.id=? AND EXISTS(SELECT 1 FROM usuarios WHERE id=? AND activo=1)');
    $st->execute([$id,$uid]);
    $row = $st->fetch();
    if (!$row || ($row['estado'] === 'borrador' && !puedeModerar($uid))) {
        return null;
    }
    $row['fase'] = faseReto($row);
    return $row;
}
function transaccionReto(int $uid, int $id, callable $operation): mixed
{
    if (!retosDisponibles()) {
        throw new RuntimeException('Retos no instalados.');
    }
    $db = getDB();
    $own = !$db->inTransaction();
    if ($own) {
        $db->beginTransaction();
    }
    try {
        $st = $db->prepare('SELECT id FROM usuarios WHERE id=? AND activo=1 FOR UPDATE');
        $st->execute([$uid]);
        if (!$st->fetchColumn()) {
            throw new OutOfBoundsException('Cuenta no disponible.');
        }
        if ($id) {
            $st = $db->prepare('SELECT id FROM retos_musicales WHERE id=? FOR UPDATE');
            $st->execute([$id]);
            if (!$st->fetchColumn() || !($row = retoPara($uid, $id))) {
                throw new OutOfBoundsException('Reto no disponible.');
            }
        } else {
            $row = [];
        }
        $result = $operation($db, $row);
        if ($own) {
            $db->commit();
        }
        return $result;
    } catch (Throwable $e) {
        if ($own && $db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}
function reciboReto(PDO $db, int $uid, string $request, string $hash): ?int
{
    if (!preg_match('/^[a-f0-9]{32}$/D', $request)) {
        throw new InvalidArgumentException('Vuelve a abrir el formulario.');
    }
    $st = $db->prepare('SELECT reto_id,payload_hash FROM musicmad_challenge_receipts WHERE usuario_id=? AND solicitud=?');
    $st->execute([$uid,$request]);
    $old = $st->fetch();
    if (!$old) {
        return null;
    }
    if (!hash_equals($old['payload_hash'], $hash)) {
        throw new UnexpectedValueException('Ese envío ya tiene otro contenido.');
    }
    return (int)$old['reto_id'];
}
function guardarReciboReto(PDO $db, int $uid, string $request, string $hash, int $id): void
{
    $db->prepare('INSERT INTO musicmad_challenge_receipts(usuario_id,solicitud,payload_hash,reto_id,created_at) VALUES(?,?,?,?,UTC_TIMESTAMP())')->execute([$uid,$request,$hash,$id]);
}
function fechaRetoEntrada(string $value): string
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value, new DateTimeZone('UTC'));
    if (!$date || $date->format('Y-m-d\TH:i') !== $value) {
        throw new InvalidArgumentException('Revisa las fechas UTC.');
    }
    return $date->format('Y-m-d H:i:s');
}
function crearReto(int $uid, array $data, string $request): int
{
    $title = trim(textoCampo($data, 'titulo'));
    $description = trim(textoCampo($data, 'descripcion'));
    $start = fechaRetoEntrada(textoCampo($data, 'inicio'));
    $vote = fechaRetoEntrada(textoCampo($data, 'votacion'));
    $end = fechaRetoEntrada(textoCampo($data, 'fin'));
    $state = (textoCampo($data, 'estado') ?: 'activo');
    if (mb_strlen($title) < 3 || mb_strlen($title) > 120 || mb_strlen($description) > 500 || !in_array($state, ['activo','borrador'], true) || $start >= $vote || $vote >= $end || strtotime($end . ' UTC') - strtotime($start . ' UTC') > 28 * 86400) {
        throw new InvalidArgumentException('Usa un título y fechas futuras: entrega, votación y cierre, hasta 28 días.');
    }
    $hash = hash('sha256', json_encode(['crear',$title,$description,$start,$vote,$end,$state]));
    return transaccionReto($uid, 0, function (PDO $db) use ($uid, $request, $hash, $title, $description, $start, $vote, $end, $state) {
        if (!puedeModerar($uid)) {
            throw new OutOfBoundsException('Solo el equipo organiza retos.');
        }
        if ($old = reciboReto($db, $uid, $request, $hash)) {
            return $old;
        }
        if (strtotime($start . ' UTC') < time() - 300) {
            throw new InvalidArgumentException('El inicio debe ser futuro.');
        }
        limiteChat($uid, 'challenge_create', 5);
        $db->prepare('INSERT INTO retos_musicales(creado_por,titulo,descripcion,inicio,fin,estado) VALUES(?,?,?,?,?,?)')->execute([$uid,$title,$description,$start,$end,$state]);
        $id = (int)$db->lastInsertId();
        $db->prepare('INSERT INTO musicmad_challenge_details(reto_id,votacion_desde) VALUES(?,?)')->execute([$id,$vote]);
        guardarReciboReto($db, $uid, $request, $hash, $id);
        return $id;
    });
}
function pistaPublicaReto(int $uid, int $pub): array
{
    $st = getDB()->prepare("SELECT p.usuario_id,m.sha256,m.duracion FROM publicaciones p JOIN usuarios u ON u.id=p.usuario_id AND u.activo=1 JOIN publicacion_medios m ON m.publicacion_id=p.id LEFT JOIN perfiles_privacidad pp ON pp.usuario_id=u.id WHERE p.id=? AND COALESCE(pp.visibilidad,'publico')='publico' AND EXISTS(SELECT 1 FROM publicacion_audio a WHERE a.publicacion_id=p.id UNION ALL SELECT 1 FROM publicacion_video v WHERE v.publicacion_id=p.id)");
    $st->execute([$pub]);
    $row = $st->fetch();
    if (!$row || (int)$row['usuario_id'] !== $uid || (float)$row['duracion'] <= 0 || (float)$row['duracion'] > 180) {
        throw new OutOfBoundsException('Elige un audio o video tuyo de hasta tres minutos, con perfil público.');
    }
    medioParaEstadisticas($uid, $pub);
    return $row;
}
function participarReto(int $uid, int $id, int $pub, string $request): void
{
    $hash = hash('sha256', json_encode(['participar',$id,$pub]));
    transaccionReto($uid, $id, function (PDO $db, array $row) use ($uid, $id, $pub, $request, $hash) {
        if (reciboReto($db, $uid, $request, $hash)) {
            return;
        }
        if ($row['fase'] !== 'participacion') {
            throw new LogicException('La entrega de pistas no está abierta.');
        }
        limiteChat($uid, 'challenge_entry', 12);
        $st = $db->prepare('SELECT id FROM publicaciones WHERE id=? FOR UPDATE');
        $st->execute([$pub]);
        if (!$st->fetchColumn()) {
            throw new OutOfBoundsException('Pista no disponible.');
        }
        $media = pistaPublicaReto($uid, $pub);
        $st = $db->prepare('SELECT COUNT(*) FROM reto_participaciones WHERE reto_id=?');
        $st->execute([$id]);
        $existing = $db->prepare('SELECT 1 FROM reto_participaciones WHERE reto_id=? AND usuario_id=?');
        $existing->execute([$id,$uid]);
        if ((int)$st->fetchColumn() >= 250 && !$existing->fetchColumn()) {
            throw new OverflowException('Este reto ya tiene 250 participantes.');
        }
        $db->prepare('INSERT INTO reto_participaciones(reto_id,usuario_id,publicacion_id,created_at) VALUES(?,?,?,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE publicacion_id=VALUES(publicacion_id),created_at=UTC_TIMESTAMP()')->execute([$id,$uid,$pub]);
        $db->prepare('INSERT INTO musicmad_challenge_tracks(reto_id,usuario_id,sha256) VALUES(?,?,?) ON DUPLICATE KEY UPDATE sha256=VALUES(sha256)')->execute([$id,$uid,$media['sha256']]);
        $db->prepare('DELETE FROM reto_votos WHERE reto_id=? AND participante_id=?')->execute([$id,$uid]);
        guardarReciboReto($db, $uid, $request, $hash, $id);
    });
}
function rankingReto(int $uid, int $id, int $page = 1, ?bool &$more = null): array
{
    $more = false;
    $row = retoPara($uid, $id);
    if (!$row || in_array($row['fase'], ['borrador','cancelado'], true)) {
        return [];
    }
    $page = max(1, min(9, $page));
    [$access,$args] = filtroAccesoContenido($uid, 'p.usuario_id', 'challenge_rank');
    $args[':reto'] = $id;
    $sql = "SELECT rp.usuario_id,rp.publicacion_id,rp.created_at,p.titulo,CONCAT(u.nombre,' ',u.apellido) AS artista,m.duracion,IF(a.publicacion_id IS NULL,'video','audio') AS medio,(SELECT COUNT(*) FROM reto_votos rv JOIN usuarios vu ON vu.id=rv.votante_id AND vu.activo=1 WHERE rv.reto_id=rp.reto_id AND rv.participante_id=rp.usuario_id AND rv.votante_id<>rp.usuario_id AND NOT EXISTS(SELECT 1 FROM usuarios_bloqueados vb WHERE vb.modo='bloquear' AND ((vb.usuario_id=rv.votante_id AND vb.objetivo_id=rp.usuario_id) OR (vb.usuario_id=rp.usuario_id AND vb.objetivo_id=rv.votante_id)))) AS votos FROM reto_participaciones rp JOIN musicmad_challenge_tracks ct ON ct.reto_id=rp.reto_id AND ct.usuario_id=rp.usuario_id JOIN publicaciones p ON p.id=rp.publicacion_id AND p.usuario_id=rp.usuario_id JOIN usuarios u ON u.id=p.usuario_id AND u.activo=1 JOIN publicacion_medios m ON m.publicacion_id=p.id AND m.sha256=ct.sha256 LEFT JOIN publicacion_audio a ON a.publicacion_id=p.id LEFT JOIN publicacion_video v ON v.publicacion_id=p.id LEFT JOIN perfiles_privacidad pp ON pp.usuario_id=u.id WHERE rp.reto_id=:reto AND COALESCE(pp.visibilidad,'publico')='publico' AND m.duracion>0 AND m.duracion<=180 AND (a.publicacion_id IS NOT NULL OR v.publicacion_id IS NOT NULL) AND (" . $access . ') ORDER BY votos DESC,rp.created_at ASC,rp.usuario_id ASC LIMIT 30 OFFSET ' . (($page - 1) * 30);
    $st = getDB()->prepare($sql);
    $st->execute($args);
    $rows = $st->fetchAll();
    $more = count($rows) === 30 && $page < 9;
    return array_values(array_filter($rows, function ($entry) {
        try {
            $meta = pistaPublicaReto((int)$entry['usuario_id'], (int)$entry['publicacion_id']);
            return true;
        } catch (OutOfBoundsException $e) {
            return false;
        }
    }));
}
function miVotoReto(int $uid, int $id): int
{
    $st = getDB()->prepare('SELECT participante_id FROM reto_votos WHERE reto_id=? AND votante_id=?');
    $st->execute([$id,$uid]);
    return (int)$st->fetchColumn();
}
function votarReto(int $uid, int $id, int $participant, string $request): void
{
    if ($participant === $uid) {
        throw new InvalidArgumentException('Tu voto debe ser para otro músico.');
    }
    $hash = hash('sha256', json_encode(['votar',$id,$participant]));
    transaccionReto($uid, $id, function (PDO $db, array $row) use ($uid, $id, $participant, $request, $hash) {
        if (reciboReto($db, $uid, $request, $hash)) {
            return;
        }
        if ($row['fase'] !== 'votacion') {
            throw new LogicException('La votación no está abierta.');
        }
        limiteChat($uid, 'challenge_vote', 20);
        if ($participant === 0) {
            $db->prepare('DELETE FROM reto_votos WHERE reto_id=? AND votante_id=?')->execute([$id,$uid]);
        } else {
            $st = $db->prepare('SELECT rp.publicacion_id,ct.sha256 FROM reto_participaciones rp JOIN musicmad_challenge_tracks ct ON ct.reto_id=rp.reto_id AND ct.usuario_id=rp.usuario_id WHERE rp.reto_id=? AND rp.usuario_id=? FOR UPDATE');
            $st->execute([$id,$participant]);
            $entry = $st->fetch();
            if (!$entry || !puedeVerPublicacionSocial3($uid, (int)$entry['publicacion_id'])) {
                throw new OutOfBoundsException('Participación no disponible.');
            }
            $media = pistaPublicaReto($participant, (int)$entry['publicacion_id']);
            if (!hash_equals($media['sha256'], $entry['sha256'])) {
                throw new OutOfBoundsException('La pista cambió.');
            }
            $db->prepare('INSERT INTO reto_votos(reto_id,votante_id,participante_id,created_at) VALUES(?,?,?,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE participante_id=VALUES(participante_id),created_at=UTC_TIMESTAMP()')->execute([$id,$uid,$participant]);
        }
        guardarReciboReto($db, $uid, $request, $hash, $id);
    });
}
function retirarParticipacionReto(int $uid, int $id, string $request): void
{
    $hash = hash('sha256', json_encode(['retirar',$id]));
    transaccionReto($uid, $id, function (PDO $db) use ($uid, $id, $request, $hash) {
        if (reciboReto($db, $uid, $request, $hash)) {
            return;
        }
        limiteChat($uid, 'challenge_entry', 12);
        $db->prepare('DELETE FROM reto_votos WHERE reto_id=? AND participante_id=?')->execute([$id,$uid]);
        $db->prepare('DELETE FROM reto_participaciones WHERE reto_id=? AND usuario_id=?')->execute([$id,$uid]);
        guardarReciboReto($db, $uid, $request, $hash, $id);
    });
}
function administrarReto(int $uid, int $id, string $action): void
{
    transaccionReto($uid, $id, function (PDO $db, array $row) use ($uid, $id, $action) {
        if (!puedeModerar($uid)) {
            throw new OutOfBoundsException('Solo el equipo administra retos.');
        }
        if ($action === 'cancelar') {
            $db->prepare('UPDATE musicmad_challenge_details SET cancelado=1 WHERE reto_id=?')->execute([$id]);
        } elseif ($action === 'publicar' && $row['estado'] === 'borrador' && $row['fin'] > gmdate('Y-m-d H:i:s')) {
            $db->prepare("UPDATE retos_musicales SET estado='activo' WHERE id=?")->execute([$id]);
        } else {
            throw new InvalidArgumentException('Acción no válida.');
        }
    });
}
function listarRetos(int $uid): array
{
    if (!retosDisponibles()) {
        return [];
    }
    $st = getDB()->query("SELECT id FROM retos_musicales r JOIN musicmad_challenge_details d ON d.reto_id=r.id WHERE d.cancelado=0 ORDER BY (r.fin>UTC_TIMESTAMP()) DESC,r.inicio DESC LIMIT 40");
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) {
        if ($row = retoPara($uid, (int)$id)) {
            $out[] = $row;
        }
    }
    return $out;
}
function configurarRetosSemanales(int $uid, bool $enable): void
{
    transaccionReto($uid, 0, function (PDO $db) use ($uid, $enable) {
        if (!puedeModerar($uid)) {
            throw new OutOfBoundsException('Solo el equipo programa retos.');
        }
        $db->prepare('UPDATE musicmad_challenge_schedule SET habilitado=?,responsable_id=? WHERE id=1')->execute([(int)$enable,$enable ? $uid : null]);
    });
}
function generarRetoSemanal(): int
{
    if (!retosDisponibles()) {
        return 0;
    }
    $db = getDB();
    $name = 'mm_challenge_' . substr(hash('sha256', DB_NAME), 0, 16);
    $st = $db->prepare('SELECT GET_LOCK(?,0)');
    $st->execute([$name]);
    if ((int)$st->fetchColumn() !== 1) {
        return 0;
    }
    try {
        $settings = $db->query('SELECT * FROM musicmad_challenge_schedule WHERE id=1')->fetch();
        $uid = (int)($settings['responsable_id'] ?? 0);
        if (!$settings || !$settings['habilitado'] || !puedeModerar($uid)) {
            return 0;
        }
        return transaccionReto($uid, 0, function (PDO $db) use ($uid) {
            $st = $db->query('SELECT * FROM musicmad_challenge_schedule WHERE id=1 FOR UPDATE');
            $settings = $st->fetch();
            if (!$settings['habilitado'] || (int)$settings['responsable_id'] !== $uid || !puedeModerar($uid)) {
                return 0;
            }
            $start = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('monday this week')->setTime(0, 0);
            $week = $start->format('Y-m-d');
            $st = $db->prepare('SELECT reto_id FROM musicmad_challenge_details WHERE semana=?');
            $st->execute([$week]);
            if ($st->fetchColumn()) {
                return 0;
            }
            $prompts = [['Tres notas, muchas ideas','Crea un riff o melodía usando solo tres notas. Hasta tres minutos, audio o video.'],['Una canción desde el silencio','Empieza con un silencio y construye una idea musical propia.'],['Ritmo en un objeto','Crea una base con un objeto cotidiano y añade tu instrumento.'],['Un acorde, otra historia','Construye una pieza original alrededor de un solo acorde.'],['Respuesta musical','Graba dos frases: una pregunta y una respuesta.'],['Música para amanecer','Comparte una idea original que acompañe un amanecer.'],['Contraste de texturas','Combina dos sonidos distintos en una pieza corta.'],['Tu ciudad suena','Crea una pista inspirada en tu ciudad, sin revelar ubicaciones privadas.']];
            $prompt = $prompts[(int)$start->format('W') % count($prompts)];
            $db->prepare("INSERT INTO retos_musicales(creado_por,titulo,descripcion,inicio,fin,estado) VALUES(?,?,?,?,?,'activo')")->execute([$uid,$prompt[0],$prompt[1],$start->format('Y-m-d H:i:s'),$start->modify('+7 days')->format('Y-m-d H:i:s')]);
            $id = (int)$db->lastInsertId();
            $db->prepare('INSERT INTO musicmad_challenge_details(reto_id,votacion_desde,semana) VALUES(?,?,?)')->execute([$id,$start->modify('+6 days')->format('Y-m-d H:i:s'),$week]);
            return $id;
        });
    } finally {
        $st = $db->prepare('SELECT RELEASE_LOCK(?)');
        $st->execute([$name]);
    }
}


function limpiarRecibosRetos(): void
{
    if (retosDisponibles()) {
        getDB()->exec('DELETE FROM musicmad_challenge_receipts WHERE created_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 90 DAY) LIMIT 1000');
    }
}
