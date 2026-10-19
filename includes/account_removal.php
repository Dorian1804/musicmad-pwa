<?php

require_once __DIR__ . '/upload_transport.php';
/** Collect names while ownership rows exist; filesystem changes happen only after commit. */
function prepararMediosCuentaParaBorrado(PDO $db, int $uid): void
{
    foreach (['audio' => 'audio','video' => 'videos','partitura' => 'partituras'] as $type => $folder) {
        $st = $db->prepare('INSERT IGNORE INTO musicmad_media_gc(carpeta,archivo) SELECT ?,m.archivo FROM publicacion_' . $type . ' m JOIN publicaciones p ON p.id=m.publicacion_id WHERE p.usuario_id=?');
        $st->execute([$folder,$uid]);
    }
    $db->prepare("INSERT IGNORE INTO musicmad_media_gc(carpeta,archivo) SELECT IF(tipo='audio','audio','videos'),archivo FROM clips_musicales WHERE usuario_id=?")->execute([$uid]);
    $db->prepare("INSERT IGNORE INTO musicmad_media_gc(carpeta,archivo) SELECT 'audio',archivo FROM colaboracion_stems WHERE usuario_id=?")->execute([$uid]);
    $db->prepare("INSERT IGNORE INTO musicmad_media_gc(carpeta,archivo) SELECT 'market',f.archivo FROM instrumentos_fotos f JOIN instrumentos_usados i ON i.id=f.instrumento_id WHERE i.usuario_id=?")->execute([$uid]);
    $db->prepare("INSERT IGNORE INTO musicmad_media_gc(carpeta,archivo) SELECT 'market',foto FROM instrumentos_usados WHERE usuario_id=? AND foto IS NOT NULL AND foto<>''")->execute([$uid]);
    $db->prepare("INSERT IGNORE INTO musicmad_media_gc(carpeta,archivo) SELECT 'staging',CONCAT(id,'.part') FROM musicmad_uploads WHERE usuario_id=? AND estado='subiendo'")->execute([$uid]);
    $st = $db->prepare('SELECT avatar FROM usuarios WHERE id=?');
    $st->execute([$uid]);
    $avatar = (string)$st->fetchColumn();
    if (preg_match('#^uploads/avatars/' . preg_quote((string)$uid, '#') . '_[a-f0-9]{24}\.jpg$#D', $avatar)) {
        $db->prepare("INSERT IGNORE INTO musicmad_media_gc(carpeta,archivo) VALUES('avatars',?)")->execute([basename($avatar)]);
    }
    $st = $db->prepare('SELECT archivo FROM fondos_perfil WHERE usuario_id=?');
    $st->execute([$uid]);
    $cover = (string)$st->fetchColumn();
    if (preg_match('/^' . preg_quote((string)$uid, '/') . '_[a-f0-9]{24}\.jpg$/D', $cover)) {
        $db->prepare("INSERT IGNORE INTO musicmad_media_gc(carpeta,archivo) VALUES('covers',?)")->execute([$cover]);
    }
}
/** CLI cron only. Locks/rechecks deadline and inactive state; cancel and purge cannot win together. */
function purgarCuentasSolicitadas(int $maximum = 3, int $seconds = 5): int
{
    if (!controlesCuentaDisponibles()) {
        return 0;
    }
    $db = getDB();
    if ($db->inTransaction()) {
        throw new LogicException('El borrado necesita su propia transacción.');
    }
    $deadline = microtime(true) + max(1, min(10, $seconds));
    $done = 0;
    for ($i = 0;$i < max(1, min(10, $maximum)) && microtime(true) < $deadline;$i++) {
        $db->beginTransaction();
        try {
            $db->query('SELECT id FROM musicmad_security_guard WHERE id=1 FOR UPDATE')->fetchColumn();
            $st = $db->query("SELECT d.usuario_id FROM musicmad_account_deletions d JOIN usuarios u ON u.id=d.usuario_id AND u.activo=0 WHERE d.estado='pendiente' AND d.ejecutar_en<=UTC_TIMESTAMP() ORDER BY d.ejecutar_en,d.usuario_id LIMIT 1 FOR UPDATE");
            $uid = (int)$st->fetchColumn();
            if (!$uid) {
                $db->commit();
                break;
            }
            // A transferred band is independent of its former creator; stale ownership is repaired.
            $st = $db->prepare('SELECT id FROM bandas WHERE creador_id=? ORDER BY id FOR UPDATE');
            $st->execute([$uid]);
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $band) {
                mantenerAdministracionBanda($db, (int)$band, $uid);
            }
            prepararMediosCuentaParaBorrado($db, $uid);
            auditarCuenta($uid, 'cuenta_eliminada');
            // The old shop's orders have a restrictive FK. They are part of the requested data removal.
            $db->prepare('DELETE FROM pedidos WHERE usuario_id=?')->execute([$uid]);
            $db->prepare('DELETE FROM auditoria_acciones WHERE usuario_id=?')->execute([$uid]);
            $db->prepare('DELETE FROM usuarios WHERE id=? AND activo=0')->execute([$uid]);
            $db->commit();
            $done++;
        } catch (Throwable $error) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $error;
        }
    }
    return $done;
}
function medioRecogidaReferenciado(string $folder, string $file): bool
{
    $queries = match($folder) {
        'audio' => [['SELECT 1 FROM publicacion_audio WHERE archivo=? LIMIT 1',$file],['SELECT 1 FROM clips_musicales WHERE tipo=\'audio\' AND archivo=? LIMIT 1',$file],['SELECT 1 FROM colaboracion_stems WHERE archivo=? LIMIT 1',$file]],
        'videos' => [['SELECT 1 FROM publicacion_video WHERE archivo=? LIMIT 1',$file],['SELECT 1 FROM clips_musicales WHERE tipo=\'video\' AND archivo=? LIMIT 1',$file]],
        'partituras' => [['SELECT 1 FROM publicacion_partitura WHERE archivo=? LIMIT 1',$file]],
        'avatars' => [['SELECT 1 FROM usuarios WHERE avatar=? LIMIT 1','uploads/avatars/' . $file]],
        'covers' => [['SELECT 1 FROM fondos_perfil WHERE archivo=? LIMIT 1',$file]],
        'market' => [['SELECT 1 FROM instrumentos_fotos WHERE archivo=? LIMIT 1',$file],['SELECT 1 FROM instrumentos_usados WHERE foto=? LIMIT 1',$file]],
        'staging' => [['SELECT 1 FROM musicmad_uploads WHERE id=? AND estado=\'subiendo\' LIMIT 1',substr($file, 0, 32)]],
        default => throw new InvalidArgumentException('Carpeta no válida.')
    };
    foreach ($queries as [$sql,$value]) {
        $st = getDB()->prepare($sql);
        $st->execute([$value]);
        if ($st->fetchColumn()) {
            return true;
        }
    }
    return false;
}
function rutaMedioRecogida(string $folder, string $file): ?string
{
    $pattern = match($folder) {
        'audio' => '/^[A-Za-z0-9][A-Za-z0-9_-]{0,70}\.(mp3|m4a|ogg|wav)$/Di','videos' => '/^[a-f0-9]{24}\.(mp4|webm)$/D','partituras' => '/^[a-f0-9]{24}\.pdf$/D','avatars','covers' => '/^[1-9][0-9]*_[a-f0-9]{24}\.jpg$/D','market' => '/^[a-f0-9]{24}\.jpg$/D','staging' => '/^[a-f0-9]{32}\.part$/D',default => null
    };
    if ($pattern === null || !preg_match($pattern, $file)) {
        return null;
    }
    $dir = $folder === 'staging' ? directorioSubidasPendientes() : musicmadUploadDirectory($folder);
    if (is_link($dir) || is_link($dir . '/' . $file)) {
        return null;
    }
    $base = realpath($dir);
    if ($base === false) {
        return null;
    }
    $path = realpath($dir . '/' . $file);
    if ($path === false) {
        return null;
    }
    return dirname($path) === $base && is_file($path) ? $path : null;
}
/** Bounded cleanup never traverses a directory, follows symlinks or deletes referenced media. */
function recogerMediosEliminados(int $maximum = 30, int $seconds = 3): int
{
    if (!controlesCuentaDisponibles()) {
        return 0;
    }
    $db = getDB();
    $deadline = microtime(true) + max(1, min(10, $seconds));
    $done = 0;
    $rows = $db->query('SELECT id,carpeta,archivo FROM musicmad_media_gc WHERE created_at<=UTC_TIMESTAMP() ORDER BY created_at,id LIMIT ' . max(1, min(100, $maximum)))->fetchAll();
    foreach ($rows as $row) {
        if (microtime(true) > $deadline) {
            break;
        }
        $folder = $row['carpeta'];
        $file = $row['archivo'];
        if (medioRecogidaReferenciado($folder, $file)) {
            $db->prepare('UPDATE musicmad_media_gc SET created_at=UTC_TIMESTAMP()+INTERVAL 1 DAY WHERE id=?')->execute([$row['id']]);
            continue;
        }
        $path = rutaMedioRecogida($folder, $file);
        if ($path !== null && !unlink($path)) {
            $db->prepare('UPDATE musicmad_media_gc SET created_at=UTC_TIMESTAMP()+INTERVAL 1 HOUR WHERE id=?')->execute([$row['id']]);
            continue;
        }
        // Missing or invalid paths have no eligible file; do not touch them.
        $db->prepare('DELETE FROM musicmad_media_gc WHERE id=?')->execute([$row['id']]);
        $done++;
    }
    return $done;
}
