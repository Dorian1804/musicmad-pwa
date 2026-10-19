<?php

/** Registration policy is separate from proving mailbox ownership by a link. */
function evaluarCorreoRegistro(string $email, ?callable $dns = null): array
{
    $email = trim($email);
    $failure = static fn (string $message): array => ['ok' => false, 'mensaje' => $message];
    if (strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return $failure('Escribe un correo electrónico válido.');
    }
    [$local, $domain] = explode('@', strtolower($email), 2);
    $reserved = ['example.com', 'example.org', 'example.net', 'ejemplo.com', 'ejemplo.org'];
    foreach ($reserved as $blocked) {
        if ($domain === $blocked || str_ends_with($domain, '.' . $blocked)) {
            return $failure('Usa tu correo real, no una dirección de ejemplo.');
        }
    }
    if (preg_match('/\.(invalid|test|localhost|example)$/D', $domain)
        || !str_contains($domain, '.')
        || preg_match('/^(asdf|qwer|zxcv|test|prueba)([0-9._+-]*)$/D', $local)
        || preg_match('/^([a-z0-9])\1{3,}(?:[0-9]*)$/D', $local)) {
        return $failure('Usa un correo personal que puedas abrir para confirmarlo.');
    }
    $blockedDomains = require __DIR__ . '/email_disposable_domains.php';
    foreach ($blockedDomains as $blocked) {
        if ($domain === $blocked || str_ends_with($domain, '.' . $blocked)) {
            return $failure('Los correos temporales no están permitidos. Usa un correo permanente.');
        }
    }
    $typos = ['gmial.com' => 'gmail.com', 'gmal.com' => 'gmail.com', 'gamil.com' => 'gmail.com',
        'gmail.con' => 'gmail.com', 'hotmial.com' => 'hotmail.com', 'hotmal.com' => 'hotmail.com',
        'outlok.com' => 'outlook.com', 'outllok.com' => 'outlook.com', 'yaho.com' => 'yahoo.com'];
    if (isset($typos[$domain])) {
        return $failure('¿Quisiste escribir ' . $local . '@' . $typos[$domain] . '? Corrige el correo para continuar.');
    }
    $dns ??= 'dominioCorreoRecibeMensajes';
    $available = $dns($domain);
    if ($available === null) {
        return $failure('No pudimos comprobar el dominio del correo. Reintenta en un momento.');
    }
    if (!$available) {
        return $failure('El dominio de ese correo no puede recibir mensajes. Revisa que esté bien escrito.');
    }
    return ['ok' => true, 'mensaje' => '', 'email' => $email];
}

/** Fixed HTTPS DNS resolver: no user-controlled hosts, redirects or SMTP probes. */
function consultaDnsCorreo(string $domain, string $type): ?array
{
    if (!in_array($type, ['MX', 'A'], true) || strlen($domain) > 253
        || !preg_match('/^[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?$/D', $domain)
        || !function_exists('curl_init')) {
        return null;
    }
    $curl = curl_init('https://dns.google/resolve?name=' . rawurlencode($domain) . '&type=' . $type);
    if ($curl === false) {
        return null;
    }
    $body = '';
    curl_setopt_array($curl, [CURLOPT_CONNECTTIMEOUT_MS => 500, CURLOPT_TIMEOUT_MS => 1000,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => ['Accept: application/dns-json'],
        CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body): int {
            if (strlen($body) + strlen($chunk) > 32768) {
                return 0;
            }
            $body .= $chunk;
            return strlen($chunk);
        }]);
    try {
        $ok = curl_exec($curl);
        $response = $ok !== false && curl_getinfo($curl, CURLINFO_RESPONSE_CODE) === 200
            ? json_decode($body, true) : null;
        return is_array($response) && isset($response['Status']) ? $response : null;
    } finally {
        curl_close($curl);
    }
}

/** At most two one-second queries. Null MX explicitly forbids A fallback. */
function dominioCorreoRecibeMensajes(string $domain, ?callable $query = null): ?bool
{
    $query ??= 'consultaDnsCorreo';
    $mx = $query($domain, 'MX');
    if ($mx === null || !in_array($mx['Status'] ?? -1, [0, 3], true)) {
        return null;
    }
    if ($mx['Status'] === 3) {
        return false;
    }
    foreach ($mx['Answer'] ?? [] as $answer) {
        if (($answer['type'] ?? null) !== 15) {
            continue;
        }
        $value = trim((string) ($answer['data'] ?? ''));
        if (preg_match('/^0\s+\.$/D', $value)) {
            return false;
        }
        if (preg_match('/^[0-9]+\s+[a-z0-9][a-z0-9.-]+\.?$/iD', $value)) {
            return true;
        }
    }
    $a = $query($domain, 'A');
    if ($a === null || !in_array($a['Status'] ?? -1, [0, 3], true)) {
        return null;
    }
    foreach ($a['Answer'] ?? [] as $answer) {
        if (($answer['type'] ?? null) === 1 && filter_var($answer['data'] ?? '', FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return true;
        }
    }
    return false;
}
