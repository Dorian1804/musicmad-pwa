<?php

/** Consume a single browser-bound OAuth attempt before exchanging its code. */
function consumirRetornoGoogle(array &$session, array $query, int $now): string
{
    $state = $session['oauth2state'] ?? null;
    $verifier = $session['oauth_pkce'] ?? null;
    $expires = $session['oauth_state_expires'] ?? null;
    unset($session['oauth2state'],$session['oauth_pkce'],$session['oauth_state_expires']);
    if (!is_int($expires) || $expires < $now) {
        throw new DomainException('El acceso de Google expiró. Vuelve a entrar.');
    }
    $returned = $query['state'] ?? null;
    if (!is_string($state) || $state === '' || strlen($state) > 256 || !is_string($returned) || $returned === '' || strlen($returned) > 256 || !hash_equals($state, $returned)) {
        throw new DomainException('La sesión con Google no es válida. Inténtalo otra vez.');
    }
    $code = $query['code'] ?? null;
    if (!is_string($code) || $code === '' || strlen($code) > 2048 || str_contains($code, "\0") || !is_string($verifier) || preg_match('/^[A-Za-z0-9._~-]{43,128}$/D', $verifier) !== 1) {
        throw new DomainException('Google no devolvió el código de autorización.');
    }
    return $verifier;
}
