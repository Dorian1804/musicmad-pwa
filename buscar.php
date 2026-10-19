<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/social.php';
require_once __DIR__ . '/includes/global_search.php';
requerirSesion();
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    exit;
}
$yo = (int)sesion('usuario_id');
$query = mb_substr(trim(textoCampo($_GET, 'q')), 0, 80);
$tag = mb_substr(textoCampo($_GET, 'tag'), 0, 40);
$before = idCampo($_GET, 'antes');
$kind = textoCampo($_GET, 'tipo');
$groups = ['musicos' => 'Músicos','publicaciones' => 'Publicaciones','bandas' => 'Bandas','hashtags' => 'Hashtags','generos' => 'Géneros'];
if (!isset($groups[$kind])) {
    $kind = '';
}
$results = [];
$error = '';
$tagPosts = [];
try {
    if ($tag !== '') {
        $tagPosts = publicacionesEtiqueta($yo, $tag, $before);
    } elseif ($query !== '') {
        $results = buscarGlobal($yo, $query, 10);
    }
} catch (Throwable $e) {
    $error = 'La búsqueda ampliada no está disponible todavía. Puedes seguir buscando músicos.';
}
$pageTitle = $tag !== '' ? '#' . $tag : 'Buscar en MusicMad';
$pageCss = ['CSS/social.css'];
$pageJs = ['js/global-search.js'];
$navActivo = 'musicos';
require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/navbar.php';
?>
<main class="container discovery-page">
 <header class="page-heading"><span class="community-kicker">DESCUBRE TU ESCENA</span><h1><?= $tag !== '' ? '#' . e($tag) : 'Todo empieza con una conexión.' ?></h1><p>Encuentra músicos, publicaciones, bandas, géneros y etiquetas en un solo lugar.</p></header>
 <form method="GET" action="<?= e(rutaAplicacion('buscar.php', 'GET')) ?>" class="card global-search-form" role="search">
  <label for="global-search">Buscar en MusicMad</label><div class="global-search-control"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="10.5" cy="10.5" r="6.5"/><path d="m16 16 5 5"/></svg><input id="global-search" name="q" value="<?= e($query) ?>" maxlength="80" placeholder="Un nombre, una canción, #rock…" autocomplete="off" data-global-search role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="global-suggestions"><button type="submit" class="btn btn-primary">Buscar</button></div>
  <div id="global-suggestions" class="global-suggestions" role="listbox" aria-label="Sugerencias de búsqueda" hidden></div><p class="sr-only" data-search-status role="status"></p>
 </form>
 <?php if ($error): ?><div class="alert alert-error" role="status"><?= e($error) ?> <a href="musicos.php?q=<?= rawurlencode($query) ?>">Buscar músicos</a></div><?php endif; ?>
 <?php if ($tag !== ''): ?>
 <section><h2>Publicaciones con esta etiqueta</h2><?php if (!$tagPosts): ?><p class="card vacio">Todavía no hay publicaciones disponibles con esta etiqueta.</p><?php endif; ?>
 <?php $more = count($tagPosts) > 20;
     $tagPosts = array_slice($tagPosts, 0, 20);
     foreach ($tagPosts as $post): ?><article class="card discovery-post"><small><?= e($post['genero']) ?></small><h3><a href="<?= e(rutaAplicacion('publicacion.php?id=' . (int)$post['id'])) ?>"><?= e($post['titulo']) ?></a></h3><p><?= e(mb_strimwidth($post['texto'], 0, 220, '…')) ?></p><a href="<?= e(rutaAplicacion('publicacion.php?id=' . (int)$post['id'])) ?>" class="btn btn-ghost btn-sm">Ver publicación →</a></article><?php endforeach; ?>
 <?php if ($more): ?><a class="btn btn-secondary" href="buscar.php?tag=<?= rawurlencode($tag) ?>&amp;antes=<?= (int)end($tagPosts)['id'] ?>">Publicaciones anteriores</a><?php endif; ?></section>
 <?php elseif ($query === ''): ?>
 <section class="discovery-start"><h2>Encuentra a tu gente</h2><div class="discovery-shortcuts"><a class="card" href="<?= e(rutaAplicacion('musicos.php')) ?>"><span aria-hidden="true">↗</span><strong>Buscar músicos y amigos</strong><small>Nombre, instrumento o ciudad</small></a><a class="card" href="<?= e(rutaAplicacion('busco.php')) ?>"><span aria-hidden="true">＋</span><strong>Encuentra una colaboración</strong><small>Proyectos que buscan tu talento</small></a><a class="card" href="<?= e(rutaAplicacion('bandas.php')) ?>"><span aria-hidden="true">♫</span><strong>Explora las bandas</strong><small>Tu próximo escenario</small></a></div></section>
 <section class="discovery-genres"><h2>¿Qué quieres escuchar?</h2><div class="genre-chips"><?php foreach (GENEROS as $genre): ?><a href="feed.php?genero=<?= rawurlencode($genre) ?>"><?= e($genre) ?></a><?php endforeach; ?></div></section>
 <?php else: ?>
 <nav class="discovery-tabs" aria-label="Tipos de resultado"><a href="buscar.php?q=<?= rawurlencode($query) ?>" <?= $kind === '' ? 'aria-current="page"' : '' ?>>Todo</a><?php foreach ($groups as $key => $title): ?><a href="buscar.php?q=<?= rawurlencode($query) ?>&amp;tipo=<?= e($key) ?>" <?= $kind === $key ? 'aria-current="page"' : '' ?>><?= e($title) ?></a><?php endforeach; ?></nav>
 <?php $any = false;
     foreach ($groups as $key => $title): if ($kind !== '' && $kind !== $key) {
         continue;
     }
         $items = $results[$key] ?? [];
         if (!$items) {
             continue;
         }
         $any = true; ?>
 <section class="discovery-group"><h2><?= e($title) ?></h2><ul><?php foreach ($items as $item): ?><li><a href="<?= e($item['url']) ?>"><?php if (!empty($item['imagen'])): ?><img src="<?= e(musicmadPublicImageUrl($item['imagen'])) ?>" alt="" class="post-avatar" width="40" height="40" loading="lazy"><?php else: ?><span class="discovery-result-icon" aria-hidden="true"><?= ['musicos' => '↗','publicaciones' => '♫','bandas' => '♬','hashtags' => '#','generos' => '♪'][$key] ?></span><?php endif; ?><span><strong><?= e($item['titulo']) ?></strong><small><?= e($item['detalle']) ?></small></span><b aria-hidden="true">→</b></a></li><?php endforeach; ?></ul></section>
 <?php endforeach;
     if (!$any && !$error): ?><div class="card vacio">No encontramos coincidencias. Prueba otro nombre, instrumento o género.</div><?php endif; ?>
 <p class="muted">Mostramos los primeros resultados disponibles para tu cuenta. <a href="musicos.php?q=<?= rawurlencode($query) ?>">Ver más músicos</a></p>
 <?php endif; ?>
</main>
<?php require __DIR__ . '/includes/footer.php'; ?>
