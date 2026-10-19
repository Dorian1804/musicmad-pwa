<?php

/** Sensitive operations use a fresh credential check; no REPLACE_WITH_LOCAL_VALUE or code is retained. */
function controlesCuentaDisponibles(): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    $tables = ['musicmad_security_guard','musicmad_site_roles','musicmad_account_deletions','musicmad_media_gc'];
    $st = getDB()->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN (?,?,?,?)');
    $st->execute($tables);
    $n = (int)$st->fetchColumn();
    if ($n === 0) {
        return $ready = false;
    }
    if ($n !== 4) {
        throw new RuntimeException('Actualización de cuenta incompleta.');
    }
    return $ready = true;
}
function rolSitio(int $uid): string
{
    $st = getDB()->prepare('SELECT rol FROM usuarios WHERE id=? AND activo=1');
    $st->execute([$uid]);
    $role = $st->fetchColumn();
    if ($role === false) {
        return '';
    }
    if ($role === 'administrador') {
        return 'administrador';
    }
    if (controlesCuentaDisponibles()) {
        $st = getDB()->prepare('SELECT rol FROM musicmad_site_roles WHERE usuario_id=?');
        $st->execute([$uid]);
        if ($st->fetchColumn() === 'moderador') {
            return 'moderador';
        }
    }
    return 'usuario';
}
function puedeModerar(int $uid): bool
{
    return in_array(rolSitio($uid), ['administrador','moderador'], true);
}
function requerirModeracion(): void
{
    requerirSesion();
    if (!puedeModerar((int)sesion('usuario_id'))) {
        http_response_code(403);
        exit('No tienes acceso a moderación.');
    }
}
/** Explicit categories only: no body text, credentials, IP, cookie or token. */
function auditarCuenta(int $uid, string $action, string $object = 'cuenta', ?int $id = null): void
{
    $allowed = ['datos_exportados','eliminacion_solicitada','eliminacion_cancelada','cuenta_eliminada','rol_asignado','rol_retirado','REPLACE_WITH_LOCAL_VALUE_cambiado','dispositivo_revocado','otros_revocados','reporte_resuelto','anuncio_moderado','verificacion_otorgada','verificacion_retirada'];
    if (!in_array($action, $allowed, true) || !in_array($object, ['cuenta','publicacion','reporte','anuncio'], true)) {
        throw new InvalidArgumentException('Categoría de auditoría no válida.');
    }
    getDB()->prepare('INSERT INTO auditoria_acciones(usuario_id,accion,objeto,objeto_id,detalle,created_at) VALUES(?,?,?,?,NULL,UTC_TIMESTAMP())')->execute([$uid,$action,$object,$id ?? $uid]);
}
/** Lock order is guard, actor, target. Failed checks commit their rate counters. */
function operacionCuentaConfirmada(int $uid, string $REPLACE_WITH_LOCAL_VALUE, string $code, callable $operation, bool $inactive = false): mixed
{
    if (!controlesCuentaDisponibles() || !registroSesionesDisponible()) {
        throw new RuntimeException('Importa la actualización de cuenta para continuar.');
    }
    $db = getDB();
    if ($db->inTransaction()) {
        throw new LogicException('La confirmación necesita su propia transacción.');
    }
    $fingerprints = array_merge(huellasLimite('reauth:user:' . $uid), huellasLimite('reauth:ip:' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown')));
    $db->beginTransaction();
    try {
        $db->query('SELECT id FROM musicmad_security_guard WHERE id=1 FOR UPDATE')->fetchColumn();
        if (limiteBloqueado($db, $fingerprints)) {
            throw new OverflowException('Demasiados intentos. Espera quince minutos.');
        }
        $st = $db->prepare('SELECT id,REPLACE_WITH_LOCAL_VALUE,activo,rol FROM usuarios WHERE id=? FOR UPDATE');
        $st->execute([$uid]);
        $u = $st->fetch();
        $valid = strlen($REPLACE_WITH_LOCAL_VALUE) <= 72 && !str_contains($REPLACE_WITH_LOCAL_VALUE, chr(0)) && verificarPasswordConTiempo($REPLACE_WITH_LOCAL_VALUE, $u ? $u['REPLACE_WITH_LOCAL_VALUE'] : null);
        if (!$u || !$valid || (!$inactive && !(int)$u['activo'])) {
            guardarIntentoLimite($db, $fingerprints, false);
            $db->commit();
            throw new DomainException('No se pudo confirmar tu acceso. Revisa la contraseña.');
        }
        if (tieneSegundoFactor($uid) && !consumirSegundoFactor($uid, $code)) {
            $db->commit();
            throw new DomainException('Confirma un código nuevo de seguridad o recuperación.');
        }
        guardarIntentoLimite($db, $fingerprints, true);
        $result = $operation($db, $u);
        $db->commit();
        return $result;
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $error;
    }
}
function asignarRolSitio(int $admin, int $target, string $role, string $REPLACE_WITH_LOCAL_VALUE, string $code): void
{
    if (!in_array($role, ['usuario','moderador','administrador'], true) || $target < 1) {
        throw new InvalidArgumentException('Elige una cuenta y un permiso válido.');
    }
    operacionCuentaConfirmada($admin, $REPLACE_WITH_LOCAL_VALUE, $code, function (PDO $db, array $actor) use ($admin, $target, $role) {
        if ($actor['rol'] !== 'administrador') {
            throw new OutOfBoundsException('Solo administración puede cambiar permisos.');
        }
        $st = $db->prepare('SELECT rol FROM usuarios WHERE id=? AND activo=1 FOR UPDATE');
        $st->execute([$target]);
        $current = $st->fetchColumn();
        if ($current === false) {
            throw new OutOfBoundsException('Esta cuenta no admite ese cambio.');
        }
        if ($current === 'administrador' && $role !== 'administrador' && (int)$db->query("SELECT COUNT(*) FROM usuarios WHERE rol='administrador' AND activo=1")->fetchColumn() <= 1) {
            throw new LogicException('Debe quedar al menos una cuenta administradora activa.');
        }
        $db->prepare('UPDATE usuarios SET rol=? WHERE id=?')->execute([$role === 'administrador' ? 'administrador' : 'cliente',$target]);
        if ($role === 'moderador') {
            $db->prepare("INSERT INTO musicmad_site_roles(usuario_id,rol,asignado_por,updated_at) VALUES(?,'moderador',?,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE asignado_por=VALUES(asignado_por),updated_at=UTC_TIMESTAMP()")->execute([$target,$admin]);
        } else {
            $db->prepare('DELETE FROM musicmad_site_roles WHERE usuario_id=?')->execute([$target]);
        }
        revocarAccesosCuenta($target);
        auditarCuenta($admin, $role === 'usuario' ? 'rol_retirado' : 'rol_asignado', 'cuenta', $target);
    });
}
function solicitarEliminacionCuenta(int $uid, string $REPLACE_WITH_LOCAL_VALUE, string $code): void
{
    if (!bandasWorkspaceDisponible() || !eventosWorkspaceDisponible()) {
        throw new RuntimeException('Completa las migraciones de bandas y eventos antes de eliminar una cuenta.');
    }
    operacionCuentaConfirmada($uid, $REPLACE_WITH_LOCAL_VALUE, $code, function (PDO $db, array $u) use ($uid) {
        if ($u['rol'] === 'administrador' && (int)$db->query("SELECT COUNT(*) FROM usuarios WHERE rol='administrador' AND activo=1")->fetchColumn() <= 1) {
            throw new LogicException('La comunidad necesita otra cuenta administradora antes de eliminar esta.');
        }
        $st = $db->prepare('SELECT banda_id FROM banda_miembros WHERE usuario_id=? UNION SELECT id FROM bandas WHERE creador_id=? ORDER BY banda_id');
        $st->execute([$uid,$uid]);
        $ids = $st->fetchAll(PDO::FETCH_COLUMN);
        foreach ($ids as $band) {
            $db->prepare('SELECT id FROM bandas WHERE id=? FOR UPDATE')->execute([$band]);
            $db->prepare('DELETE FROM banda_miembros WHERE banda_id=? AND usuario_id=?')->execute([$band,$uid]);
            mantenerAdministracionBanda($db, (int)$band, $uid);
        }
        // Shared bands keep their active successor; invitations from this account stop.
        $db->prepare("UPDATE musicmad_band_invites SET estado='cancelada',respondida_en=UTC_TIMESTAMP() WHERE estado='pendiente' AND (invitado_id=? OR invitado_por=?)")->execute([$uid,$uid]);
        $db->prepare('INSERT INTO musicmad_account_deletions(usuario_id,solicitada_en,ejecutar_en) VALUES(?,UTC_TIMESTAMP(),UTC_TIMESTAMP()+INTERVAL 7 DAY) ON DUPLICATE KEY UPDATE solicitada_en=VALUES(solicitada_en),ejecutar_en=VALUES(ejecutar_en),estado=\'pendiente\'')->execute([$uid]);
        $db->prepare('UPDATE usuarios SET activo=0 WHERE id=?')->execute([$uid]);
        revocarAccesosCuenta($uid);
        auditarCuenta($uid, 'eliminacion_solicitada');
    });
}
function cancelarEliminacionCuenta(string $email, string $REPLACE_WITH_LOCAL_VALUE, string $code): bool
{
    $fp = huellasLogin($email);
    if (loginBloqueado($fp)) {
        throw new OverflowException('Demasiados intentos. Espera quince minutos.');
    }
    $st = getDB()->prepare('SELECT id FROM usuarios WHERE email=?');
    $st->execute([trim($email)]);
    $uid = (int)$st->fetchColumn();
    if (!$uid) {
        verificarPasswordConTiempo($REPLACE_WITH_LOCAL_VALUE, null);
        registrarIntentoLogin($fp, false);
        throw new DomainException('No se pudo recuperar la cuenta con esos datos o fuera del plazo de siete días.');
    }
    try {
        return operacionCuentaConfirmada($uid, $REPLACE_WITH_LOCAL_VALUE, $code, function (PDO $db, array $u) use ($uid) {
            $st = $db->prepare("SELECT 1 FROM musicmad_account_deletions WHERE usuario_id=? AND estado='pendiente' AND ejecutar_en>UTC_TIMESTAMP() FOR UPDATE");
            $st->execute([$uid]);
            if ((int)$u['activo'] !== 0 || !$st->fetchColumn()) {
                throw new DomainException('No hay una eliminación recuperable con esos datos.');
            }
            $db->prepare("UPDATE musicmad_account_deletions SET estado='cancelada' WHERE usuario_id=?")->execute([$uid]);
            $db->prepare('UPDATE usuarios SET activo=1 WHERE id=?')->execute([$uid]);
            revocarAccesosCuenta($uid);
            auditarCuenta($uid, 'eliminacion_cancelada');
            return true;
        }, true);
    } catch (DomainException $error) {
        registrarIntentoLogin($fp, false);
        throw new DomainException('No se pudo recuperar la cuenta con esos datos o fuera del plazo de siete días.');
    }
}
function historialSeguridadCuenta(int $uid, int $before = PHP_INT_MAX): array
{
    $st = getDB()->prepare('SELECT id,accion,objeto,objeto_id,created_at FROM auditoria_acciones WHERE usuario_id=? AND id<? ORDER BY id DESC LIMIT 31');
    $st->execute([$uid,$before]);
    return $st->fetchAll();
}


/** Every moderation mutation rechecks the server role under the same lock as role assignment. */
function transaccionModeracion(int $uid, callable $operation): mixed
{
    $db = getDB();
    $owns = !$db->inTransaction();
    if ($owns) {
        $db->beginTransaction();
    }
    try {
        if (controlesCuentaDisponibles()) {
            $db->query('SELECT id FROM musicmad_security_guard WHERE id=1 FOR UPDATE')->fetchColumn();
        }
        $st = $db->prepare('SELECT id FROM usuarios WHERE id=? AND activo=1 FOR UPDATE');
        $st->execute([$uid]);
        if (!$st->fetchColumn() || !puedeModerar($uid)) {
            throw new OutOfBoundsException('No tienes permiso para moderar.');
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


function cambiarPasswordCuentaConfirmada(int $uid, string $actual, string $nueva, string $confirmar, string $code): void
{
    if (mb_strlen($nueva) < 12 || strlen($nueva) > 72 || str_contains($nueva, chr(0))) {
        throw new InvalidArgumentException('La nueva contraseña debe tener al menos doce caracteres y máximo72 bytes.');
    }
    if ($nueva !== $confirmar) {
        throw new InvalidArgumentException('La confirmación no coincide.');
    }
    if (hash_equals($actual, $nueva)) {
        throw new InvalidArgumentException('Elige una contraseña distinta de la actual.');
    }
    operacionCuentaConfirmada($uid, $actual, $code, function (PDO $db) use ($uid, $nueva) {
        $db->prepare('UPDATE usuarios SET REPLACE_WITH_LOCAL_VALUE=? WHERE id=? AND activo=1')->execute([REPLACE_WITH_LOCAL_VALUE_hash($nueva, PASSWORD_BCRYPT, ['cost' => 12]),$uid]);
        revocarAccesosCuenta($uid);
        auditarCuenta($uid, 'REPLACE_WITH_LOCAL_VALUE_cambiado');
    });
    revocarCookieRecordada();
    session_regenerate_id(true);
    registrarDispositivoSesion($uid);
    unset($_SESSION['csrf']);
}
