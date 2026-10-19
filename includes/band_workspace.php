<?php

require_once __DIR__ . '/chat_live.php';
const BAND_ROLES = ['admin' => 'Administrador','coordinador' => 'Coordinador','miembro' => 'Integrante'];
function bandasWorkspaceDisponible(): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    $tables = ['musicmad_band_settings','musicmad_band_roles','musicmad_band_invites','musicmad_band_rehearsals','musicmad_band_rsvp','musicmad_band_chat_receipts','musicmad_band_chat_reads','musicmad_band_posts','musicmad_band_notifications','musicmad_band_chat_tracks'];
    $st = getDB()->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN (' . implode(',', array_fill(0, count($tables), '?')) . ')');
    $st->execute($tables);
    $count = (int)$st->fetchColumn();
    if ($count === 0) {
        return $ready = false;
    }
    if ($count !== count($tables) || !chatLiveDisponible()) {
        throw new RuntimeException('Actualización de bandas incompleta.');
    }
    return $ready = true;
}
function configuracionBanda(int $id): array
{
    $defaults = ['ingreso' => 'abierto','color' => 'violeta','archivada_en' => null,'archivada_por' => null];
    if (!bandasWorkspaceDisponible()) {
        return $defaults;
    }
    $st = getDB()->prepare('SELECT ingreso,color,archivada_en,archivada_por FROM musicmad_band_settings WHERE banda_id=?');
    $st->execute([$id]);
    return $st->fetch() ?: $defaults;
}
function bandaDisponible(int $yo, int $id): ?array
{
    $band = obtenerBanda($id);
    if (!$band || configuracionBanda($id)['archivada_en'] !== null) {
        return null;
    }
    [$block,$params] = filtroBloqueoInteraccion($yo, 'u.id', 'band_access');
    $st = getDB()->prepare('SELECT 1 FROM usuarios u WHERE u.id=:creator AND u.activo=1 AND ' . $block);
    $st->execute([':creator' => $band['creador_id']] + $params);
    return $st->fetchColumn() ? $band : null;
}
function exigirIntegranteBanda(int $yo, int $id, array $roles = ['admin','coordinador','miembro']): string
{
    if (!bandaDisponible($yo, $id)) {
        throw new OutOfBoundsException('Banda no disponible.');
    }
    $role = rolEnBanda($yo, $id);
    if (!in_array($role, $roles, true)) {
        throw new OutOfBoundsException('No tienes acceso a esta sección de la banda.');
    }
    return $role;
}
function transaccionBanda(int $yo, int $id, callable $operation, bool $archived = false): mixed
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
            throw new OutOfBoundsException('Acceso no disponible.');
        }
        $st = $db->prepare('SELECT id FROM bandas WHERE id=? FOR UPDATE');
        $st->execute([$id]);
        if (!$st->fetchColumn() || (!$archived && !bandaDisponible($yo, $id))) {
            throw new OutOfBoundsException('Banda no disponible.');
        }
        $result = $operation($db);
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
function notificarActividadBanda(int $yo, int $actor, int $band, string $type, int $reference): ?int
{
    if ($yo === $actor && $type !== 'recordatorio') {
        return null;
    }
    $allowed = ['invitacion','ensayo','ensayo_cancelado','rol','publicacion','recordatorio'];
    if (!in_array($type, $allowed, true)) {
        throw new InvalidArgumentException('Aviso no válido.');
    }
    $db = getDB();
    $db->prepare("INSERT INTO notificaciones(usuario_id,actor_id,tipo,banda_id) VALUES(?,?,'banda',?)")->execute([$yo,$actor,$band]);
    $id = (int)$db->lastInsertId();
    $db->prepare('INSERT INTO musicmad_band_notifications(notificacion_id,subtipo,referencia_id) VALUES(?,?,?)')->execute([$id,$type,$reference]);
    try {
        encolarAvisoPush($yo, $actor, 'bandas', $id);
    } catch (Throwable $error) {
        error_log('Band notification queue: ' . get_class($error));
    }
    return $id;
}
function unirseBandaWorkspace(int $yo, int $id, string $instrument): bool
{
    return transaccionBanda($yo, $id, function (PDO $db) use ($yo, $id, $instrument) {
        if (configuracionBanda($id)['ingreso'] !== 'abierto' || rolEnBanda($yo, $id) !== null) {
            return false;
        }
        $st = $db->prepare('SELECT COUNT(*) FROM banda_miembros WHERE banda_id=?');
        $st->execute([$id]);
        if ((int)$st->fetchColumn() >= 100) {
            throw new OverflowException('Esta banda ya tiene cien integrantes.');
        }
        $db->prepare("INSERT INTO banda_miembros(banda_id,usuario_id,rol,instrumento) VALUES(?,?,'miembro',?)")->execute([$id,$yo,mb_substr(trim($instrument), 0, 60)]);
        foreach (miembrosBanda($id) as $member) {
            if ($member['rol'] === 'admin') {
                notificar((int)$member['usuario_id'], $yo, 'banda', null, $id);
            }
        }
        return true;
    });
}
function mantenerAdministracionBanda(PDO $db, int $id, int $departing): void
{
    $st = $db->prepare("SELECT m.usuario_id,m.rol FROM banda_miembros m JOIN usuarios u ON u.id=m.usuario_id AND u.activo=1 WHERE m.banda_id=? ORDER BY (m.rol='admin') DESC,m.created_at,m.usuario_id");
    $st->execute([$id]);
    $members = $st->fetchAll();
    if (!$members) {
        $db->prepare('INSERT INTO musicmad_band_settings(banda_id,archivada_en,archivada_por) VALUES(?,UTC_TIMESTAMP(),?) ON DUPLICATE KEY UPDATE archivada_en=UTC_TIMESTAMP(),archivada_por=VALUES(archivada_por)')->execute([$id,$departing]);
        return;
    }
    $successor = (int)$members[0]['usuario_id'];
    if ($members[0]['rol'] !== 'admin') {
        $db->prepare("UPDATE banda_miembros SET rol='admin' WHERE banda_id=? AND usuario_id=?")->execute([$id,$successor]);
        $db->prepare('DELETE FROM musicmad_band_roles WHERE banda_id=? AND usuario_id=?')->execute([$id,$successor]);
    }
    $db->prepare('UPDATE bandas SET creador_id=? WHERE id=? AND (creador_id=? OR NOT EXISTS(SELECT 1 FROM banda_miembros m WHERE m.banda_id=bandas.id AND m.usuario_id=bandas.creador_id))')->execute([$successor,$id,$departing]);
}
function salirBandaWorkspace(int $yo, int $id): void
{
    transaccionBanda($yo, $id, function (PDO $db) use ($yo, $id) {
        exigirIntegranteBanda($yo, $id);
        $db->prepare('DELETE FROM banda_miembros WHERE banda_id=? AND usuario_id=?')->execute([$id,$yo]);
        mantenerAdministracionBanda($db, $id, $yo);
    });
}
function cambiarRolBanda(int $yo, int $id, int $target, string $role): void
{
    if (!isset(BAND_ROLES[$role])) {
        throw new InvalidArgumentException('Permiso no válido.');
    }
    transaccionBanda($yo, $id, function (PDO $db) use ($yo, $id, $target, $role) {
        exigirIntegranteBanda($yo, $id, ['admin']);
        $old = rolEnBanda($target, $id);
        if ($old === null) {
            throw new OutOfBoundsException('Integrante no disponible.');
        }
        if ($old === 'admin' && $role !== 'admin') {
            $st = $db->prepare("SELECT COUNT(*) FROM banda_miembros m JOIN usuarios u ON u.id=m.usuario_id AND u.activo=1 WHERE m.banda_id=? AND m.rol='admin' AND m.usuario_id<>?");
            $st->execute([$id,$target]);
            if (!(int)$st->fetchColumn()) {
                throw new LogicException('La banda necesita al menos un administrador.');
            }
        }
        $db->prepare('UPDATE banda_miembros SET rol=? WHERE banda_id=? AND usuario_id=?')->execute([$role === 'admin' ? 'admin' : 'miembro',$id,$target]);
        $db->prepare('DELETE FROM musicmad_band_roles WHERE banda_id=? AND usuario_id=?')->execute([$id,$target]);
        if ($role === 'coordinador') {
            $db->prepare('INSERT INTO musicmad_band_roles(banda_id,usuario_id) VALUES(?,?)')->execute([$id,$target]);
        }
        mantenerAdministracionBanda($db, $id, 0);
        notificarActividadBanda($target, $yo, $id, 'rol', $target);
    });
}
function expulsarMiembroWorkspace(int $yo, int $id, int $target): void
{
    if ($yo === $target) {
        throw new LogicException('Usa Salir de la banda para retirar tu participación.');
    }
    transaccionBanda($yo, $id, function (PDO $db) use ($yo, $id, $target) {
        exigirIntegranteBanda($yo, $id, ['admin']);
        if (rolEnBanda($target, $id) === null) {
            throw new OutOfBoundsException('Integrante no disponible.');
        }
        $db->prepare('DELETE FROM banda_miembros WHERE banda_id=? AND usuario_id=?')->execute([$id,$target]);
        mantenerAdministracionBanda($db, $id, $target);
    });
}
function editarBandaWorkspace(int $yo, int $id, array $data): void
{
    $name = trim(textoCampo($data, 'nombre'));
    $desc = trim(textoCampo($data, 'descripcion'));
    $city = trim(textoCampo($data, 'ciudad'));
    $genre = textoCampo($data, 'genero');
    $entry = textoCampo($data, 'ingreso');
    $color = textoCampo($data, 'color');
    if (mb_strlen($name) < 2 || mb_strlen($name) > 60 || mb_strlen($desc) > 500 || mb_strlen($city) > 80 || ($genre !== '' && !in_array($genre, GENEROS, true)) || !in_array($entry, ['abierto','invitacion'], true) || !in_array($color, ['violeta','azul','coral','verde'], true)) {
        throw new InvalidArgumentException('Revisa los datos de la banda.');
    }
    transaccionBanda($yo, $id, function (PDO $db) use ($yo, $id, $name, $desc, $city, $genre, $entry, $color) {
        exigirIntegranteBanda($yo, $id, ['admin']);
        $db->prepare('UPDATE bandas SET nombre=?,descripcion=?,ciudad=?,genero=? WHERE id=?')->execute([$name,$desc,$city,$genre,$id]);
        $db->prepare('INSERT INTO musicmad_band_settings(banda_id,ingreso,color) VALUES(?,?,?) ON DUPLICATE KEY UPDATE ingreso=VALUES(ingreso),color=VALUES(color)')->execute([$id,$entry,$color]);
    });
}
function archivarBandaWorkspace(int $yo, int $id, bool $restore = false, bool $siteAdmin = false): void
{
    transaccionBanda($yo, $id, function (PDO $db) use ($yo, $id, $restore, $siteAdmin) {
        $settings = configuracionBanda($id);
        if (!$siteAdmin && rolEnBanda($yo, $id) !== 'admin' && !($restore && (int)$settings['archivada_por'] === $yo)) {
            throw new OutOfBoundsException('No puedes administrar esta banda.');
        }
        if ($restore) {
            $db->prepare('UPDATE musicmad_band_settings SET archivada_en=NULL,archivada_por=NULL WHERE banda_id=?')->execute([$id]);
            $st = $db->prepare('SELECT COUNT(*) FROM banda_miembros m JOIN usuarios u ON u.id=m.usuario_id AND u.activo=1 WHERE m.banda_id=?');
            $st->execute([$id]);
            if (!(int)$st->fetchColumn()) {
                $db->prepare("INSERT INTO banda_miembros(banda_id,usuario_id,rol) VALUES(?,?,'admin') ON DUPLICATE KEY UPDATE rol='admin'")->execute([$id,$yo]);
            }
            mantenerAdministracionBanda($db, $id, 0);
        } else {
            $db->prepare('INSERT INTO musicmad_band_settings(banda_id,archivada_en,archivada_por) VALUES(?,UTC_TIMESTAMP(),?) ON DUPLICATE KEY UPDATE archivada_en=UTC_TIMESTAMP(),archivada_por=VALUES(archivada_por)')->execute([$id,$yo]);
        }
    }, true);
}
function invitarABanda(int $yo, int $id, int $target): void
{
    transaccionBanda($yo, $id, function (PDO $db) use ($yo, $id, $target) {
        exigirIntegranteBanda($yo, $id, ['admin']);
        if ($yo === $target || rolEnBanda($target, $id) !== null || !puedeVerPerfilSocial3($yo, $target)) {
            throw new OutOfBoundsException('No se puede invitar a esa cuenta.');
        }
        $st = $db->prepare('SELECT id FROM usuarios WHERE id=? AND activo=1');
        $st->execute([$target]);
        if (!$st->fetchColumn()) {
            throw new OutOfBoundsException('Cuenta no disponible.');
        }
        $st = $db->prepare("SELECT 1 FROM musicmad_band_invites WHERE banda_id=? AND invitado_id=? AND estado='pendiente' AND expira_en>UTC_TIMESTAMP()");
        $st->execute([$id,$target]);
        if ($st->fetchColumn()) {
            return;
        }
        limiteChat($yo, 'invitaciones', 15);
        $db->prepare("INSERT INTO musicmad_band_invites(banda_id,invitado_id,invitado_por,expira_en,created_at) VALUES(?,?,?,UTC_TIMESTAMP()+INTERVAL 7 DAY,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE invitado_por=VALUES(invitado_por),estado='pendiente',expira_en=VALUES(expira_en),created_at=UTC_TIMESTAMP(),respondida_en=NULL")->execute([$id,$target,$yo]);
        notificarActividadBanda($target, $yo, $id, 'invitacion', $id);
    });
}
function responderInvitacionBanda(int $yo, int $id, bool $accept, string $instrument = ''): void
{
    transaccionBanda($yo, $id, function (PDO $db) use ($yo, $id, $accept, $instrument) {
        $st = $db->prepare("SELECT invitado_por FROM musicmad_band_invites WHERE banda_id=? AND invitado_id=? AND estado='pendiente' AND expira_en>UTC_TIMESTAMP() FOR UPDATE");
        $st->execute([$id,$yo]);
        $inviter = (int)$st->fetchColumn();
        if (!$inviter || rolEnBanda($inviter, $id) !== 'admin') {
            throw new OutOfBoundsException('La invitación ya no está disponible.');
        }
        [$block,$params] = filtroBloqueoInteraccion($yo, 'u.id', 'invite_accept');
        $st = $db->prepare('SELECT 1 FROM usuarios u WHERE u.id=:actor AND u.activo=1 AND ' . $block);
        $st->execute([':actor' => $inviter] + $params);
        if (!$st->fetchColumn()) {
            throw new OutOfBoundsException('Invitación no disponible.');
        }
        if ($accept && rolEnBanda($yo, $id) === null) {
            $st = $db->prepare('SELECT COUNT(*) FROM banda_miembros WHERE banda_id=?');
            $st->execute([$id]);
            if ((int)$st->fetchColumn() >= 100) {
                throw new OverflowException('La banda ya tiene cien integrantes.');
            }
            $db->prepare("INSERT INTO banda_miembros(banda_id,usuario_id,rol,instrumento) VALUES(?,?,'miembro',?)")->execute([$id,$yo,mb_substr(trim($instrument), 0, 60)]);
            notificar($inviter, $yo, 'banda', null, $id);
        }
        $db->prepare('UPDATE musicmad_band_invites SET estado=?,respondida_en=UTC_TIMESTAMP() WHERE banda_id=? AND invitado_id=?')->execute([$accept ? 'aceptada' : 'rechazada',$id,$yo]);
        $db->prepare("UPDATE notificaciones n JOIN musicmad_band_notifications bn ON bn.notificacion_id=n.id SET n.leida=1 WHERE n.usuario_id=? AND n.banda_id=? AND bn.subtipo='invitacion'")->execute([$yo,$id]);
    });
}
function invitacionesBandaPara(int $yo): array
{
    [$block,$params] = filtroBloqueoInteraccion($yo, 'u.id', 'invite_inbox');
    $st = getDB()->prepare("SELECT i.*,b.nombre,u.nombre AS actor_nombre,u.apellido AS actor_apellido FROM musicmad_band_invites i JOIN bandas b ON b.id=i.banda_id JOIN usuarios u ON u.id=i.invitado_por AND u.activo=1 JOIN banda_miembros m ON m.banda_id=b.id AND m.usuario_id=u.id AND m.rol='admin' LEFT JOIN musicmad_band_settings s ON s.banda_id=b.id WHERE i.invitado_id=:yo AND i.estado='pendiente' AND i.expira_en>UTC_TIMESTAMP() AND s.archivada_en IS NULL AND " . $block . ' ORDER BY i.created_at DESC LIMIT 30');
    $st->execute([':yo' => $yo] + $params);
    return $st->fetchAll();
}


function listarBandasWorkspace(int $yo, string $query = ''): array
{
    [$block,$params] = filtroBloqueoInteraccion($yo, 'u.id', 'band_list');
    $params[':yo'] = $yo;
    $where = $block;
    if ($query !== '') {
        $where .= ' AND CONCAT_WS(" ",b.nombre,b.genero,b.ciudad) LIKE :q';
        $params[':q'] = '%' . addcslashes(mb_substr($query, 0, 80), '%_\\') . '%';
    }
    $st = getDB()->prepare("SELECT b.*,COALESCE(s.color,'violeta') AS color,COALESCE(s.ingreso,'abierto') AS ingreso,(SELECT COUNT(*) FROM banda_miembros m JOIN usuarios mu ON mu.id=m.usuario_id AND mu.activo=1 WHERE m.banda_id=b.id) AS miembros,EXISTS(SELECT 1 FROM banda_miembros m WHERE m.banda_id=b.id AND m.usuario_id=:yo) AS soy_miembro FROM bandas b JOIN usuarios u ON u.id=b.creador_id AND u.activo=1 LEFT JOIN musicmad_band_settings s ON s.banda_id=b.id WHERE s.archivada_en IS NULL AND " . $where . ' ORDER BY soy_miembro DESC,miembros DESC,b.id DESC LIMIT 60');
    $st->execute($params);
    return $st->fetchAll();
}
function miembrosBandaWorkspace(int $id): array
{
    $st = getDB()->prepare("SELECT m.usuario_id,IF(m.rol='admin','admin',COALESCE(r.permiso,'miembro')) AS rol,m.instrumento,u.nombre,u.apellido,u.avatar FROM banda_miembros m JOIN usuarios u ON u.id=m.usuario_id AND u.activo=1 LEFT JOIN musicmad_band_roles r ON r.banda_id=m.banda_id AND r.usuario_id=m.usuario_id WHERE m.banda_id=? ORDER BY (m.rol='admin') DESC,m.created_at,m.usuario_id LIMIT 100");
    $st->execute([$id]);
    return $st->fetchAll();
}
function bandasArchivadasPara(int $yo): array
{
    $st = getDB()->prepare("SELECT b.id,b.nombre,s.archivada_en FROM bandas b JOIN musicmad_band_settings s ON s.banda_id=b.id WHERE s.archivada_en IS NOT NULL AND (s.archivada_por=:yo OR EXISTS(SELECT 1 FROM banda_miembros m WHERE m.banda_id=b.id AND m.usuario_id=:admin AND m.rol='admin')) ORDER BY s.archivada_en DESC LIMIT 30");
    $st->execute([':yo' => $yo,':admin' => $yo]);
    return $st->fetchAll();
}


require_once __DIR__ . '/band_activity.php';
