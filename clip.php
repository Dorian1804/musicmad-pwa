<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/social.php';
requerirSesion();
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    exit;
}
$uid = (int)sesion('usuario_id');
$id = idCampo($_GET, 'id');
$clip = clipDisponiblePara($uid, $id);
if (!$clip) {
    http_response_code(404);
}
$pageTitle = $clip ? $clip['titulo'] : 'Clip no disponible';
$pageCss = ['CSS/social.css','CSS/account.css','CSS/creative.css'];
$pageJs = ['js/clip-player.js'];
require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/navbar.php';
?>
<main class="container clip-view-page"><a class="account-back" href="<?= e(rutaAplicacion('clips.php')) ?>">← Clips de la escena</a><?php if (!$clip): ?><section class="card studio-empty"><h1>Este momento ya no está disponible</h1><p>Pudo vencer, retirarse o cambiar su privacidad.</p></section><?php else: ?>
<article class="clip-view" data-clip-start="<?= (int)$clip['inicio_ms'] ?>" data-clip-length="<?= (int)$clip['duracion_ms'] ?>" data-clip-expiry="<?= e(str_replace(' ', 'T', $clip['expira_en']) . 'Z') ?>" data-clip-remaining="<?= max(0, ((new DateTimeImmutable($clip['expira_en'], new DateTimeZone('UTC')))->getTimestamp() - time()) * 1000) ?>"><header><img width="44" height="44" src="<?= e(musicmadPublicImageUrl($clip['avatar'])) ?>" alt=""><span><strong><?= e($clip['autor']) ?></strong><small>Un momento de 24 horas</small></span></header><div class="clip-stage"><?php if ($clip['tipo'] === 'video'): ?><video controls playsinline preload="metadata" src="clip-medio.php?id=<?= $id ?>" data-clip-media data-analytics-pub="<?= (int)$clip['publicacion_id'] ?>"></video><?php else: ?><div class="clip-audio-art" aria-hidden="true">♪</div><audio controls preload="metadata" src="clip-medio.php?id=<?= $id ?>" data-clip-media></audio><?php endif; ?></div><h1><?= e($clip['titulo']) ?></h1><p><?= e($clip['texto']) ?></p><div class="clip-controls" hidden data-clip-controls><button class="btn btn-primary" type="button" data-clip-toggle>Reproducir ▶</button><label><span class="sr-only">Posición dentro del clip</span><input type="range" min="0" max="1000" value="0" data-clip-seek aria-label="Posición dentro del clip"></label><output data-clip-clock>0:00</output></div><p data-clip-status role="status">Toca para escuchar este momento.</p><a href="<?= e(rutaAplicacion('publicacion.php?id=' . (int)$clip['publicacion_id'])) ?>">Escuchar o ver la publicación completa ↗</a></article>
<?php if ((int)$clip['usuario_id'] === $uid): ?><form method="POST" action="<?= e(rutaAplicacion('clip-accion.php', 'POST')) ?>" class="clip-retire"><?= csrfCampo() ?><input type="hidden" name="accion" value="retirar"><input type="hidden" name="id" value="<?= $id ?>"><button class="btn btn-ghost" type="submit">Retirar de Clips</button></form><?php endif; ?><?php endif; ?></main>
<?php require __DIR__ . '/includes/footer.php'; ?>
