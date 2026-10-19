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
$ready = estudioCreativoDisponible();
$clips = listarClipsMusicales($uid, 50);
$tracks = $ready ? pistasPropiasCreativas($uid, true) : [];
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$pageTitle = 'Clips de la escena';
$pageCss = ['CSS/social.css','CSS/account.css','CSS/creative.css'];
require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/navbar.php';
?>
<main class="container clips-page"><header class="clips-hero"><span>24 HORAS EN LA ESCENA</span><h1>Un momento<br>de tu sonido.</h1><p>Hasta 30 segundos de música o video, con tu comunidad.</p><a class="btn btn-primary" href="<?= e(anclaAplicacion('#nuevo-clip')) ?>">Compartir un momento ↗</a></header><?php if ($flash): ?><p class="alert alert-<?= e($flash[0]) ?>" role="status"><?= e($flash[1]) ?></p><?php endif; ?>
<div class="clips-grid"><?php foreach ($clips as $clip): ?><a class="clip-card" href="<?= e(rutaAplicacion('clip.php?id=' . (int)$clip['id'])) ?>"><img width="56" height="56" src="<?= e(musicmadPublicImageUrl($clip['avatar'])) ?>" alt="" loading="lazy"><small><?= e($clip['tipo'] === 'video' ? 'VIDEO' : 'AUDIO') ?> · <?= ceil($clip['duracion_ms'] / 1000) ?>s</small><h2><?= e($clip['titulo']) ?></h2><p><?= e($clip['texto']) ?></p><strong><?= e($clip['autor']) ?></strong><span aria-hidden="true">↗</span></a><?php endforeach; ?><?php if (!$clips): ?><article class="card studio-empty"><h2>Hoy aún no suena ningún clip</h2><p>Comparte un riff, una práctica o un momento de tu tocada.</p></article><?php endif; ?></div>
<?php if ($ready): ?><details id="nuevo-clip" data-sheet data-sheet-title="Tu clip de 24 horas"><summary class="btn btn-primary">Crear clip ＋</summary><form method="POST" action="<?= e(rutaAplicacion('clip-accion.php', 'POST')) ?>" class="account-confirm-form"><?= csrfCampo() ?><input type="hidden" name="accion" value="crear"><input type="hidden" name="solicitud" value="<?= bin2hex(random_bytes(16)) ?>"><label for="clips-source">Audio o video tuyo</label><select id="clips-source" name="pista" required><option value="">Elige una publicación</option><?php foreach ($tracks as $track): ?><option value="<?= (int)$track['id'] ?>"><?= e($track['titulo'] . ' · ' . $track['medio']) ?></option><?php endforeach; ?></select><label>Empezar en el segundo<input type="number" name="inicio" min="0" max="21600" step=".001" value="0" inputmode="decimal" required></label><label>Duración del fragmento<input type="number" name="duracion" min="1" max="30" step=".001" value="10" inputmode="decimal" required></label><label>Tu mensaje<input name="texto" maxlength="180" placeholder="Un nuevo riff, un ensayo, una idea…"></label><p>Desaparece de Clips en 24 horas. Tu publicación y su archivo original se conservan; la privacidad sigue siendo la de tu perfil.</p><button class="btn btn-primary" type="submit">Compartir clip</button><a href="<?= e(rutaAplicacion('feed.php#compartir')) ?>">Subir primero audio o video</a></form></details><?php endif; ?></main>
<?php require __DIR__ . '/includes/footer.php'; ?>
