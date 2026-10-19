<?php

require_once __DIR__ . '/totp.php';
function reautenticarCuentaBloqueada(int $uid, string $REPLACE_WITH_LOCAL_VALUE): bool
{
    $db = getDB();
    $fingerprints = huellasLimite('reauth:' . $uid);
    if (limiteBloqueado($db, $fingerprints)) {
        return false;
    }
    $st = $db->prepare('SELECT REPLACE_WITH_LOCAL_VALUE FROM usuarios WHERE id=? AND activo=1 FOR UPDATE');
    $st->execute([$uid]);
    $hash = $st->fetchColumn();
    $ok = is_string($hash) && strlen($REPLACE_WITH_LOCAL_VALUE) <= 72 && !str_contains($REPLACE_WITH_LOCAL_VALUE, "\0") && REPLACE_WITH_LOCAL_VALUE_verify($REPLACE_WITH_LOCAL_VALUE, $hash);
    guardarIntentoLimite($db, $fingerprints, $ok);
    return $ok;
}
function prepararSegundoFactor(int $uid, string $REPLACE_WITH_LOCAL_VALUE): array
{
    if (!totpDisponible()) {
        return ['ok' => false,'mensaje' => 'La verificación en dos pasos no está disponible todavía.'];
    }
    $db = getDB();
    $db->beginTransaction();
    try {
        if (!reautenticarCuentaBloqueada($uid, $REPLACE_WITH_LOCAL_VALUE)) {
            $db->commit();
            return ['ok' => false,'mensaje' => 'Contraseña incorrecta o demasiados intentos.'];
        }
        if (tieneSegundoFactor($uid)) {
            $db->commit();
            return ['ok' => false,'mensaje' => 'La verificación ya está activada.'];
        }
        $secret = base32Codificar(random_bytes(20));
        $cipher = cifrarSecretoMusicMad($secret, 'totp', (string)$uid);
        $db->prepare('INSERT INTO autenticacion_2fa(usuario_id,secreto_cifrado,habilitada) VALUES(?,?,0) ON DUPLICATE KEY UPDATE secreto_cifrado=VALUES(secreto_cifrado),habilitada=0,confirmada_en=NULL')->execute([$uid,$cipher]);
        $db->prepare('INSERT INTO musicmad_2fa_state(usuario_id,ultimo_contador) VALUES(?,-1) ON DUPLICATE KEY UPDATE ultimo_contador=-1')->execute([$uid]);
        $db->commit();
        $_SESSION['totp_setup'] = ['uid' => $uid,'cipher' => hash('sha256', $cipher),'expira' => time() + 600];
        return ['ok' => true,'mensaje' => 'Agrega MusicMad a tu app autenticadora y confirma un código.'];
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $error;
    }
}
function secretoSegundoFactorPendiente(int $uid): ?string
{
    $pending = $_SESSION['totp_setup'] ?? null;
    if (!is_array($pending) || ($pending['uid'] ?? 0) !== $uid || ($pending['expira'] ?? 0) < time()) {
        return null;
    }
    $st = getDB()->prepare('SELECT secreto_cifrado FROM autenticacion_2fa WHERE usuario_id=? AND habilitada=0');
    $st->execute([$uid]);
    $cipher = $st->fetchColumn();
    if (!is_string($cipher) || !hash_equals($pending['cipher'], hash('sha256', $cipher))) {
        return null;
    }
    return descifrarSecretoMusicMad($cipher, 'totp', (string)$uid);
}
function nuevosCodigosRecuperacion(int $uid): array
{
    $db = getDB();
    $db->prepare('DELETE FROM musicmad_2fa_recovery WHERE usuario_id=?')->execute([$uid]);
    $codes = [];
    for ($i = 0;$i < 10;$i++) {
        $code = base32Codificar(random_bytes(12)) . 'A';
        $code = substr($code, 0, 20);
        $hash = hash_hmac('sha256', $code, claveCifradoMusicMad('recovery'));
        $db->prepare('INSERT INTO musicmad_2fa_recovery(usuario_id,codigo_hash) VALUES(?,?)')->execute([$uid,$hash]);
        $codes[] = implode('-', str_split($code, 5));
    }
    return $codes;
}
function confirmarSegundoFactor(int $uid, string $code): array
{
    $db = getDB();
    $db->beginTransaction();
    try {
        $db->prepare('SELECT id FROM usuarios WHERE id=? AND activo=1 FOR UPDATE')->execute([$uid]);
        $secret = secretoSegundoFactorPendiente($uid);
        $fingerprints = huellaSegundoFactor($uid);
        if ($secret === null || limiteBloqueado($db, $fingerprints)) {
            $db->commit();
            return ['ok' => false,'mensaje' => 'Vuelve a preparar la verificación o espera si alcanzaste el límite.'];
        }
        $counter = contadorTotpValido($secret, trim($code), time());
        guardarIntentoLimite($db, $fingerprints, $counter !== null);
        if ($counter === null) {
            $db->commit();
            return ['ok' => false,'mensaje' => 'El código no coincide. Revisa la hora automática de tu teléfono.'];
        }
        $db->prepare('UPDATE autenticacion_2fa SET habilitada=1,confirmada_en=UTC_TIMESTAMP() WHERE usuario_id=? AND habilitada=0')->execute([$uid]);
        $db->prepare('UPDATE musicmad_2fa_state SET ultimo_contador=? WHERE usuario_id=?')->execute([$counter,$uid]);
        $codes = nuevosCodigosRecuperacion($uid);
        revocarAccesosCuenta($uid);
        revocarCookieRecordada();
        session_regenerate_id(true);
        registrarDispositivoSesion($uid);
        unset($_SESSION['csrf'],$_SESSION['totp_setup']);
        $db->commit();
        return ['ok' => true,'mensaje' => 'Verificación en dos pasos activada. Cerramos los demás accesos.','codigos' => $codes];
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $error;
    }
}
function cambiarSegundoFactor(int $uid, string $REPLACE_WITH_LOCAL_VALUE, string $code, bool $disable): array
{
    $db = getDB();
    $db->beginTransaction();
    try {
        if (!reautenticarCuentaBloqueada($uid, $REPLACE_WITH_LOCAL_VALUE) || !consumirSegundoFactor($uid, trim($code))) {
            $db->commit();
            return ['ok' => false,'mensaje' => 'Contraseña o código incorrectos; usa un código nuevo.'];
        }
        $codes = [];
        if ($disable) {
            $db->prepare('DELETE FROM autenticacion_2fa WHERE usuario_id=?')->execute([$uid]);
            $db->prepare('DELETE FROM musicmad_2fa_state WHERE usuario_id=?')->execute([$uid]);
            $db->prepare('DELETE FROM musicmad_2fa_recovery WHERE usuario_id=?')->execute([$uid]);
        } else {
            $codes = nuevosCodigosRecuperacion($uid);
        }
        revocarAccesosCuenta($uid);
        revocarCookieRecordada();
        session_regenerate_id(true);
        registrarDispositivoSesion($uid);
        unset($_SESSION['csrf']);
        $db->commit();
        return ['ok' => true,'mensaje' => $disable ? 'Verificación desactivada. Cerramos los demás accesos.' : 'Códigos reemplazados. Los anteriores ya no funcionan.','codigos' => $codes];
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $error;
    }
}
