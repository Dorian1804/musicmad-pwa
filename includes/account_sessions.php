<?php

/** Session IDs and remember validators never leave the server. */
function registroSesionesDisponible(): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    try {
        getDB()->query('SELECT selector FROM musicmad_session_bindings LIMIT 0');
        return $ready = true;
    } catch (PDOException $error) {
        if ($error->getCode() === '42S02') {
            return $ready = false;
        }
        throw $error;
    }
}
function versionAccesoCuenta(int $uid): int
{
    getDB()->prepare('INSERT IGNORE INTO musicmad_cuenta_seguridad(usuario_id) VALUES(?)')->execute([$uid]);
    $st = getDB()->prepare('SELECT version_acceso FROM musicmad_cuenta_seguridad WHERE usuario_id=?');
    $st->execute([$uid]);
    return (int)$st->fetchColumn();
}
function etiquetaDispositivo(string $agent): string
{
    $os = str_contains($agent, 'Android') ? 'Android' : (preg_match('/iPhone|iPad/', $agent) ? 'iPhone / iPad' : (str_contains($agent, 'Windows') ? 'Windows' : (str_contains($agent, 'Macintosh') ? 'Mac' : 'Otro dispositivo')));
    $browser = str_contains($agent, 'Edg/') ? 'Edge' : (str_contains($agent, 'Firefox/') ? 'Firefox' : (str_contains($agent, 'Chrome/') ? 'Chrome' : (str_contains($agent, 'Safari/') ? 'Safari' : 'Navegador')));
    return $browser . ' · ' . $os;
}
function registrarDispositivoSesion(int $uid, ?string $remember = null): void
{
    if (!registroSesionesDisponible()) {
        return;
    }
    $db = getDB();
    $version = versionAccesoCuenta($uid);
    if ($remember !== null) {
        $existing = $db->prepare('SELECT d.selector FROM sesiones_dispositivos d JOIN musicmad_session_bindings b ON b.selector=d.selector WHERE d.usuario_id=? AND b.recordar_selector=? AND b.version_acceso=? AND b.expira_en>UTC_TIMESTAMP() AND d.revocada_en IS NULL LIMIT 1');
        $existing->execute([$uid,$remember,$version]);
        $selector = $existing->fetchColumn();
        if (is_string($selector)) {
            $db->prepare('UPDATE musicmad_session_bindings SET session_hash=? WHERE selector=?')->execute([hash_hmac('sha256', session_id(), prepararClaveLimites()),$selector]);
            $db->prepare('UPDATE sesiones_dispositivos SET ultimo_uso=UTC_TIMESTAMP() WHERE selector=?')->execute([$selector]);
            $_SESSION['device_selector'] = $selector;
            $_SESSION['auth_version'] = $version;
            unset($GLOBALS['musicmad_auth_checked']);
            return;
        }
    }
    $selector = bin2hex(random_bytes(16));
    $db->prepare('INSERT INTO sesiones_dispositivos(usuario_id,selector,etiqueta,ultimo_uso,created_at) VALUES(?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())')->execute([$uid,$selector,etiquetaDispositivo((string)($_SERVER['HTTP_USER_AGENT'] ?? ''))]);
    $db->prepare('INSERT INTO musicmad_session_bindings(selector,session_hash,recordar_selector,version_acceso,expira_en) VALUES(?,?,?,?,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 30 DAY))')->execute([$selector,hash_hmac('sha256', session_id(), prepararClaveLimites()),$remember,$version]);
    $_SESSION['device_selector'] = $selector;
    $_SESSION['auth_version'] = $version;
    unset($GLOBALS['musicmad_auth_checked']);
}
function dispositivoSesionValido(int $uid): bool
{
    if (!registroSesionesDisponible()) {
        return true;
    }
    $version = versionAccesoCuenta($uid);
    if (empty($_SESSION['device_selector'])) {
        // Existing pre-update sessions can enroll only before any global revocation.
        if ($version !== 0) {
            return false;
        }
        $remember = null;
        $cookie = $_COOKIE[COOKIE_RECORDAR] ?? '';
        if (is_string($cookie) && preg_match('/^([a-f0-9]{32})\.[a-f0-9]{64}$/D', $cookie, $m)) {
            $remember = $m[1];
        }
        registrarDispositivoSesion($uid, $remember);
    }
    if ((int)($_SESSION['auth_version'] ?? -1) !== $version) {
        return false;
    }
    $st = getDB()->prepare('SELECT d.selector FROM sesiones_dispositivos d JOIN musicmad_session_bindings b ON b.selector=d.selector WHERE d.usuario_id=:u AND d.selector=:s AND d.revocada_en IS NULL AND b.session_hash=:h AND b.version_acceso=:v AND b.expira_en>UTC_TIMESTAMP()');
    $st->execute([':u' => $uid,':s' => $_SESSION['device_selector'],':h' => hash_hmac('sha256', session_id(), prepararClaveLimites()),':v' => $version]);
    if (!$st->fetchColumn()) {
        return false;
    }
    getDB()->prepare('UPDATE sesiones_dispositivos SET ultimo_uso=UTC_TIMESTAMP() WHERE selector=? AND ultimo_uso<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 5 MINUTE)')->execute([$_SESSION['device_selector']]);
    return true;
}
function validarSesionActual(): bool
{
    $uid = (int)($_SESSION['usuario_id'] ?? 0);
    if ($uid < 1) {
        return false;
    }
    $cacheKey = $uid . ':' . session_id() . ':' . ($_SESSION['device_selector'] ?? '');
    if (($GLOBALS['musicmad_auth_checked'] ?? '') === $cacheKey) {
        return true;
    }
    try {
        $st = getDB()->prepare('SELECT rol,activo,avatar,nombre,apellido FROM usuarios WHERE id=?');
        $st->execute([$uid]);
        $user = $st->fetch();
        if (!$user || !(int)$user['activo'] || !dispositivoSesionValido($uid)) {
            revocarCookieRecordada();
            $_SESSION = [];
            return false;
        }
        $_SESSION['usuario_rol'] = $user['rol'];
        // Another device may have replaced the previous photo and retired its old file.
        $_SESSION['usuario_avatar'] = $user['avatar'] ?: AVATAR_DEFECTO;
        $_SESSION['usuario_nombre'] = $user['nombre'];
        $_SESSION['usuario_apellido'] = $user['apellido'];
        $GLOBALS['musicmad_auth_checked'] = $uid . ':' . session_id() . ':' . ($_SESSION['device_selector'] ?? '');
        return true;
    } catch (Throwable $error) {
        error_log('Session validation unavailable: ' . get_class($error));
        http_response_code(503);
        exit('El acceso no está disponible por el momento. Inténtalo de nuevo.');
    }
}
function dispositivosCuenta(int $uid): array
{
    if (!registroSesionesDisponible()) {
        return [];
    }
    $st = getDB()->prepare('SELECT d.id,d.selector,d.etiqueta,d.created_at,d.ultimo_uso,b.recordar_selector FROM sesiones_dispositivos d JOIN musicmad_session_bindings b ON b.selector=d.selector WHERE d.usuario_id=? AND d.revocada_en IS NULL AND b.expira_en>UTC_TIMESTAMP() ORDER BY d.ultimo_uso DESC LIMIT 50');
    $st->execute([$uid]);
    return $st->fetchAll();
}
function revocarDispositivoCuenta(int $uid, int $deviceId): bool
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
            if ($owns) {
                $db->commit();
            }
            return false;
        }
        $st = $db->prepare('SELECT d.selector,b.recordar_selector FROM sesiones_dispositivos d JOIN musicmad_session_bindings b ON b.selector=d.selector WHERE d.id=? AND d.usuario_id=? AND d.revocada_en IS NULL FOR UPDATE');
        $st->execute([$deviceId,$uid]);
        $row = $st->fetch();
        if (!$row) {
            if ($owns) {
                $db->commit();
            }
            return false;
        }
        $db->prepare('UPDATE sesiones_dispositivos SET revocada_en=UTC_TIMESTAMP() WHERE id=? AND usuario_id=?')->execute([$deviceId,$uid]);
        if ($row['recordar_selector']) {
            $db->prepare('DELETE FROM sesiones_recordadas WHERE selector=? AND usuario_id=?')->execute([$row['recordar_selector'],$uid]);
        }
        if (controlesCuentaDisponibles()) {
            auditarCuenta($uid, 'dispositivo_revocado');
        }
        if ($owns) {
            $db->commit();
        }
        unset($GLOBALS['musicmad_auth_checked']);
        return true;
    } catch (Throwable $error) {
        if ($owns && $db->inTransaction()) {
            $db->rollBack();
        }
        throw $error;
    }
}
/** Call inside a transaction with the user row locked when changing credentials. */
function revocarAccesosCuenta(int $uid): void
{
    $db = getDB();
    if (registroSesionesDisponible()) {
        $db->prepare('INSERT INTO musicmad_cuenta_seguridad(usuario_id,version_acceso) VALUES(?,1) ON DUPLICATE KEY UPDATE version_acceso=version_acceso+1')->execute([$uid]);
        $db->prepare('UPDATE sesiones_dispositivos SET revocada_en=UTC_TIMESTAMP() WHERE usuario_id=? AND revocada_en IS NULL')->execute([$uid]);
    }
    $db->prepare('DELETE FROM sesiones_recordadas WHERE usuario_id=?')->execute([$uid]);
    try {
        $db->prepare('UPDATE tokens_api SET revocado_en=NOW() WHERE usuario_id=? AND revocado_en IS NULL')->execute([$uid]);
    } catch (PDOException $error) {
        if ($error->getCode() !== '42S02') {
            throw $error;
        }
    }
    unset($GLOBALS['musicmad_auth_checked']);
}
