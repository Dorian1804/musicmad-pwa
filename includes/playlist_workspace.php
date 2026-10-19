<?php

function playlistsEquipoDisponible(): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    $st = getDB()->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');
    $st->execute(['musicmad_playlist_members']);
    return $ready = (int)$st->fetchColumn() === 1;
}
function miembroPlaylist(int $uid, int $id): bool
{
    if (!funcionalidadMusicMad('playlists_equipo', $uid)) {
        return false;
    }
    if (!playlistsEquipoDisponible()) {
        return false;
    }
    $st = getDB()->prepare("SELECT 1 FROM musicmad_playlist_members WHERE playlist_id=? AND usuario_id=? AND estado='aceptada'");
    $st->execute([$id,$uid]);
    return (bool)$st->fetchColumn();
}
function puedeEditarPlaylist(int $uid, array $list): bool
{
    return (int)$list['usuario_id'] === $uid || ((int)$list['colaborativa'] === 1 && miembroPlaylist($uid, (int)$list['id']));
}
function candidatosInvitacionPlaylist(int $uid, string $query): array
{
    $query = mb_substr(trim($query), 0, 80);
    [$policy,$params] = filtroAccesoContenido($uid, 'u.id', 'invite_candidate', false);
    $st = getDB()->prepare("SELECT u.id,u.nombre,u.apellido FROM usuarios u WHERE u.activo=1 AND u.id<>:uid AND CONCAT(u.nombre,' ',u.apellido) LIKE :query AND (" . $policy . ") ORDER BY u.nombre,u.id LIMIT 50");
    $st->execute([':uid' => $uid,':query' => '%' . addcslashes($query, '%_\\') . '%'] + $params);
    return array_slice(array_values(array_filter($st->fetchAll(), static fn ($person) => puedeVerPerfilSocial3((int)$person['id'], $uid))), 0, 20);
}
function listasEquipoUsuario(int $uid, bool $pending = false): array
{
    if (!funcionalidadMusicMad('playlists_equipo', $uid)) {
        return [];
    }
    if (!playlistsEquipoDisponible()) {
        return [];
    }[$policy,$params] = filtroAccesoContenido($uid, 'pl.usuario_id', 'team_list', false);
    $st = getDB()->prepare('SELECT pl.id,pl.nombre,pl.descripcion,pl.publica,pl.colaborativa,pl.usuario_id,u.nombre AS autor,u.apellido AS autor_apellido FROM musicmad_playlist_members m JOIN playlists pl ON pl.id=m.playlist_id AND pl.colaborativa=1 JOIN usuarios u ON u.id=pl.usuario_id AND u.activo=1 WHERE m.usuario_id=:uid AND m.estado=:state AND (' . $policy . ') ORDER BY m.invitado_en DESC LIMIT 50');
    $st->execute([':uid' => $uid,':state' => $pending ? 'pendiente' : 'aceptada'] + $params);
    return $st->fetchAll();
}
function equipoPlaylist(int $uid, int $id): array
{
    $list = obtenerPlaylistSocial3($id, $uid);
    if (!$list || !puedeEditarPlaylist($uid, $list) || !playlistsEquipoDisponible()) {
        return [];
    }
    [$policy,$params] = filtroBloqueoInteraccion($uid, 'm.usuario_id', 'list_member');
    $st = getDB()->prepare('SELECT m.usuario_id,m.estado,u.nombre,u.apellido FROM musicmad_playlist_members m JOIN usuarios u ON u.id=m.usuario_id AND u.activo=1 WHERE m.playlist_id=:id AND (' . $policy . ') ORDER BY m.invitado_en,m.usuario_id LIMIT 20');
    $st->execute([':id' => $id] + $params);
    return $st->fetchAll();
}
function accionEquipoPlaylist(int $uid, int $id, string $action, int $target = 0): void
{
    exigirFuncionMusicMad('playlists_equipo', $uid);
    if (!playlistsEquipoDisponible()) {
        throw new RuntimeException('Actualización de playlists no disponible.');
    }
    if (!in_array($action, ['invitar','aceptar','rechazar','revocar','salir'], true)) {
        throw new InvalidArgumentException('Acción no válida.');
    }
    limiteChat($uid, 'playlist_team', 15);
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
        $st = $db->prepare('SELECT pl.* FROM playlists pl JOIN usuarios u ON u.id=pl.usuario_id AND u.activo=1 WHERE pl.id=? FOR UPDATE');
        $st->execute([$id]);
        $list = $st->fetch();
        if (!$list) {
            throw new OutOfBoundsException('Lista no disponible.');
        }
        $owner = (int)$list['usuario_id'];
        if (in_array($action, ['invitar','revocar'], true)) {
            if ($owner !== $uid || $target < 1 || $target === $uid) {
                throw new OutOfBoundsException('Solo el creador administra colaboradores.');
            }
            if ($action === 'revocar') {
                $db->prepare('DELETE FROM musicmad_playlist_members WHERE playlist_id=? AND usuario_id=?')->execute([$id,$target]);
            } else {
                if (!puedeVerPerfilSocial3($uid, $target) || !puedeVerPerfilSocial3($target, $uid)) {
                    throw new OutOfBoundsException('Músico no disponible.');
                }
                $st = $db->prepare('SELECT id FROM usuarios WHERE id=? AND activo=1');
                $st->execute([$target]);
                if (!$st->fetchColumn()) {
                    throw new OutOfBoundsException('Músico no disponible.');
                }
                $st = $db->prepare('SELECT 1 FROM musicmad_playlist_members WHERE playlist_id=? AND usuario_id=?');
                $st->execute([$id,$target]);
                $existing = (bool)$st->fetchColumn();
                $st = $db->prepare('SELECT COUNT(*) FROM musicmad_playlist_members WHERE playlist_id=?');
                $st->execute([$id]);
                if (!$existing && (int)$st->fetchColumn() >= 20) {
                    throw new OverflowException('Máximo veinte colaboradores e invitaciones.');
                }
                $db->prepare("INSERT IGNORE INTO musicmad_playlist_members(playlist_id,usuario_id,estado,invitado_en) VALUES(?,?,'pendiente',UTC_TIMESTAMP())")->execute([$id,$target]);
                $db->prepare('UPDATE playlists SET colaborativa=1 WHERE id=?')->execute([$id]);
            }
        } else {
            if ($owner === $uid) {
                throw new InvalidArgumentException('El creador administra la lista desde su equipo.');
            }
            if ($action === 'aceptar') {
                if (!(int)$list['colaborativa'] || !puedeVerPerfilSocial3($uid, $owner)) {
                    throw new OutOfBoundsException('Lista no disponible.');
                }
                $st = $db->prepare('SELECT estado FROM musicmad_playlist_members WHERE playlist_id=? AND usuario_id=? FOR UPDATE');
                $st->execute([$id,$uid]);
                if (!$st->fetchColumn()) {
                    throw new OutOfBoundsException('Invitación no disponible.');
                }
                $db->prepare("UPDATE musicmad_playlist_members SET estado='aceptada',aceptado_en=COALESCE(aceptado_en,UTC_TIMESTAMP()) WHERE playlist_id=? AND usuario_id=?")->execute([$id,$uid]);
            } else {
                $db->prepare('DELETE FROM musicmad_playlist_members WHERE playlist_id=? AND usuario_id=?')->execute([$id,$uid]);
            }
        }
        if ($own) {
            $db->commit();
        }
    } catch (Throwable $e) {
        if ($own && $db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}
function cambiarPistaEquipoPlaylist(int $uid, int $id, int $pub, bool $add): bool
{
    if (!playlistsEquipoDisponible()) {
        return false;
    }
    limiteChat($uid, 'playlist_tracks', 30);
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
        $st = $db->prepare('SELECT id FROM playlists WHERE id=? FOR UPDATE');
        $st->execute([$id]);
        if (!$st->fetchColumn()) {
            throw new OutOfBoundsException('Lista no disponible.');
        }
        $list = obtenerPlaylistSocial3($id, $uid);
        if (!$list || !puedeEditarPlaylist($uid, $list)) {
            throw new OutOfBoundsException('No puedes editar esta playlist.');
        }
        if ($add) {
            if (!puedeVerPublicacionSocial3($uid, $pub) || !puedeVerPublicacionSocial3((int)$list['usuario_id'], $pub)) {
                throw new OutOfBoundsException('Pista no disponible para esta colección.');
            }
            $st = $db->prepare("SELECT 1 FROM publicaciones p JOIN publicacion_audio a ON a.publicacion_id=p.id WHERE p.id=? AND p.tipo IN ('cancion','cover','demo')");
            $st->execute([$pub]);
            if (!$st->fetchColumn()) {
                throw new OutOfBoundsException('Elige una pista con audio.');
            }
            $st = $db->prepare('SELECT 1 FROM playlist_pistas WHERE playlist_id=? AND publicacion_id=?');
            $st->execute([$id,$pub]);
            if (!$st->fetchColumn()) {
                $st = $db->prepare('SELECT COUNT(*),COALESCE(MAX(orden),0)+1 FROM playlist_pistas WHERE playlist_id=?');
                $st->execute([$id]);
                [$count,$order] = $st->fetch(PDO::FETCH_NUM);
                if ((int)$count >= 300) {
                    throw new OverflowException('Máximo trescientas pistas.');
                }
                $db->prepare('INSERT INTO playlist_pistas(playlist_id,publicacion_id,agregado_por,orden) VALUES(?,?,?,?)')->execute([$id,$pub,$uid,$order]);
            }
        } else {
            $st = $db->prepare('SELECT agregado_por FROM playlist_pistas WHERE playlist_id=? AND publicacion_id=?');
            $st->execute([$id,$pub]);
            $addedBy = $st->fetchColumn();
            if ($addedBy !== false && (int)$list['usuario_id'] !== $uid && (int)$addedBy !== $uid) {
                throw new OutOfBoundsException('Solo puedes retirar las pistas que añadiste.');
            }
            $sql = 'DELETE FROM playlist_pistas WHERE playlist_id=? AND publicacion_id=?';
            $args = [$id,$pub];
            if ((int)$list['usuario_id'] !== $uid) {
                $sql .= ' AND agregado_por=?';
                $args[] = $uid;
            }
            $db->prepare($sql)->execute($args);
        }
        $db->prepare('UPDATE playlists SET updated_at=UTC_TIMESTAMP() WHERE id=?')->execute([$id]);
        if ($own) {
            $db->commit();
        }
        return true;
    } catch (Throwable $e) {
        if ($own && $db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}
