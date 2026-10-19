<?php
define('MUSICMAD_MAP_PAGE', true);
ob_start();
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/social.php';
requerirSesion();
$yo = (int)sesion('usuario_id');
$input = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;
$type = textoCampo($input, 'tipo') ?: 'musico';
$id = $type === 'musico' ? $yo : idCampo($input, 'id');
$error = '';
$flash = '';
$zone = null;
$ready = mapaDisponible();
if (!in_array($_SERVER['REQUEST_METHOD'], ['GET','POST'], true)) {
    header('Allow: GET, POST');
    http_response_code(405);
    exit;
}
try {
    if (!$ready) {
        throw new RuntimeException('El mapa estará disponible al terminar la actualización.');
    }
    autorizarLugarMapa($yo, $type, $id);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        exigirCsrf();
        if (!in_array(textoCampo($_POST, 'accion'), ['guardar','ocultar'], true)) {
            throw new InvalidArgumentException('Acción no válida.');
        }
        guardarZonaMapa($yo, $type, $id, $_POST, textoCampo($_POST, 'accion') === 'ocultar');
        $flash = textoCampo($_POST, 'accion') === 'ocultar' ? 'La ubicación ya no aparece en el mapa.' : 'Tu zona aproximada quedó guardada.';
    }
    $zone = miZonaMapa($yo, $type, $id);
} catch (OutOfBoundsException $e) {
    http_response_code(404);
    $error = $e->getMessage();
    $ready = false;
} catch (LogicException|OverflowException $e) {
    $error = $e->getMessage();
} catch (RuntimeException $e) {
    $error = 'El mapa estará disponible al terminar la actualización.';
    $ready = false;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'MusicMad-PJAX') {
    if ($error && http_response_code() === 200) {
        http_response_code(422);
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => $error === '','redirect' => 'ubicacion.php?tipo=' . rawurlencode($type) . '&id=' . $id,'flash' => [$error ? 'error' : 'success',$error ?: $flash]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
$tiles = !(defined('MUSICMAD_TEST_RUN') && MUSICMAD_TEST_RUN);
$pageTitle = 'Mi zona en el mapa';
$pageCss = ['CSS/social.css','assets/vendor/leaflet/leaflet.css','CSS/map.css'];
$pageJs = ['assets/vendor/leaflet/leaflet.js','js/scene-map.js'];
$navActivo = 'perfil';
require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/navbar.php';
?>
<main class="container location-editor"><a class="discover-back" href="<?= e(rutaAplicacion('mapa.php')) ?>">← Explorar el mapa</a><h1><?= $type === 'musico' ? 'Tu zona, a tu manera' : ($type === 'banda' ? 'La zona de tu banda' : 'Zona del evento') ?></h1><p class="location-explanation">Elige una zona amplia. Redondeamos el punto antes de guardarlo; no publiques tu domicilio. Tu perfil conserva sus reglas de privacidad.</p><?php if ($error): ?><p class="alert alert-error"><?= e($error) ?></p><?php endif; ?><?php if ($flash): ?><p class="alert alert-success" role="status"><?= e($flash) ?></p><?php endif; ?>
<?php if ($ready): ?><section data-scene-map data-map-editor data-tiles="<?= $tiles ? '1' : '0' ?>"><div class="scene-map-stage"><div class="scene-map" data-map-canvas role="region" aria-label="Elegir zona aproximada"></div><div class="scene-map-consent" data-map-consent><h2>Elige una zona amplia</h2><p>El fondo lo proporciona OpenStreetMap. Al abrirlo, verá tu IP y la zona consultada.</p><button type="button" class="btn btn-primary" data-map-open>Elegir en el mapa</button></div></div><div class="scene-map-error" data-map-error hidden role="status"><p></p><button type="button" class="btn btn-ghost" data-map-retry>Reintentar mapa</button></div><p data-map-status role="status" class="scene-map-status">Toca en el mapa para elegir una zona de unos 10 km.</p><form method="POST" action="<?= e(rutaAplicacion('ubicacion.php', 'POST')) ?>" class="location-form" data-location-form><?= csrfCampo() ?><input type="hidden" name="tipo" value="<?= e($type) ?>"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="accion" value="guardar"><div class="location-coordinate-row"><label>Latitud aproximada<input name="latitud" inputmode="decimal" required value="<?= e($zone ? number_format((float)$zone['latitud'], 1, '.', '') : textoCampo($_POST, 'latitud')) ?>" data-map-lat></label><label>Longitud aproximada<input name="longitud" inputmode="decimal" required value="<?= e($zone ? number_format((float)$zone['longitud'], 1, '.', '') : textoCampo($_POST, 'longitud')) ?>" data-map-lon></label></div><small>Puedes introducir coordenadas de la ciudad sin abrir el mapa. Solo guardamos una cifra decimal.</small><label class="location-consent"><input type="checkbox" name="consentimiento" value="1" required> Quiero mostrar esta zona aproximada a las cuentas que pueden ver mi perfil o este proyecto.</label><button type="submit" class="btn btn-primary">Guardar zona aproximada</button></form></section><?php if ($zone): ?><form method="POST" action="<?= e(rutaAplicacion('ubicacion.php', 'POST')) ?>" class="location-remove"><?= csrfCampo() ?><input type="hidden" name="tipo" value="<?= e($type) ?>"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="accion" value="ocultar"><button type="submit" class="btn btn-ghost">Dejar de aparecer en el mapa</button></form><?php endif; ?><?php endif; ?></main><?php require __DIR__ . '/includes/footer.php'; ?>
