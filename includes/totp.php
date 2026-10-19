<?php

/** RFC6238, SHA1, 30 seconds; each accepted counter is consumed once. */
function base32Codificar(string $bytes): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $output = '';
    $buffer = 0;
    $bits = 0;
    foreach (unpack('C*', $bytes) as $byte) {
        $buffer = ($buffer << 8) | $byte;
        $bits += 8;
        while ($bits >= 5) {
            $bits -= 5;
            $output .= $alphabet[($buffer >> $bits) & 31];
        }
        $buffer &= (1 << $bits) - 1;
    }
    if ($bits) {
        $output .= $alphabet[($buffer << (5 - $bits)) & 31];
    }
    return $output;
}
function base32Decodificar(string $secret): string
{
    $secret = strtoupper($secret);
    if (!preg_match('/^[A-Z2-7]{16,128}$/D', $secret)) {
        throw new InvalidArgumentException('Clave TOTP no válida.');
    }
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $output = '';
    $buffer = 0;
    $bits = 0;
    for ($i = 0;$i < strlen($secret);$i++) {
        $buffer = ($buffer << 5) | strpos($alphabet, $secret[$i]);
        $bits += 5;
        if ($bits >= 8) {
            $bits -= 8;
            $output .= chr(($buffer >> $bits) & 255);
        }
        $buffer &= (1 << $bits) - 1;
    }
    return $output;
}
function codigoTotp(string $secret, int $unix, int $digits = 6, string $algorithm = 'sha1'): string
{
    if ($unix < 0 || !in_array($digits, [6,8], true) || !in_array($algorithm, ['sha1','sha256','sha512'], true)) {
        throw new InvalidArgumentException('Parámetros TOTP no válidos.');
    }
    $counter = intdiv($unix, 30);
    $packed = pack('N2', intdiv($counter, 4294967296), $counter % 4294967296);
    $digest = hash_hmac($algorithm, $packed, base32Decodificar($secret), true);
    $offset = ord(substr($digest, -1)) & 15;
    $value = unpack('N', substr($digest, $offset, 4))[1] & 0x7fffffff;
    return str_pad((string)($value % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
}
function contadorTotpValido(string $secret, string $code, int $unix, int $last = -1): ?int
{
    if (!preg_match('/^[0-9]{6}$/D', $code)) {
        return null;
    }
    $now = intdiv($unix, 30);
    foreach ([0,-1,1] as $delta) {
        $counter = $now + $delta;
        if ($counter < 0 || $counter <= $last) {
            continue;
        }
        if (hash_equals(codigoTotp($secret, $counter * 30), $code)) {
            return $counter;
        }
    }
    return null;
}
function claveCifradoMusicMad(string $purpose): string
{
    return hash_hmac('sha256', 'musicmad-secret:' . $purpose, prepararClaveLimites(), true);
}
function cifrarSecretoMusicMad(string $value, string $purpose, string $owner): string
{
    if (!function_exists('openssl_encrypt')) {
        throw new RuntimeException('OpenSSL no disponible.');
    }
    $nonce = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($value, 'aes-256-gcm', claveCifradoMusicMad($purpose), OPENSSL_RAW_DATA, $nonce, $tag, $owner, 16);
    if ($cipher === false) {
        throw new RuntimeException('No se pudo proteger el secreto.');
    }
    return chr(1) . $nonce . $tag . $cipher;
}
function descifrarSecretoMusicMad(string $value, string $purpose, string $owner): string
{
    if (strlen($value) < 30 || ord($value[0]) !== 1 || !function_exists('openssl_decrypt')) {
        throw new RuntimeException('Secreto no disponible.');
    }
    $clear = openssl_decrypt(substr($value, 29), 'aes-256-gcm', claveCifradoMusicMad($purpose), OPENSSL_RAW_DATA, substr($value, 1, 12), substr($value, 13, 16), $owner);
    if (!is_string($clear)) {
        throw new RuntimeException('No se pudo verificar el secreto.');
    }
    return $clear;
}
function totpDisponible(): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    try {
        $st = getDB()->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN (?,?)');
        $st->execute(['musicmad_2fa_state','musicmad_2fa_recovery']);
        return $ready = (int)$st->fetchColumn() === 2 && function_exists('openssl_encrypt');
    } catch (PDOException $error) {
        if ($error->getCode() === '42S02') {
            return $ready = false;
        }
        throw $error;
    }
}
function tieneSegundoFactor(int $uid): bool
{
    try {
        $st = getDB()->prepare('SELECT habilitada FROM autenticacion_2fa WHERE usuario_id=?');
        $st->execute([$uid]);
        return (bool)$st->fetchColumn();
    } catch (PDOException $error) {
        if ($error->getCode() === '42S02') {
            return false;
        }
        throw $error;
    }
}
function huellaSegundoFactor(int $uid): array
{
    return huellasLimite('segundo-factor:' . $uid);
}
/** User row must be locked by the credential transaction before calling. */
function consumirSegundoFactor(int $uid, string $code): bool
{
    $code = trim($code);
    if (!totpDisponible()) {
        throw new RuntimeException('Segundo factor no disponible.');
    }
    $db = getDB();
    $fingerprints = huellaSegundoFactor($uid);
    if (limiteBloqueado($db, $fingerprints)) {
        return false;
    }
    $st = $db->prepare('SELECT f.secreto_cifrado,s.ultimo_contador FROM autenticacion_2fa f JOIN musicmad_2fa_state s ON s.usuario_id=f.usuario_id WHERE f.usuario_id=? AND f.habilitada=1 FOR UPDATE');
    $st->execute([$uid]);
    $row = $st->fetch();
    $ok = false;
    if ($row) {
        if (preg_match('/^[0-9]{6}$/D', $code)) {
            $secret = descifrarSecretoMusicMad($row['secreto_cifrado'], 'totp', (string)$uid);
            $counter = contadorTotpValido($secret, $code, time(), (int)$row['ultimo_contador']);
            if ($counter !== null) {
                $db->prepare('UPDATE musicmad_2fa_state SET ultimo_contador=? WHERE usuario_id=?')->execute([$counter,$uid]);
                $ok = true;
            }
        } else {
            $normalized = strtoupper(str_replace(['-',' '], '', $code));
            if (preg_match('/^[A-Z2-7]{20}$/D', $normalized)) {
                $hash = hash_hmac('sha256', $normalized, claveCifradoMusicMad('recovery'));
                $st = $db->prepare('UPDATE musicmad_2fa_recovery SET usado_en=UTC_TIMESTAMP() WHERE usuario_id=? AND codigo_hash=? AND usado_en IS NULL');
                $st->execute([$uid,$hash]);
                $ok = $st->rowCount() === 1;
            }
        }
    }
    guardarIntentoLimite($db, $fingerprints, $ok);
    return $ok;
}
