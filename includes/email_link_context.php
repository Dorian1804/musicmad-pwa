<?php

/** The URL token is exchanged for server session state; never copied to forms. */
function prepararContextoEnlaceCorreo(string $type, string $page): ?array
{
    if (isset($_GET['token'])) {
        $token = textoCampo($_GET, 'token');
        $context = preg_match('/^[a-f0-9]{64}$/D', $token) ? contextoTokenCorreo(hash('sha256', $token), $type) : null;
        if ($context) {
            $_SESSION['email_pending_' . $type] = ['hash' => hash('sha256', $token),'expira' => time() + 900];
        } else {
            unset($_SESSION['email_pending_' . $type]);
        }
        header('Referrer-Policy: no-referrer');
        header('Location: ' . $page);
        exit;
    }
    $pending = $_SESSION['email_pending_' . $type] ?? null;
    if (!is_array($pending) || ($pending['expira'] ?? 0) < time()) {
        return null;
    }
    return contextoTokenCorreo($pending['hash'], $type) ? $pending : null;
}
