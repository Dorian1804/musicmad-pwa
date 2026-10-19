<?php
ob_start();
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/social.php';
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    exit('Método no permitido.');
}
exigirLimiteLecturaPortafolio();
$prettyPath = rawurldecode((string)parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH));
if (preg_match('#/@([a-z][a-z0-9_]{2,29})/$#D', $prettyPath, $canonicalAlias)) {
    header('Location: ' . urlPortafolio($canonicalAlias[1]) . (textoCampo($_GET, 'vista') === '1' ? '?vista=1' : ''), true, 308);
    exit;
}
$path = rawurldecode((string)parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH));
$slug = preg_match('#/@([a-z][a-z0-9_]{2,29})/?$#D', $path, $m) ? $m[1] : textoCampo($_GET, 'usuario');
$viewer = estaAutenticado() ? (int)sesion('usuario_id') : 0;
$artist = portafolioPorAlias($slug, $viewer, textoCampo($_GET, 'vista') === '1' && $viewer > 0);
if (!$artist) {
    http_response_code(404);
    $pageTitle = 'Portafolio no disponible';
    $pageCss = ['CSS/social.css'];
    require __DIR__ . '/includes/header.php';
    require __DIR__ . '/includes/navbar.php';?><main class="container"><section class="card vacio"><h1>Este portafolio no está disponible</h1><p>El músico decide cuándo publicar su portafolio y quién puede ver su contenido.</p><a class="btn btn-primary" href="<?= e(rutaAplicacion('index.php')) ?>">Volver a MusicMad</a></section></main><?php require __DIR__ . '/includes/footer.php';
    exit;
}
$name = $artist['nombre'] . ' ' . $artist['apellido'];
$pageTitle = $name . ' · Artista';
$pageCss = ['CSS/social.css','CSS/account.css','CSS/portfolio.css'];
$pageJs = ['assets/vendor/qrcode/qrcodegen-1.8.0.js','js/qr.js','js/portfolio.js'];
$navActivo = $viewer === (int)$artist['id'] ? 'perfil' : '';
if ($artist['publico']) {
    $publicAvatar = musicmadPublicImageUrl($artist['avatar']);
    $pageOpenGraph = ['og:type' => 'profile','og:title' => $name . ' · MusicMad','og:description' => mb_substr($artist['bio'] ?: 'Escucha mis pistas y conoce mi música.', 0, 200),'og:url' => urlPortafolio($slug),'og:image' => str_ends_with($artist['avatar'], '.jpg') ? $publicAvatar : rtrim(APP_URL, '/') . '/img/icon-512.png'];
    $pageCanonical = urlPortafolio($slug);
} else {
    header('X-Robots-Tag: noindex, nofollow');
}
require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/navbar.php';
?>
<main class="portfolio-page portfolio-<?= e($artist['color']) ?>">
<header class="portfolio-stage"><img class="portfolio-cover" src="<?= e(musicmadPublicImageUrl($artist['portada'])) ?>" alt=""><div class="portfolio-stage-shade"></div><div class="container portfolio-stage-content"><span class="portfolio-eyebrow">MUSICMAD / ARTISTA</span><div class="portfolio-identity"><img src="<?= e(musicmadPublicImageUrl($artist['avatar'])) ?>" class="musico-avatar" alt="Foto de <?= e($name) ?>" tabindex="0" role="button" aria-label="Ver foto de perfil ampliada"><div><span>@<?= e($slug) ?></span><h1><?= e($name) ?></h1><p><?= e($artist['instrumento'] ?: 'Músico') ?><?= $artist['ciudad'] ? ' · ' . e($artist['ciudad']) : '' ?></p></div></div><nav aria-label="Secciones del portafolio"><a href="<?= e(anclaAplicacion('#sonido')) ?>" aria-current="location">Mi sonido</a><a href="<?= e(anclaAplicacion('#historia')) ?>">Mi historia</a><a href="<?= e(anclaAplicacion('#enlaces')) ?>">Conecta</a></nav></div></header>
<div class="container portfolio-body"><?php if (!$artist['publico']): ?><p class="alert alert-info">Vista privada de tu portafolio. Activa la publicación y el perfil Público para que otras personas lo vean.</p><?php endif; ?>
<section id="sonido" class="portfolio-sound"><header><span class="portfolio-eyebrow">01 / ESCUCHA</span><h2>Mi sonido</h2><p>Una selección de lo que estoy creando.</p></header><?php if (!$artist['pistas']): ?><p class="portfolio-empty">Mis primeras pistas llegarán pronto.</p><?php endif; ?><ol><?php foreach ($artist['pistas'] as $i => $track): ?><li><span class="portfolio-track-number">0<?= $i + 1 ?></span><div><h3><?= e($track['titulo']) ?></h3><p><?= e($track['genero'] ?: 'Pista original') ?><?php if ($track['duracion'] !== null): ?> · <?= (int)floor((float)$track['duracion'] / 60) ?>:<?= str_pad((string)((int)$track['duracion'] % 60), 2, '0', STR_PAD_LEFT) ?><?php endif; ?></p><?php if ($viewer): ?><button class="btn btn-primary" type="button" data-track-play data-track-id="<?= (int)$track['id'] ?>" data-track-src="audio.php?id=<?= (int)$track['id'] ?>" data-track-title="<?= e($track['titulo']) ?>" data-track-artist="<?= e($name) ?>">▶ Escuchar pista</button><?php else: ?><audio controls preload="none" src="pista-publica.php?id=<?= (int)$track['id'] ?>" aria-label="Escuchar <?= e($track['titulo']) ?>"></audio><?php endif; ?></div></li><?php endforeach; ?></ol></section>
<section id="historia" class="portfolio-story"><span class="portfolio-eyebrow">02 / DETRÁS DEL SONIDO</span><h2>Mi historia</h2><p><?= nl2br(e($artist['bio'] ?: 'La música es mi punto de encuentro.')) ?></p><?php if ($artist['generos']): ?><p class="portfolio-genres"><?= e($artist['generos']) ?></p><?php endif; ?></section>
<section id="enlaces" class="portfolio-connect"><span class="portfolio-eyebrow">03 / SIGAMOS EN CONTACTO</span><h2>Conecta conmigo</h2><div><?php foreach ($artist['redes'] as $key => $url): ?><a href="<?= e($url) ?>" rel="noopener noreferrer" target="_blank"><?= e(REDES_PORTAFOLIO[$key]) ?> ↗</a><?php endforeach; ?><a href="<?= $viewer ? 'musico.php?id=' . (int)$artist['id'] : 'login.php' ?>">Mi perfil en MusicMad ↗</a><?php if ($viewer && (int)$artist['id'] !== $viewer): ?><a href="<?= e(rutaAplicacion('mensajes.php?con=' . (int)$artist['id'])) ?>">Hablemos de música ↗</a><?php endif; ?></div></section>
<?php if ($artist['publico']): ?><details data-sheet data-sheet-title="Comparte este portafolio" class="portfolio-share"><summary class="btn btn-primary">Compartir enlace y QR ↗</summary><label>Enlace del portafolio<input readonly value="<?= e(enlaceCompartiblePublico('perfil', (int) $artist['id']) ?? '') ?>"></label><?php if ($artist['incrustable']): ?><label>Código para otra página<textarea readonly rows="4"><?= e(codigoIncrustarPortafolio($slug)) ?></textarea></label><?php endif; ?><div class="account-qr" data-qr-kind="perfil" data-qr-value="<?= e(enlaceCompartiblePublico('perfil', (int) $artist['id']) ?? '') ?>"></div></details><?php endif; ?><?php if ($viewer === (int)$artist['id']): ?><a class="account-back" href="<?= e(rutaAplicacion('epk.php')) ?>">Editar mi portafolio →</a><?php endif; ?></div></main>
<?php require __DIR__ . '/includes/footer.php'; ?>
