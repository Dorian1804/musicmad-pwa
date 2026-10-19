<?php

function clipDisponiblePara(int $uid, int $id): ?array
{
    if (!funcionalidadMusicMad('clips', $uid)) {
        return null;
    }
    if ($uid < 1 || !estudioCreativoDisponible()) {
        return null;
    }
    $st = getDB()->prepare("SELECT c.id,c.usuario_id,c.tipo,c.texto,c.expira_en,s.publicacion_id,s.inicio_ms,s.duracion_ms,p.titulo,CONCAT(u.nombre,' ',u.apellido) AS autor,u.avatar FROM clips_musicales c JOIN musicmad_clip_sources s ON s.clip_id=c.id JOIN publicaciones p ON p.id=s.publicacion_id AND p.usuario_id=c.usuario_id LEFT JOIN publicacion_audio a ON a.publicacion_id=p.id LEFT JOIN publicacion_video v ON v.publicacion_id=p.id JOIN usuarios u ON u.id=c.usuario_id AND u.activo=1 WHERE c.id=? AND c.expira_en>UTC_TIMESTAMP() AND ((c.tipo='audio' AND a.archivo=c.archivo) OR (c.tipo='video' AND v.archivo=c.archivo)) AND EXISTS(SELECT 1 FROM usuarios viewer WHERE viewer.id=? AND viewer.activo=1)");
    $st->execute([$id,$uid]);
    $row = $st->fetch();
    if (!$row || !puedeVerPublicacionSocial3($uid, (int)$row['publicacion_id'])) {
        return null;
    }
    $row['avatar'] = avatarBusqueda((int)$row['usuario_id'], $row['avatar']);
    return $row;
}
function crearClipMusical(int $uid, array $data, string $request): int
{
    exigirFuncionMusicMad('clips', $uid);
    if (!estudioCreativoDisponible()) {
        throw new RuntimeException('Clips no disponibles.');
    }
    $pub = idCampo($data, 'pista');
    $text = trim(textoCampo($data, 'texto'));
    $start = filter_var($data['inicio'] ?? null, FILTER_VALIDATE_FLOAT);
    $length = filter_var($data['duracion'] ?? null, FILTER_VALIDATE_FLOAT);
    if (!preg_match('/^[a-f0-9]{32}$/D', $request) || mb_strlen($text) > 180 || $start === false || $length === false || !is_finite($start) || !is_finite($length) || $start < 0 || $start > 21600 || $length < 1 || $length > 30) {
        throw new InvalidArgumentException('Elige un fragmento de uno a treinta segundos.');
    }
    $startMs = (int)round($start * 1000);
    $lengthMs = (int)round($length * 1000);
    $hash = hash('sha256', json_encode([$pub,$text,$startMs,$lengthMs], JSON_THROW_ON_ERROR));
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
        $st = $db->prepare('SELECT clip_id,payload_hash FROM musicmad_clip_receipts WHERE usuario_id=? AND solicitud=?');
        $st->execute([$uid,$request]);
        $old = $st->fetch();
        if ($old) {
            if (!hash_equals($old['payload_hash'], $hash)) {
                throw new UnexpectedValueException('El envío ya se usó para otro clip.');
            }
            if ($owns) {
                $db->commit();
            }
            return (int)$old['clip_id'];
        }
        $st = $db->prepare("SELECT p.usuario_id,m.duracion,a.archivo AS audio,v.archivo AS video FROM publicaciones p JOIN publicacion_medios m ON m.publicacion_id=p.id LEFT JOIN publicacion_audio a ON a.publicacion_id=p.id LEFT JOIN publicacion_video v ON v.publicacion_id=p.id WHERE p.id=? FOR UPDATE");
        $st->execute([$pub]);
        $row = $st->fetch();
        if (!$row || (int)$row['usuario_id'] !== $uid || (!isset($row['audio']) && !isset($row['video']))) {
            throw new OutOfBoundsException('Elige audio o video de una publicación tuya.');
        }
        $duration = (float)$row['duracion'];
        if (!is_finite($duration) || $duration <= 0 || ($startMs + $lengthMs) / 1000 > $duration) {
            throw new InvalidArgumentException('El fragmento está fuera del archivo.');
        }
        $type = $row['audio'] ? 'audio' : 'video';
        $file = $row[$type];
        $dir = musicmadMediaDirectoryForFile($type === 'audio' ? 'audio' : 'videos', $file);
        $base = realpath($dir);
        $path = is_string($file) ? realpath($dir . '/' . $file) : false;
        if (!$base || !$path || dirname($path) !== $base || is_link($dir) || is_link($dir . '/' . $file) || !is_file($path)) {
            throw new OutOfBoundsException('El archivo no está disponible.');
        }
        $st = $db->prepare('SELECT COUNT(*) FROM clips_musicales WHERE usuario_id=? AND expira_en>UTC_TIMESTAMP()');
        $st->execute([$uid]);
        if ((int)$st->fetchColumn() >= 10) {
            throw new OverflowException('Puedes tener diez clips activos.');
        }
        limiteChat($uid, 'clip_crear', 5);
        $db->prepare('INSERT INTO clips_musicales(usuario_id,archivo,tipo,duracion_segundos,texto,expira_en,created_at) VALUES(?,?,?,?,?,UTC_TIMESTAMP()+INTERVAL 1 DAY,UTC_TIMESTAMP())')->execute([$uid,$file,$type,(int)ceil($length),$text]);
        $id = (int)$db->lastInsertId();
        $db->prepare('INSERT INTO musicmad_clip_sources(clip_id,publicacion_id,inicio_ms,duracion_ms) VALUES(?,?,?,?)')->execute([$id,$pub,$startMs,$lengthMs]);
        $db->prepare('INSERT INTO musicmad_clip_receipts(usuario_id,solicitud,payload_hash,clip_id) VALUES(?,?,?,?)')->execute([$uid,$request,$hash,$id]);
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
function listarClipsMusicales(int $uid, int $limit = 24): array
{
    if (!funcionalidadMusicMad('clips', $uid)) {
        return [];
    }
    if (!estudioCreativoDisponible()) {
        return [];
    }[$policy,$params] = filtroAccesoContenido($uid, 'c.usuario_id', 'clip_discovery');
    $st = getDB()->prepare('SELECT c.id FROM clips_musicales c JOIN musicmad_clip_sources s ON s.clip_id=c.id WHERE c.expira_en>UTC_TIMESTAMP() AND (' . $policy . ') ORDER BY c.id DESC LIMIT 100');
    $st->execute($params);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $row = clipDisponiblePara($uid, (int)$id);
        if ($row) {
            $out[] = $row;
        }
        if (count($out) >= max(1, min(50, $limit))) {
            break;
        }
    }
    return $out;
}
function retirarClipMusical(int $uid, int $id): void
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
        $st = $db->prepare('SELECT id FROM clips_musicales WHERE id=? AND usuario_id=? FOR UPDATE');
        $st->execute([$id,$uid]);
        if (!$st->fetchColumn()) {
            throw new OutOfBoundsException('Clip no disponible.');
        }
        limiteChat($uid, 'clip_retirar', 20);
        $db->prepare('UPDATE clips_musicales SET expira_en=UTC_TIMESTAMP() WHERE id=? AND usuario_id=?')->execute([$id,$uid]);
        if ($owns) {
            $db->commit();
        }
    } catch (Throwable $e) {
        if ($owns && $db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}
function limpiarClipsMusicales(): int
{
    if (!estudioCreativoDisponible()) {
        return 0;
    }
    $db = getDB();
    $ids = $db->query('SELECT id FROM clips_musicales WHERE expira_en<=UTC_TIMESTAMP() ORDER BY expira_en LIMIT 100')->fetchAll(PDO::FETCH_COLUMN);
    $count = 0;
    foreach ($ids as $id) {
        $db->beginTransaction();
        try {
            $st = $db->prepare('SELECT archivo,tipo FROM clips_musicales WHERE id=? AND expira_en<=UTC_TIMESTAMP() FOR UPDATE');
            $st->execute([(int)$id]);
            $row = $st->fetch();
            if ($row) {
                if (controlesCuentaDisponibles()) {
                    $db->prepare('INSERT IGNORE INTO musicmad_media_gc(carpeta,archivo) VALUES(?,?)')->execute([$row['tipo'] === 'audio' ? 'audio' : 'videos',$row['archivo']]);
                }
                $db->prepare('DELETE FROM clips_musicales WHERE id=?')->execute([(int)$id]);
                $count++;
            }
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }
    return $count;
}
