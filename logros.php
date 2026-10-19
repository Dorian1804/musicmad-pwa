<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/social.php';
requerirSesion();
header('Cache-Control: private, no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    exit;
}
$uid = (int)sesion('usuario_id');
if (!estadisticasArtistaDisponibles()) {
    http_response_code(503);
}
try {
    $achievements = logrosArtista($uid);
} catch (Throwable $e) {
    http_response_code(503);
    error_log('Artist awards: ' . get_class($e));
    $achievements = ['insignias' => [],'nivel' => 1,'puntos' => 0];
}
$pageTitle = 'Tus insignias';
$pageCss = ['CSS/social.css','CSS/artist.css'];
$navActivo = 'perfil';
require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/navbar.php';
?>
<main class="container artist-page"><a class="artist-back" href="<?= e(rutaAplicacion('estadisticas.php')) ?>">← Tu música en números</a><header class="artist-hero awards-hero"><span>HITOS / TU RECORRIDO</span><h1>Deja tu<br>marca.</h1><p>Cada pista y cada colaboración suma a la escena. Aquí ves los hitos que ya alcanzaste y tu siguiente paso.</p><strong>Nivel de comunidad <?= (int)$achievements['nivel'] ?> · <?= (int)$achievements['puntos'] ?> puntos</strong></header>
<?php if (!$achievements['insignias']): ?><section class="artist-empty"><h2>Las insignias todavía no están disponibles</h2><a href="<?= e(rutaAplicacion('feed.php')) ?>">Volver a la comunidad</a></section><?php endif; ?>
<section class="artist-awards" aria-label="Tus hitos"><?php foreach ($achievements['insignias'] as $award): $progress = $award['alcanzada'] ? (int)$award['objetivo'] : min((int)$award['actual'], (int)$award['objetivo']); ?><article class="artist-award <?= $award['alcanzada'] ? 'is-earned' : '' ?>"><svg class="artist-badge-icon" viewBox="0 0 64 64" aria-hidden="true"><path d="m32 3 24 14v28L32 59 8 45V17Z"/><path d="m19 31 9 9 18-19"/></svg><small><?= $award['alcanzada'] ? 'ALCANZADA' : 'TU SIGUIENTE PASO' ?></small><h2><?= e($award['titulo']) ?></h2><p><?= e($award['descripcion']) ?></p><label for="award-<?= e($award['codigo']) ?>"><?= $progress ?> / <?= (int)$award['objetivo'] ?><progress id="award-<?= e($award['codigo']) ?>" value="<?= $progress ?>" max="<?= (int)$award['objetivo'] ?>"></progress></label><strong>+<?= (int)$award['puntos'] ?> puntos</strong><?php if ($award['obtenida_en'] && (int)$award['actual'] < (int)$award['objetivo']): ?><small>Hito conservado · cifra actual: <?= (int)$award['actual'] ?></small><?php endif; ?><?php if ($award['obtenida_en']): ?><time datetime="<?= e(str_replace(' ', 'T', $award['obtenida_en']) . 'Z') ?>">Obtenida el <?= e(substr($award['obtenida_en'], 0, 10)) ?> · UTC</time><?php endif; ?></article><?php endforeach; ?></section>
<p class="artist-empty-note">Las insignias registradas conservan el hito aunque después retires una pista o cambie tu audiencia. Los niveles de comunidad no cambian el nivel musical de tu ficha.</p><a class="btn btn-primary" href="<?= e(rutaAplicacion('feed.php#compartir')) ?>">Crear algo nuevo ＋</a></main><?php require __DIR__ . '/includes/footer.php'; ?>
