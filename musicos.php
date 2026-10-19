<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/social.php';
requerirSesion();
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    exit('Método no permitido.');
}
$yo = (int)sesion('usuario_id');
$q = mb_substr(trim(textoCampo($_GET, 'q')), 0, 80);
$pagina = max(1, min(10000, idCampo($_GET, 'pagina')));
$listaTipo = in_array(textoCampo($_GET, 'lista'), ['seguidores', 'siguiendo'], true) ? textoCampo($_GET, 'lista') : '';
$listaUsuario = $listaTipo ? (idCampo($_GET, 'usuario') ?: $yo) : 0;
$perfilLista = $listaTipo ? obtenerPerfil($listaUsuario) : null;
if ($listaTipo && !$perfilLista) {
    header('Location: musicos.php');
    exit;
}
if ($listaTipo) {
    $musicos = puedeVerPerfilSocial3($yo, $listaUsuario) ? listarRelacionesMusico($listaUsuario, $listaTipo, 100, $yo) : [];
    $resultado = ['items' => $musicos, 'total' => count($musicos), 'pagina' => 1, 'por_pagina' => max(1, count($musicos))];
    $totalPaginas = 1;
} else {
    $resultado = buscarMusicos($yo, $q, $pagina);
    $musicos = $resultado['items'];
    $totalPaginas = max(1, (int)ceil($resultado['total'] / $resultado['por_pagina']));
    if ($pagina > $totalPaginas) {
        $pagina = $totalPaginas;
        $resultado = buscarMusicos($yo, $q, $pagina);
        $musicos = $resultado['items'];
    }
}
$urlPagina = static fn (int $n): string => 'musicos.php?' . http_build_query(array_filter(['q' => $q,'pagina' => $n], static fn ($v) => $v !== '' && $v !== null));
$pageTitle = $listaTipo ? ($listaTipo === 'seguidores' ? 'Seguidores' : 'Siguiendo') : 'Buscar músicos';
$pageCss = ['CSS/social.css'];
$navActivo = 'musicos';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/navbar.php';
?>
<main class="container musicians-page">
  <header class="musicians-heading"><span class="community-kicker">Gente que hace música</span><h1><?= $listaTipo ? e($listaTipo === 'seguidores' ? 'Seguidores de ' . $perfilLista['nombre'] : $perfilLista['nombre'] . ' sigue') : 'Encuentra tu próxima conexión.' ?></h1><p><?= $listaTipo ? 'Músicos conectados en MusicMad.' : 'Busca por nombre, instrumento, género o ciudad.' ?></p></header>
  <?php if (!$listaTipo): ?><form class="musician-search card" method="GET"><label for="q">Buscar en la comunidad</label><div><input id="q" name="q" maxlength="80" value="<?= e($q) ?>" placeholder="Ej. baterista, jazz, Guadalajara…"><button class="btn btn-primary" type="submit">Buscar</button></div></form><?php endif; ?>
  <section class="musician-results" aria-live="polite">
    <h2><?= $listaTipo ? ($listaTipo === 'seguidores' ? 'Seguidores' : 'Siguiendo') : ($q !== '' ? 'Resultados' : 'Músicos de la comunidad') ?> <small><?= (int)$resultado['total'] ?> perfiles</small></h2>
    <?php if (!$musicos): ?><div class="card vacio"><?= $listaTipo ? 'Esta lista todavía está vacía o no tienes acceso a ella.' : 'No encontramos músicos con esa búsqueda. Prueba con otro instrumento o ciudad.' ?></div><?php endif; ?>
    <?php foreach ($musicos as $m): ?><article class="musician-result card"><img src="<?= e(musicmadPublicImageUrl($m['avatar'])) ?>" alt="" class="post-avatar grande"><div class="musician-result-info"><h3><a href="<?= e(rutaAplicacion('musico.php?id=' . (int)$m['id'])) ?>"><?= e($m['nombre'] . ' ' . $m['apellido']) ?></a></h3><p><?= e($m['instrumento'] ?: 'Músico') ?><?= $m['ciudad'] ? ' · ' . e($m['ciudad']) : '' ?></p><?php if ($m['generos']): ?><small><?= e($m['generos']) ?></small><?php endif; ?></div><a class="btn btn-ghost" href="<?= e(rutaAplicacion('musico.php?id=' . (int)$m['id'])) ?>">Ver perfil</a></article><?php endforeach; ?>
    <?php if (!$listaTipo && $totalPaginas > 1): ?><nav class="musician-pagination" aria-label="Páginas de músicos"><a class="btn btn-ghost <?= $pagina <= 1 ? 'is-disabled' : '' ?>" href="<?= e($urlPagina(max(1, $pagina - 1))) ?>" <?= $pagina <= 1 ? 'aria-disabled="true" tabindex="-1"' : '' ?>>← Anterior</a><span>Página <?= $pagina ?> de <?= $totalPaginas ?></span><a class="btn btn-ghost <?= $pagina >= $totalPaginas ? 'is-disabled' : '' ?>" href="<?= e($urlPagina(min($totalPaginas, $pagina + 1))) ?>" <?= $pagina >= $totalPaginas ? 'aria-disabled="true" tabindex="-1"' : '' ?>>Siguiente →</a></nav><?php endif; ?>
  </section>
</main>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
