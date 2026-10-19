<?php

/**
 * Clave HMAC independiente. En la primera ejecución crea solo MM_ENC_KEY y
 * una ventana de transición de 30 minutos en .env, conservando su contenido.
 * No modifica credenciales DB/OAuth/JWT ni muestra valores secretos.
 */
function prepararClaveLimites(): string
{
    $configured = defined('MM_ENC_KEY') ? (string) MM_ENC_KEY : (string) (getenv('MM_ENC_KEY') ?: '');
    if ($configured !== '') {
        if (strlen($configured) < 32 || preg_match('/reemplaza|tu_clave/i', $configured)) {
            throw new RuntimeException('Configura MM_ENC_KEY con una clave aleatoria de al menos 32 caracteres.');
        }
        return $configured;
    }
    $path = dirname(__DIR__) . '/.env';
    if (is_link($path)) {
        throw new RuntimeException('No se pudo preparar la clave de seguridad.');
    }
    $handle = @fopen($path, 'c+');
    if (!$handle) {
        throw new RuntimeException('Configura MM_ENC_KEY en .env o config/local.php; el hosting no permite crearla automáticamente.');
    }
    try {
        if (!flock($handle, LOCK_EX)) {
            throw new RuntimeException('No se pudo preparar la clave de seguridad.');
        }
        $content = stream_get_contents($handle);
        $values = is_string($content) ? @parse_ini_string($content, false, INI_SCANNER_RAW) : false;
        if ($content !== '' && !is_array($values)) {
            throw new RuntimeException('Revisa el formato de .env.');
        }
        $key = is_array($values) ? ($values['MM_ENC_KEY'] ?? '') : '';
        if (!is_string($key)) {
            throw new RuntimeException('Revisa MM_ENC_KEY.');
        }
        if ($key === '') {
            $key = bin2hex(random_bytes(32));
            $until = time() + 1800;
            $addition = "\n# MusicMad: clave independiente y transición temporal de rate limiting\nMM_ENC_KEY=\"$key\"\nMM_ENC_KEY_LEGACY_UNTIL=$until\n";
            fseek($handle, 0, SEEK_END);
            if (fwrite($handle, $addition) !== strlen($addition) || !fflush($handle)) {
                throw new RuntimeException('No se pudo guardar la clave de seguridad.');
            }
            @chmod($path, 0600);
        } else {
            $until = (int) ($values['MM_ENC_KEY_LEGACY_UNTIL'] ?? 0);
        }
        if (strlen($key) < 32) {
            throw new RuntimeException('MM_ENC_KEY requiere al menos 32 caracteres.');
        }
        putenv('MM_ENC_KEY=' . $key);
        putenv('MM_ENC_KEY_LEGACY_UNTIL=' . $until);
        return $key;
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

function huellasConClaves(string $identity, string $current, ?string $legacy = null): array
{
    $keys = [$current];
    if ($legacy !== null) {
        $keys[] = $legacy;
    }
    return array_values(array_unique(array_map(static fn ($key) => hash_hmac('sha256', $identity, $key), $keys)));
}

function huellasLimite(string $identity): array
{
    $current = prepararClaveLimites();
    $until = defined('MM_ENC_KEY_LEGACY_UNTIL') ? (int) MM_ENC_KEY_LEGACY_UNTIL : (int) (getenv('MM_ENC_KEY_LEGACY_UNTIL') ?: 0);
    // Solo el puente de migración usa la derivación anterior, y vence.
    $legacy = $until > time() ? hash('sha256', DB_NAME . "\0" . DB_USER . "\0" . DB_PASS, true) : null;
    return huellasConClaves($identity, $current, $legacy);
}

function huellasLogin(string $email): array
{
    return huellasLimite(mb_strtolower(trim($email)) . "\0" . (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
}
function huellasRegistro(): array
{
    return huellasLimite('registro' . "\0" . (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
}
function limiteBloqueado(PDO $db, array $fingerprints): bool
{
    foreach ($fingerprints as $fingerprint) {
        $st = $db->prepare('SELECT (blocked_until IS NOT NULL AND blocked_until > NOW()) FROM login_attempts WHERE fingerprint=:f LIMIT 1');
        $st->execute([':f' => $fingerprint]);
        if ((int) $st->fetchColumn() === 1) {
            return true;
        }
    }
    return false;
}
function guardarIntentoLimite(PDO $db, array $fingerprints, bool $ok): void
{
    $ownsTransaction = !$db->inTransaction();
    if ($ownsTransaction) {
        $db->beginTransaction();
    }
    try {
        foreach ($fingerprints as $fingerprint) {
            if ($ok) {
                $db->prepare('DELETE FROM login_attempts WHERE fingerprint=:f')->execute([':f' => $fingerprint]);
            } else {
                $db->prepare('INSERT INTO login_attempts (fingerprint,attempts,window_started,blocked_until)
                    VALUES (:f,1,NOW(),NULL) ON DUPLICATE KEY UPDATE
                    attempts=IF(window_started < NOW()-INTERVAL 15 MINUTE,1,LEAST(attempts+1,255)),
                    blocked_until=IF(window_started < NOW()-INTERVAL 15 MINUTE,NULL,IF(attempts>=7,NOW()+INTERVAL 15 MINUTE,blocked_until)),
                    window_started=IF(window_started < NOW()-INTERVAL 15 MINUTE,NOW(),window_started)')
                   ->execute([':f' => $fingerprint]);
            }
        }
        if ($ownsTransaction) {
            $db->commit();
        }
    } catch (Throwable $e) {
        if ($ownsTransaction && $db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}
