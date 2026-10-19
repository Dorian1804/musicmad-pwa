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
$days = idCampo($_GET, 'dias');
$days = in_array($days, [7,30,90], true) ? $days : 30;
$data = null;
$achievements = null;
try {
    $data = datosEstadisticasArtista($uid, $days);
    $achievements = logrosArtista($uid);
} catch (Throwable $e) {
    $data = null;
    $achievements = null;
    http_response_code(503);
    error_log('Artist dashboard: ' . get_class($e));
}
$pageTitle = 'Tu música en números';
$pageCss = ['CSS/social.css','CSS/artist.css'];
$pageJs = ['assets/vendor/chart/chart-4.5.1.umd.min.js','js/artist-dashboard.js'];
$navActivo = 'perfil';
require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/navbar.php';
?>
<main class="container artist-page"><a class="artist-back" href="<?= e(rutaAplicacion('menu.php')) ?>">← Tu MusicMad</a><header class="artist-hero"><span>ESTADÍSTICAS / TU ESCENA</span><h1>Tu música<br>se mueve.</h1><p>Una mirada a las escuchas y las conexiones que está creando tu música.</p><a href="musico.php?id=<?= $uid ?>">Ver mi perfil ↗</a></header>
<?php if (!$data): ?><section class="card artist-empty"><h2>El panel todavía no está disponible</h2><p>Vuelve a intentarlo cuando se complete la actualización de MusicMad.</p><a class="btn btn-primary" href="<?= e(rutaAplicacion('feed.php')) ?>">Volver a la comunidad</a></section><?php else: ?>
<nav class="artist-period" aria-label="Periodo de las estadísticas"><?php foreach ([7,30,90] as $period): ?><a href="estadisticas.php?dias=<?= $period ?>" <?= $days === $period ? 'aria-current="page"' : '' ?>><?= $period ?> días</a><?php endforeach; ?></nav>
<section class="artist-numbers" aria-label="Resumen"><?php foreach (['escuchas' => 'Escuchas confirmadas','oyentes' => 'Cuentas que escucharon','aplausos' => 'Aplausos actuales','seguidores' => 'Seguidores activos'] as $key => $label): ?><article><span><?= e($label) ?></span><strong><?= number_format($data['resumen'][$key], 0, '.', ',') ?></strong><small><?= in_array($key, ['escuchas','oyentes'], true) ? 'En este periodo' : 'En toda tu cuenta' ?></small></article><?php endforeach; ?></section>
<section class="artist-chart-card"><header><div><span>LA ACTIVIDAD</span><h2>Cómo va sonando</h2></div><small>Últimos <?= $days ?> días · UTC</small></header><div class="artist-chart-wrap" data-artist-chart data-points="<?= e(json_encode($data['serie'], JSON_THROW_ON_ERROR)) ?>"><canvas height="260" role="img" aria-label="Escuchas confirmadas por día. Los mismos datos aparecen en la tabla de abajo."></canvas></div><?php if (!$data['resumen']['escuchas']): ?><p class="artist-empty-note">Tu próxima escucha empieza con alguien que descubre una pista tuya. Comparte tu portafolio para invitar a escuchar.</p><?php endif; ?><details><summary>Ver los datos de cada día</summary><div class="artist-table-scroll"><table><thead><tr><th scope="col">Fecha UTC</th><th scope="col">Escuchas</th></tr></thead><tbody><?php foreach ($data['serie'] as $day): ?><tr><td><?= e($day['fecha']) ?></td><td><?= (int)$day['escuchas'] ?></td></tr><?php endforeach; ?></tbody></table></div></details></section>
<section class="artist-tracks"><header><span>EN ROTACIÓN</span><h2>Tus pistas y videos</h2><p>Las treinta publicaciones con más escuchas en el periodo elegido.</p></header><?php if (!$data['pistas']): ?><div class="artist-empty"><strong>Empieza con tu primera pista.</strong><p>Una grabación, un demo o un video de ensayo: aquí podrás seguir su recorrido.</p><a href="<?= e(rutaAplicacion('feed.php#compartir')) ?>" class="btn btn-primary">Compartir una pista ＋</a></div><?php endif; ?><?php foreach ($data['pistas'] as $index => $track): ?><a href="<?= e(rutaAplicacion('publicacion.php?id=' . (int)$track['id'])) ?>" class="artist-track"><span class="artist-track-index"><?= str_pad((string)($index + 1), 2, '0', STR_PAD_LEFT) ?></span><span class="artist-track-info"><strong><?= e($track['titulo']) ?></strong><small><?= e(TIPOS_PUBLICACION[$track['tipo']][1] ?? 'Pista') ?> · <?= (int)$track['aplausos'] ?> aplausos</small></span><span class="artist-track-count"><strong><?= (int)$track['escuchas'] ?></strong><small>escuchas</small></span></a><?php endforeach; ?></section>
<section class="artist-level"><div><span>APORTA A LA ESCENA</span><h2>Nivel de comunidad <?= (int)$achievements['nivel'] ?></h2><p><?= (int)$achievements['puntos'] ?> puntos por hitos alcanzados. Este nivel refleja tu actividad en MusicMad.</p></div><a class="btn btn-primary" href="<?= e(rutaAplicacion('logros.php')) ?>">Ver mis insignias ↗</a></section>
<section class="artist-explanation"><h2>Qué cuentan estos números</h2><p>Una escucha requiere al menos cinco segundos de reproducción, o el 80% si la pista es más corta. Cada cuenta aporta como máximo una escucha por pista y día UTC; tus escuchas propias no cuentan. Las cuentas de oyentes son distintas dentro del periodo, aunque hayan escuchado varias pistas.</p><p>Precargar, descargar, adelantar la pista o abrir la página no basta para sumar una escucha. Las cifras registran actividad de las cuentas; la atención humana puede ser distinta. Las escuchas sin conexión y las anónimas no se incluyen. Las solicitudes históricas de archivos se conservan y no se convierten en escuchas confirmadas.</p></section>
<?php endif; ?></main><?php require __DIR__ . '/includes/footer.php'; ?>
