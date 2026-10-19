<?php

require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/i18n.php';
function correoCuentaDisponible(): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    $st = getDB()->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN (?,?,?)');
    $st->execute(['musicmad_jobs','musicmad_email_verified','musicmad_email_token_bindings']);
    return $ready = (int)$st->fetchColumn() === 3;
}
function hashCorreoCuenta(string $email): string
{
    return hash('sha256', mb_strtolower(trim($email)));
}
function solicitarCorreoCuenta(string $email, string $type, bool $requireTransport = true): bool
{
    if (!in_array($type, ['verificar','recuperar'], true) || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) {
        throw new InvalidArgumentException('Correo no válido.');
    }
    if (!correoCuentaDisponible() || ($requireTransport && !correoConfigurado())) {
        throw new RuntimeException('Envío de correo no disponible.');
    }
    $base = direccionCanonicaCorreo();
    $db = getDB();
    $owns = !$db->inTransaction();
    if ($owns) {
        $db->beginTransaction();
    }
    try {
        $st = $db->prepare('SELECT id,nombre,email FROM usuarios WHERE email=? AND activo=1 FOR UPDATE');
        $st->execute([$email]);
        $user = $st->fetch();
        if (!$user) {
            if ($owns) {
                $db->commit();
            }
            return false;
        }
        $st = $db->prepare('SELECT COUNT(*) FROM email_tokens WHERE usuario_id=? AND tipo=? AND created_at>DATE_SUB(UTC_TIMESTAMP(),INTERVAL 15 MINUTE)');
        $st->execute([$user['id'],$type]);
        if ((int)$st->fetchColumn() >= 3) {
            if ($owns) {
                $db->commit();
            }
            return false;
        }
        $token = bin2hex(random_bytes(32));
        $hash = hash('sha256', $token);
        $minutes = $type === 'recuperar' ? 30 : 1440;
        $db->prepare('UPDATE email_tokens SET usado_en=UTC_TIMESTAMP() WHERE usuario_id=? AND tipo=? AND usado_en IS NULL')->execute([$user['id'],$type]);
        $db->prepare('INSERT INTO email_tokens(usuario_id,tipo,token_hash,expira_en,created_at) VALUES(?,?,?,DATE_ADD(UTC_TIMESTAMP(),INTERVAL ' . $minutes . ' MINUTE),UTC_TIMESTAMP())')->execute([$user['id'],$type,$hash]);
        $id = (int)$db->lastInsertId();
        $db->prepare('INSERT INTO musicmad_email_token_bindings(token_id,email_hash) VALUES(?,?)')->execute([$id,hashCorreoCuenta($user['email'])]);
        $path = $type === 'recuperar' ? 'restablecer.php' : 'confirmar-correo.php';
        $link = $base . '/' . $path . '?token=' . $token;
        $locale = idiomaCuentaMusicMad((int)$user['id']);
        $subject = mmTexto($type === 'recuperar' ? 'mail.reset.subject' : 'mail.verify.subject', [], $locale);
        $body = mmTexto('mail.body', ['intro' => mmTexto($type === 'recuperar' ? 'mail.reset.intro' : 'mail.verify.intro', [], $locale),'link' => $link,'expiry' => mmTexto($type === 'recuperar' ? '30 minutos' : '24 horas', [], $locale)], $locale);
        $dedupe = hash('sha256', 'email:' . $hash);
        encolarTrabajo('correo', ['to' => $user['email'],'subject' => $subject,'body' => $body,'expira' => time() + $minutes * 60], $dedupe, (int)$user['id']);
        if ($owns) {
            $db->commit();
        }
        if ($owns && $requireTransport && parametroCorreoMusicMad('TRANSPORT') === 'mail' && PHP_SAPI !== 'cli') {
            despacharCorreoInmediato($dedupe);
        }
        return true;
    } catch (Throwable $error) {
        if ($owns && $db->inTransaction()) {
            $db->rollBack();
        }
        throw $error;
    }
}
function contextoTokenCorreo(string $hash, string $type): ?array
{
    if (!correoCuentaDisponible() || !preg_match('/^[a-f0-9]{64}$/D', $hash) || !in_array($type, ['verificar','recuperar'], true)) {
        return null;
    }
    $st = getDB()->prepare('SELECT t.id,t.usuario_id,t.tipo,b.email_hash,u.email FROM email_tokens t JOIN musicmad_email_token_bindings b ON b.token_id=t.id JOIN usuarios u ON u.id=t.usuario_id AND u.activo=1 WHERE t.token_hash=? AND t.tipo=? AND t.usado_en IS NULL AND t.expira_en>UTC_TIMESTAMP()');
    $st->execute([$hash,$type]);
    $row = $st->fetch();
    return $row && hash_equals($row['email_hash'], hashCorreoCuenta($row['email'])) ? $row : null;
}
function consumirTokenCorreo(string $hash, string $type, ?string $REPLACE_WITH_LOCAL_VALUE = null): bool
{
    $context = contextoTokenCorreo($hash, $type);
    if (!$context) {
        return false;
    }
    if ($type === 'recuperar' && (!is_string($REPLACE_WITH_LOCAL_VALUE) || mb_strlen($REPLACE_WITH_LOCAL_VALUE) < 12 || strlen($REPLACE_WITH_LOCAL_VALUE) > 72 || str_contains($REPLACE_WITH_LOCAL_VALUE, chr(0)))) {
        throw new InvalidArgumentException('Usa al menos 12 caracteres y máximo 72 bytes.');
    }
    $db = getDB();
    $db->beginTransaction();
    try {
        $uid = (int)$context['usuario_id'];
        $db->prepare('SELECT id FROM usuarios WHERE id=? FOR UPDATE')->execute([$uid]);
        $lock = $db->prepare('SELECT id FROM email_tokens WHERE id=? AND usado_en IS NULL AND expira_en>UTC_TIMESTAMP() FOR UPDATE');
        $lock->execute([$context['id']]);
        if (!$lock->fetchColumn() || !contextoTokenCorreo($hash, $type)) {
            $db->commit();
            return false;
        }
        if ($type === 'recuperar') {
            $db->prepare('UPDATE usuarios SET REPLACE_WITH_LOCAL_VALUE=? WHERE id=?')->execute([REPLACE_WITH_LOCAL_VALUE_hash($REPLACE_WITH_LOCAL_VALUE, PASSWORD_BCRYPT, ['cost' => 12]),$uid]);
            revocarAccesosCuenta($uid);
        } else {
            $db->prepare('INSERT INTO musicmad_email_verified(usuario_id,email_hash,verificado_en) VALUES(?,?,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE email_hash=VALUES(email_hash),verificado_en=VALUES(verificado_en)')->execute([$uid,$context['email_hash']]);
        }
        $db->prepare('UPDATE email_tokens SET usado_en=UTC_TIMESTAMP() WHERE usuario_id=? AND tipo=? AND usado_en IS NULL')->execute([$uid,$type]);
        $db->commit();
        return true;
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $error;
    }
}
function correoCuentaVerificado(int $uid): bool
{
    if (!correoCuentaDisponible()) {
        return false;
    }
    $st = getDB()->prepare('SELECT v.email_hash,u.email FROM musicmad_email_verified v JOIN usuarios u ON u.id=v.usuario_id WHERE v.usuario_id=?');
    $st->execute([$uid]);
    $row = $st->fetch();
    return $row && hash_equals($row['email_hash'], hashCorreoCuenta($row['email']));
}
