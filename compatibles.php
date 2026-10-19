<?php
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
$id = idCampo($_GET, 'busqueda');
$wanted = ['instrumento' => mb_substr(trim(textoCampo($_GET, 'instrumento')), 0, 60),'genero' => textoCampo($_GET, 'genero'),'ciudad' => mb_substr(trim(textoCampo($_GET, 'ciudad')), 0, 80),'nivel' => textoCampo($_GET, 'nivel') ?: 'sin_definir'];
$error = '';
$results = [];
try {
    if (!matchingDisponible()) {
        throw new RuntimeException('Esta sección estará disponible al terminar la actualización.');
    }
    if ($id) {
        $wanted = busquedaParaMatching($yo, $id);
    }
    limiteChat($yo, 'matching_leer', 60);
    $results = sugerirMusicosCompatibles($yo, $wanted);
} catch (OutOfBoundsException $e) {
    http_response_code(404);
    $error = $e->getMessage();
} catch (LogicException|OverflowException $e) {
    $error = $e->getMessage();
} catch (RuntimeException $e) {
    $error = 'Esta sección estará disponible al terminar la actualización.';
}
$pageTitle = 'Músicos compatibles';
$pageCss = ['CSS/social.css','CSS/discover.css'];
$navActivo = 'musicos';
require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/navbar.php';
?>
<main class="container discover-page"><a class="discover-back" href="<?= e(rutaAplicacion('busco.php')) ?>">← Se busca</a><header class="discover-heading"><span class="community-kicker">MusicMad / Afinidad</span><h1>Encuentra con quién<br>hacer música.</h1><p>Instrumento, género, ciudad y nivel ayudan a conectar.</p></header>
<?php if ($error): ?><p class="alert alert-error"><?= e($error) ?></p><?php else: ?>
<?php if ($id): ?><section class="card matching-request"><span class="community-kicker">Tu búsqueda</span><h2><?= e($wanted['instrumento']) ?></h2><p><?= e(implode(' · ', array_filter([$wanted['genero'],$wanted['ciudad']]))) ?></p><a href="<?= e(rutaAplicacion('compatibles.php')) ?>">Hacer otra búsqueda ↗</a></section><?php else: ?><details class="card quick-sheet" id="matching-filtros" data-sheet data-sheet-title="Tu próximo colaborador"><summary><strong>Elige tu conexión</strong><span aria-hidden="true">☷</span></summary><form method="GET" class="discover-form"><label>Instrumento<input name="instrumento" value="<?= e($wanted['instrumento']) ?>" maxlength="60" placeholder="Baterista, voz, guitarra…"></label><label>Género<select name="genero"><option value="">Cualquiera</option><?php foreach (GENEROS as $g): ?><option value="<?= e($g) ?>" <?= $wanted['genero'] === $g ? 'selected' : '' ?>><?= e($g) ?></option><?php endforeach; ?></select></label><label>Ciudad<input name="ciudad" value="<?= e($wanted['ciudad']) ?>" maxlength="80"></label><label>Nivel mínimo<select name="nivel"><?php foreach (NIVELES_MUSICALES as $key => $label): ?><option value="<?= $key ?>" <?= $wanted['nivel'] === $key ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label><button type="submit" class="btn btn-primary">Encontrar músicos</button></form></details><?php endif; ?>
<p class="matching-explanation">Ordenamos por coincidencias de instrumento, género, ciudad y nivel declarado. Ciudad y género son preferencias; el instrumento y nivel solicitado sí se requieren.</p>
<?php if (!$results): ?><div class="card vacio"><h2>Aún no encontramos esa combinación.</h2><p>Prueba sin nivel mínimo o cambia el instrumento. Puedes publicar una búsqueda y recibir respuestas.</p><a class="btn btn-primary" href="<?= e(rutaAplicacion('busco.php#crear-busqueda')) ?>">Publicar búsqueda</a></div><?php endif; ?>
<div class="compatible-grid"><?php foreach ($results as $m): ?><article class="card compatible-card"><a class="compatible-person" href="<?= e(rutaAplicacion('musico.php?id=' . (int)$m['id'])) ?>"><img src="<?= e(musicmadPublicImageUrl(avatarBusqueda((int)$m['id'], (string)$m['avatar']))) ?>" width="64" height="64" alt="" loading="lazy"><div><h2><?= e($m['nombre'] . ' ' . $m['apellido']) ?></h2><p><?= e($m['instrumento'] ?: 'Músico') ?></p></div></a><p class="compatible-details"><?= e(implode(' · ', array_filter([$m['ciudad'],$m['nivel'] !== 'sin_definir' ? NIVELES_MUSICALES[$m['nivel']] : '']))) ?></p><p class="compatible-genres"><?= e($m['generos']) ?></p><?php if ($m['coincidencias']): ?><ul class="matching-reasons"><?php foreach ($m['coincidencias'] as $reason): ?><li><?= e($reason) ?></li><?php endforeach; ?></ul><?php endif; ?><div class="compatible-actions"><a class="btn btn-ghost" href="<?= e(rutaAplicacion('musico.php?id=' . (int)$m['id'])) ?>">Ver perfil</a><?php if (puedeEnviarMensajeSocial3($yo, (int)$m['id'])): ?><a class="btn btn-primary" href="mensajes.php?con=<?= (int)$m['id'] ?>&amp;txt=<?= e(rawurlencode('Hola, encontré tu perfil buscando ' . $wanted['instrumento'] . '. Me gustaría hablar de un proyecto musical. ')) ?>">Conectar ↗</a><?php endif; ?></div></article><?php endforeach; ?></div><?php endif; ?></main><?php require __DIR__ . '/includes/footer.php'; ?>
