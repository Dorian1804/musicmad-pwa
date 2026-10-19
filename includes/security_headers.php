<?php

// La misma política se aplica en Apache y en el servidor PHP local.
function musicmadCsp(): string
{
    // Navigation uses fetch: the policy of the original document remains active.
    // Only map tiles from this exact origin are allowed; loading still requires a click.
    $tiles = ' https://tile.openstreetmap.org';
    return "default-src 'self'; base-uri 'self'; object-src 'none'; frame-ancestors 'none'; form-action 'self' https://accounts.google.com; script-src 'self'; style-src 'self'; img-src 'self' data: blob:{$tiles}; media-src 'self' blob:; font-src 'self'; connect-src 'self'; frame-src https://www.youtube-nocookie.com https://open.spotify.com";
}
if (!headers_sent()) {
    header('Content-Security-Policy: ' . musicmadCsp());
    if ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (getenv('MM_TRUST_PROXY') === '1' && ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')) {
        header('Strict-Transport-Security: max-age=31536000');
    }
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('X-Frame-Options: DENY');
    header('Cross-Origin-Opener-Policy: same-origin');
    header_remove('X-Powered-By');
    header('Permissions-Policy: camera=(self), microphone=(self), geolocation=(self)');
}
