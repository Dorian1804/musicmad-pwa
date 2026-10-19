<?php

require_once __DIR__ . '/jobs.php';
function resolverParametroCorreo(string $name, string|false $environment, mixed $legacy = null): string
{
    if (!in_array($name, ['HOST','PORT','USER','PASS','FROM','NAME','ENCRYPTION','APP_URL','TRANSPORT'], true)) {
        throw new InvalidArgumentException('Parámetro de correo no válido.');
    }
    // Never trim REPLACE_WITH_LOCAL_VALUEs: spaces can be part of an SMTP credential.
    if ($environment !== false && $environment !== '') {
        return $environment;
    }
    if ((is_string($legacy) || is_int($legacy)) && (string)$legacy !== '') {
        return (string)$legacy;
    }
    return match($name) {
        'PORT' => '587','NAME' => 'MusicMad','TRANSPORT' => 'smtp',default => ''
    };
}
function parametroCorreoMusicMad(string $name): string
{
    $constant = 'MAIL_' . $name;
    $environment = getenv($name === 'APP_URL' ? 'MM_APP_URL' : 'MM_MAIL_' . $name);
    return resolverParametroCorreo($name, $environment, defined($constant) ? constant($constant) : null);
}
function correoConfigurado(): bool
{
    if (!colaTrabajosDisponible() || filter_var(parametroCorreoMusicMad('FROM'), FILTER_VALIDATE_EMAIL) === false) {
        return false;
    }
    return match (parametroCorreoMusicMad('TRANSPORT')) {
        'mail' => function_exists('mail'),
        'smtp' => parametroCorreoMusicMad('HOST') !== '' && in_array(parametroCorreoMusicMad('ENCRYPTION'), ['tls','ssl'], true)
            && filter_var(parametroCorreoMusicMad('PORT'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1,'max_range' => 65535]]) !== false,
        default => false,
    };
}

/** The selected transport is explicit: a failed SMTP send must never be sent twice through a fallback. */
function crearClienteCorreoMusicMad(): PHPMailer\PHPMailer\PHPMailer
{
    require_once dirname(__DIR__) . '/vendor/autoload.php';
    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    $transport = parametroCorreoMusicMad('TRANSPORT');
    if ($transport === 'mail') {
        if (!function_exists('mail')) {
            throw new RuntimeException('El hosting no permite el correo local de PHP.');
        }
        $mail->isMail();
        // Do not pass shell arguments or a caller-controlled envelope sender to the local MTA.
        $mail->UseSendmailOptions = false;
        return $mail;
    }
    if ($transport !== 'smtp') {
        throw new RuntimeException('Transporte de correo no válido.');
    }
    $mail->isSMTP();
    $mail->Host = parametroCorreoMusicMad('HOST');
    $mail->Port = (int)parametroCorreoMusicMad('PORT');
    $mail->SMTPAuth = parametroCorreoMusicMad('USER') !== '';
    $mail->Username = parametroCorreoMusicMad('USER');
    $mail->Password = parametroCorreoMusicMad('PASS');
    $mail->SMTPSecure = parametroCorreoMusicMad('ENCRYPTION') === 'ssl' ? PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS : PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
    return $mail;
}
function direccionCanonicaCorreo(): string
{
    // MAIL_APP_URL must be explicit; never use a URL derived from the Host header.
    $url = rtrim(parametroCorreoMusicMad('APP_URL'), '/');
    $parts = parse_url($url);
    if (!$parts || !isset($parts['host'],$parts['scheme']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']) || !filter_var($url, FILTER_VALIDATE_URL)) {
        throw new RuntimeException('Configura MM_APP_URL.');
    }
    $local = in_array($parts['host'], ['127.0.0.1','localhost'], true);
    if ($parts['scheme'] !== 'https' && !(defined('MUSICMAD_TEST_RUN') && MUSICMAD_TEST_RUN && $local && $parts['scheme'] === 'http')) {
        throw new RuntimeException('El correo requiere una dirección HTTPS configurada.');
    }
    return $url;
}
function enviarCorreoTrabajo(array $payload): void
{
    if (!correoConfigurado()) {
        throw new RuntimeException('Correo no configurado o transporte no disponible.');
    }
    $to = $payload['to'] ?? null;
    $subject = $payload['subject'] ?? null;
    $body = $payload['body'] ?? null;
    if (!is_string($to) || !filter_var($to, FILTER_VALIDATE_EMAIL) || !is_string($subject) || strlen($subject) > 180 || preg_match('/[\r\n]/', $subject) || !is_string($body) || strlen($body) > 32768) {
        throw new InvalidArgumentException('Correo no válido.');
    }
    $mail = crearClienteCorreoMusicMad();
    if (defined('MUSICMAD_TEST_RUN') && MUSICMAD_TEST_RUN && PHP_SAPI === 'cli') {
        require_once dirname(__DIR__) . '/tests/paths.php';
        $ca = realpath((string)getenv('MM_TEST_MAIL_CA'));
        $qaRoot = realpath(musicmadQaRoot());
        if ($ca && $qaRoot && dirname($ca) === $qaRoot) {
            $mail->SMTPOptions = ['ssl' => ['cafile' => $ca,'verify_peer' => true,'verify_peer_name' => true,'allow_self_signed' => false]];
        }
    }
    if (isset($payload['expira']) && (!is_int($payload['expira']) || $payload['expira'] < time())) {
        return;
    }
    $mail->SMTPDebug = 0;
    $mail->Timeout = 10;
    $mail->getSMTPInstance()->Timelimit = 15;
    $mail->CharSet = 'UTF-8';
    $mail->setFrom(parametroCorreoMusicMad('FROM'), parametroCorreoMusicMad('NAME'));
    $mail->addAddress($to);
    $mail->Subject = $subject;
    $mail->Body = $body;
    $mail->isHTML(false);
    $mail->send();
}

/** Claim only the message just queued by this request; never run the global queue over HTTP. */
function despacharCorreoInmediato(string $dedupe, ?callable $sender = null): bool
{
    if (!preg_match('/^[a-f0-9]{64}$/D', $dedupe)) {
        throw new InvalidArgumentException('Referencia de correo no válida.');
    }
    $db = getDB();
    if ($db->inTransaction()) {
        return false;
    }
    $lease = bin2hex(random_bytes(16));
    $claim = $db->prepare("UPDATE musicmad_jobs SET estado='procesando',lease_key=?,reservado_hasta=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 120 SECOND),intentos=intentos+1 WHERE tipo='correo' AND dedupe=? AND estado='pendiente' AND intentos=0 AND reservado_hasta IS NULL AND disponible_en<=UTC_TIMESTAMP()");
    $claim->execute([$lease,$dedupe]);
    if ($claim->rowCount() !== 1) {
        return false;
    }
    $query = $db->prepare("SELECT * FROM musicmad_jobs WHERE dedupe=? AND lease_key=? AND tipo='correo' AND estado='procesando'");
    $query->execute([$dedupe,$lease]);
    $job = $query->fetch();
    if (!$job) {
        return false;
    }
    try {
        $payload = json_decode(descifrarSecretoMusicMad($job['payload'], 'jobs', $job['dedupe']), true, 512, JSON_THROW_ON_ERROR);
        ($sender ?? 'enviarCorreoTrabajo')($payload);
        terminarTrabajo($job);
        return true;
    } catch (Throwable $error) {
        terminarTrabajo($job, $error);
        error_log('MusicMad immediate mail failed: ' . get_class($error));
        return false;
    }
}
