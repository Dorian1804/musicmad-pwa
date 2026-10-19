<?php

function estudioCreativoDisponible(): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    $tables = ['musicmad_collab_details','musicmad_stem_tracks','musicmad_collab_receipts','musicmad_clip_sources','musicmad_clip_receipts'];
    $st = getDB()->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN (?,?,?,?,?)');
    $st->execute($tables);
    $count = (int)$st->fetchColumn();
    if ($count === 0) {
        return $ready = false;
    }
    return $ready = $count === 5;
}
function colabDisponiblePara(int $uid, int $id): ?array
{
    if (!funcionalidadMusicMad('estudio', $uid)) {
        return null;
    }
    if ($uid < 1 || !estudioCreativoDisponible()) {
        return null;
    }
    $st = getDB()->prepare("SELECT c.*,d.archivada,d.final_publicacion_id,p.titulo AS pista_titulo,CONCAT(u.nombre,' ',u.apellido) AS artista FROM colaboraciones c JOIN musicmad_collab_details d ON d.colaboracion_id=c.id JOIN usuarios u ON u.id=c.usuario_id AND u.activo=1 JOIN publicaciones p ON p.id=c.pista_base_id WHERE c.id=? AND d.archivada=0 AND EXISTS(SELECT 1 FROM usuarios viewer WHERE viewer.id=? AND viewer.activo=1)");
    $st->execute([$id,$uid]);
    $row = $st->fetch();
    if (!$row || !puedeVerPublicacionSocial3($uid, (int)$row['pista_base_id']) || !puedeVerPerfilSocial3($uid, (int)$row['usuario_id'])) {
        return null;
    }
    return $row;
}
function transaccionColab(int $uid, int $id, callable $operation): mixed
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
            throw new OutOfBoundsException('Cuenta no disponible.');
        }
        $st = $db->prepare('SELECT id FROM colaboraciones WHERE id=? FOR UPDATE');
        $st->execute([$id]);
        $row = $st->fetchColumn() ? colabDisponiblePara($uid, $id) : null;
        if (!$row) {
            throw new OutOfBoundsException('Colaboración no disponible.');
        }
        $result = $operation($db, $row);
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
function crearColaboracion(int $uid, array $data): int
{
    exigirFuncionMusicMad('estudio', $uid);
    if (!estudioCreativoDisponible()) {
        throw new RuntimeException('Estudio no disponible.');
    }
    $title = trim(textoCampo($data, 'titulo'));
    $description = trim(textoCampo($data, 'descripcion'));
    $pub = idCampo($data, 'pista');
    if (mb_strlen($title) < 2 || mb_strlen($title) > 120 || mb_strlen($description) > 700) {
        throw new InvalidArgumentException('Revisa el título y descripción.');
    }
    metadatosParaSala($uid, $pub);
    limiteChat($uid, 'colab_crear', 5);
    $db = getDB();
    $owns = !$db->inTransaction();
    if ($owns) {
        $db->beginTransaction();
    }
    try {
        $st = $db->prepare('SELECT id FROM usuarios WHERE id=? AND activo=1 FOR UPDATE');
        $st->execute([$uid]);
        if (!$st->fetchColumn()) {
            throw new OutOfBoundsException('Cuenta no disponible.');
        }
        $st = $db->prepare('SELECT usuario_id FROM publicaciones WHERE id=? FOR UPDATE');
        $st->execute([$pub]);
        if ((int)$st->fetchColumn() !== $uid) {
            throw new OutOfBoundsException('La pista base debe ser tuya.');
        }
        $db->prepare('INSERT INTO colaboraciones(usuario_id,titulo,descripcion,pista_base_id) VALUES(?,?,?,?)')->execute([$uid,$title,$description,$pub]);
        $id = (int)$db->lastInsertId();
        $db->prepare('INSERT INTO musicmad_collab_details(colaboracion_id) VALUES(?)')->execute([$id]);
        if ($owns) {
            $db->commit();
        }
        return $id;
    } catch (Throwable $e) {
        if ($owns && $db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}
function aportarStemColab(int $uid, int $id, array $data, string $request): int
{
    $pub = idCampo($data, 'pista');
    $parent = idCampo($data, 'version_de');
    $title = trim(textoCampo($data, 'titulo'));
    $offset = filter_var($data['offset_ms'] ?? 0, FILTER_VALIDATE_INT);
    $volume = filter_var($data['volumen'] ?? 70, FILTER_VALIDATE_INT);
    if (!preg_match('/^[a-f0-9]{32}$/D', $request) || mb_strlen($title) < 1 || mb_strlen($title) > 120 || $offset === false || abs($offset) > 30000 || $volume === false || $volume < 0 || $volume > 100) {
        throw new InvalidArgumentException('Revisa tu aporte y su sincronización.');
    }
    $meta = metadatosParaSala($uid, $pub);
    $hash = hash('sha256', json_encode([$pub,$parent,$title,$offset,$volume], JSON_THROW_ON_ERROR));
    return transaccionColab($uid, $id, function (PDO $db, array $row) use ($uid, $id, $pub, $parent, $title, $offset, $volume, $meta, $hash, $request) {
        $st = $db->prepare('SELECT payload_hash,stem_id FROM musicmad_collab_receipts WHERE colaboracion_id=? AND usuario_id=? AND solicitud=?');
        $st->execute([$id,$uid,$request]);
        $old = $st->fetch();
        if ($old) {
            if (!hash_equals($old['payload_hash'], $hash)) {
                throw new UnexpectedValueException('Ese envío ya tiene otro contenido.');
            }
            return (int)$old['stem_id'];
        }
        if ($row['estado'] !== 'abierta') {
            throw new LogicException('Esta colaboración ya no recibe aportes.');
        }
        $st = $db->prepare('SELECT usuario_id FROM publicaciones WHERE id=? FOR UPDATE');
        $st->execute([$pub]);
        if ((int)$st->fetchColumn() !== $uid || !puedeVerPublicacionSocial3((int)$row['usuario_id'], $pub)) {
            throw new OutOfBoundsException('Elige una pista tuya que el creador pueda escuchar.');
        }
        if ($pub === (int)$row['pista_base_id']) {
            throw new InvalidArgumentException('El aporte debe ser distinto de la pista base.');
        }
        if ($parent) {
            $st = $db->prepare('SELECT 1 FROM colaboracion_stems s JOIN musicmad_stem_tracks t ON t.stem_id=s.id WHERE s.id=? AND s.colaboracion_id=? AND s.usuario_id=?');
            $st->execute([$parent,$id,$uid]);
            if (!$st->fetchColumn()) {
                throw new OutOfBoundsException('La versión anterior debe ser un aporte tuyo de este proyecto.');
            }
        }
        $st = $db->prepare('SELECT COUNT(*) FROM colaboracion_stems WHERE colaboracion_id=?');
        $st->execute([$id]);
        if ((int)$st->fetchColumn() >= 40) {
            throw new OverflowException('Este proyecto tiene cuarenta aportes.');
        }
        limiteChat($uid, 'colab_aportar', 10);
        $db->prepare('INSERT INTO colaboracion_stems(colaboracion_id,usuario_id,archivo,titulo) VALUES(?,?,?,?)')->execute([$id,$uid,$meta['archivo'],$title]);
        $stem = (int)$db->lastInsertId();
        $db->prepare('INSERT INTO musicmad_stem_tracks(stem_id,publicacion_id,version_de,offset_ms,volumen) VALUES(?,?,?,?,?)')->execute([$stem,$pub,$parent ?: null,$offset,$volume]);
        $db->prepare('INSERT INTO musicmad_collab_receipts(colaboracion_id,usuario_id,solicitud,payload_hash,stem_id) VALUES(?,?,?,?,?)')->execute([$id,$uid,$request,$hash,$stem]);
        solicitarHitosArtista($uid);
        return $stem;
    });
}
function cambiarEstadoColab(int $uid, int $id, string $state, int $final = 0): void
{
    if (!in_array($state, ['abierta','cerrada','publicada','archivada'], true)) {
        throw new InvalidArgumentException('Estado no válido.');
    }
    if ($state === 'publicada') {
        metadatosParaSala($uid, $final);
    }
    transaccionColab($uid, $id, function (PDO $db, array $row) use ($uid, $id, $state, $final) {
        if ((int)$row['usuario_id'] !== $uid) {
            throw new OutOfBoundsException('Solo el creador administra el proyecto.');
        }
        if ($state === 'archivada') {
            $db->prepare('UPDATE musicmad_collab_details SET archivada=1 WHERE colaboracion_id=?')->execute([$id]);
            return;
        }
        if ($state === 'publicada') {
            $st = $db->prepare('SELECT usuario_id FROM publicaciones WHERE id=? FOR UPDATE');
            $st->execute([$final]);
            if ((int)$st->fetchColumn() !== $uid) {
                throw new OutOfBoundsException('La mezcla final debe ser tuya.');
            }
            $db->prepare('UPDATE musicmad_collab_details SET final_publicacion_id=? WHERE colaboracion_id=?')->execute([$final,$id]);
        }
        $db->prepare('UPDATE colaboraciones SET estado=? WHERE id=?')->execute([$state,$id]);
    });
}
function stemsColabPara(int $uid, int $id): array
{
    if (!colabDisponiblePara($uid, $id)) {
        return [];
    }
    $st = getDB()->prepare("SELECT s.id,s.usuario_id,s.titulo,s.created_at,t.publicacion_id,t.version_de,t.offset_ms,t.volumen,CONCAT(u.nombre,' ',u.apellido) AS autor,m.duracion FROM colaboracion_stems s JOIN musicmad_stem_tracks t ON t.stem_id=s.id JOIN publicaciones p ON p.id=t.publicacion_id AND p.usuario_id=s.usuario_id JOIN publicacion_audio a ON a.publicacion_id=p.id AND a.archivo=s.archivo JOIN publicacion_medios m ON m.publicacion_id=p.id JOIN usuarios u ON u.id=s.usuario_id AND u.activo=1 WHERE s.colaboracion_id=? ORDER BY s.id DESC LIMIT 40");
    $st->execute([$id]);
    return array_values(array_filter($st->fetchAll(), fn ($s) => puedeVerPublicacionSocial3($uid, (int)$s['publicacion_id'])));
}
function listarColaboraciones(int $uid): array
{
    if (!funcionalidadMusicMad('estudio', $uid)) {
        return [];
    }
    if (!estudioCreativoDisponible()) {
        return [];
    }[$policy,$params] = filtroAccesoContenido($uid, 'c.usuario_id', 'collab_discover');
    $st = getDB()->prepare("SELECT c.id FROM colaboraciones c JOIN musicmad_collab_details d ON d.colaboracion_id=c.id WHERE d.archivada=0 AND (" . $policy . ') ORDER BY c.id DESC LIMIT 100');
    $st->execute($params);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $row = colabDisponiblePara($uid, (int)$id);
        if ($row) {
            $out[] = $row;
        }
        if (count($out) >= 30) {
            break;
        }
    }
    return $out;
}
function pistasPropiasCreativas(int $uid, bool $video = false): array
{
    $st = getDB()->prepare("SELECT p.id,p.titulo,m.duracion,IF(a.publicacion_id IS NULL,'video','audio') AS medio FROM publicaciones p JOIN publicacion_medios m ON m.publicacion_id=p.id LEFT JOIN publicacion_audio a ON a.publicacion_id=p.id LEFT JOIN publicacion_video v ON v.publicacion_id=p.id WHERE p.usuario_id=? AND m.duracion>0 AND (a.publicacion_id IS NOT NULL" . ($video ? ' OR v.publicacion_id IS NOT NULL' : '') . ') ORDER BY p.id DESC LIMIT 100');
    $st->execute([$uid]);
    return $st->fetchAll();
}
function retirarVinculosCreativosPub(int $pub): void
{
    if (!estudioCreativoDisponible()) {
        return;
    }
    $db = getDB();
    $db->prepare('DELETE s FROM colaboracion_stems s JOIN musicmad_stem_tracks t ON t.stem_id=s.id WHERE t.publicacion_id=?')->execute([$pub]);
    $db->prepare('DELETE c FROM clips_musicales c JOIN musicmad_clip_sources t ON t.clip_id=c.id WHERE t.publicacion_id=?')->execute([$pub]);
}
