<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/social.php';
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    exit('Método no permitido.');
}
exigirLimiteLecturaPortafolio();
// Embedding always uses the anonymous view; a logged-in owner's preview cannot enter.
$artist = portafolioPorAlias(textoCampo($_GET, 'usuario'), 0, false);
if (!$artist || !$artist['incrustable']) {
    http_response_code(404);
    exit('Visor no disponible.');
}
$csp = "default-src 'none'; base-uri 'none'; object-src 'none'; script-src 'none'; style-src 'self'; img-src 'self'; media-src 'self'; frame-ancestors *; form-action 'none'";
header_remove('X-Frame-Options');
header('X-MusicMad-Embed: 1');
header('Content-Security-Policy: ' . $csp);
header('Cache-Control: private, no-store');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex');
$name = $artist['nombre'] . ' ' . $artist['apellido'];
?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= e($name) ?> · MusicMad</title><link rel="stylesheet" href="<?= e(rtrim(APP_URL, '/') . '/CSS/embed.css') ?>"></head><body><main><header><img src="<?= e(str_starts_with($artist['avatar'], 'img/') ? rtrim(APP_URL, '/') . '/' . $artist['avatar'] : musicmadPublicImageUrl($artist['avatar'])) ?>" alt=""><div><span>MUSICMAD / ARTISTA</span><h1><?= e($name) ?></h1><p><?= e($artist['instrumento'] ?: 'Músico') ?></p></div></header><ol><?php foreach ($artist['pistas'] as $track): ?><li><h2><?= e($track['titulo']) ?></h2><audio controls preload="none" src="<?= e(rtrim(APP_URL, '/') . '/pista-publica.php?id=' . (int)$track['id']) ?>" aria-label="Escuchar <?= e($track['titulo']) ?>"></audio></li><?php endforeach; ?></ol><a href="<?= e(urlPortafolio($artist['slug'])) ?>" target="_blank" rel="noopener noreferrer">Ver portafolio en MusicMad ↗</a></main></body></html>
