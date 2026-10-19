<?php
define('MUSICMAD_MAP_PAGE', true);
ob_start();
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/social.php';
requerirSesion();
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    exit;
}
$yo = (int)sesion('usuario_id');
$ready = mapaDisponible();
$tiles = !(defined('MUSICMAD_TEST_RUN') && MUSICMAD_TEST_RUN) || (defined('MUSICMAD_TEST_MAP_TILES') && MUSICMAD_TEST_MAP_TILES === true);
$pageTitle = 'La escena cerca de ti';
$pageCss = ['CSS/social.css','CSS/discover.css','assets/vendor/leaflet/leaflet.css','CSS/map.css'];
$pageJs = ['assets/vendor/leaflet/leaflet.js','js/scene-map.js'];
$navActivo = 'musicos';
require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/navbar.php';
?>
<main class="container scene-map-page"><header class="discover-heading"><span class="community-kicker">MusicMad / Escena local</span><h1>La música también<br>está cerca de ti.</h1><p>Explora músicos, bandas y eventos por zona. Tú decides si quieres aparecer.</p><a class="btn btn-primary" href="<?= e(rutaAplicacion('ubicacion.php')) ?>">Mi zona en el mapa ↗</a><a class="btn btn-ghost" href="<?= e(rutaAplicacion('compatibles.php')) ?>">Músicos compatibles</a></header>
<?php if (!$ready): ?><p class="card">El mapa estará disponible al terminar la actualización.</p><?php else: ?>
<section class="scene-map-workspace" data-scene-map data-tiles="<?= $tiles ? '1' : '0' ?>"><form class="scene-filters" data-scene-filters><label>Ver<select name="tipo"><option value="todos">Toda la escena</option><option value="musico">Músicos</option><option value="banda">Bandas</option><option value="evento">Eventos</option></select></label><label>Ciudad<input type="search" name="ciudad" maxlength="80" placeholder="Filtrar por ciudad"></label><button class="btn btn-ghost" type="submit">Filtrar</button></form><div class="scene-map-stage"><div class="scene-map" data-map-canvas role="region" aria-label="Mapa de músicos, bandas y eventos"></div><div class="scene-map-consent" data-map-consent><h2>Explora tu escena</h2><p>Al abrir el mapa, OpenStreetMap recibirá tu IP y la zona consultada para mostrar el fondo. MusicMad no envía tu nombre ni tus mensajes.</p><button type="button" class="btn btn-primary" data-map-open>Ver mapa</button><a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener noreferrer">Información del mapa</a></div></div><div class="scene-map-error" data-map-error hidden role="status"><p></p><button type="button" class="btn btn-ghost" data-map-retry>Reintentar mapa</button></div><p class="scene-map-status" data-map-status role="status">Las ubicaciones de MusicMad son aproximadas, con un área de unos 10 km.</p><div class="scene-map-legend"><span>● Músicos</span><span>● Bandas</span><span>● Eventos</span></div><ul class="scene-results" data-map-results aria-label="Resultados en la zona"></ul><noscript><p>El mapa necesita JavaScript. Puedes encontrar a tu gente en <a href="<?= e(rutaAplicacion('musicos.php')) ?>">Músicos</a> y <a href="<?= e(rutaAplicacion('eventos.php')) ?>">Eventos</a>.</p></noscript></section>
<?php endif; ?></main><?php require __DIR__ . '/includes/footer.php'; ?>
