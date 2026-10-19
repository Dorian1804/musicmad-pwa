<?php
ob_start();
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/social.php';
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    exit('Método no permitido.');
}
requerirSesion();
$yo = (int)sesion('usuario_id');
$q = mb_substr(trim(textoCampo($_GET, 'q')), 0, 80);
$filtros = ['pagina' => max(1, min(10000, idCampo($_GET, 'pagina'))),'anuncio' => idCampo($_GET, 'anuncio'),'categoria' => textoCampo($_GET, 'categoria'),'ciudad' => textoCampo($_GET, 'ciudad'),'min' => textoCampo($_GET, 'min'),'max' => textoCampo($_GET, 'max'),'guardados' => textoCampo($_GET, 'guardados') === '1'];
$categoria = $filtros['categoria'];
if (!in_array($categoria, MERCADO_CATEGORIAS, true)) {
    $categoria = '';
}
$filtros['categoria'] = $categoria;
foreach (['min','max'] as $key) {
    if ($filtros[$key] !== '' && !preg_match('/^\d{1,8}(\.\d{1,2})?$/', $filtros[$key])) {
        $filtros[$key] = '';
    }
}
$filtros['ciudad'] = mb_substr(trim($filtros['ciudad']), 0, 80);
$filtros['orden'] = in_array(textoCampo($_GET, 'orden'), ['precio_bajo','precio_alto'], true) ? textoCampo($_GET, 'orden') : '';
$resultadoMercado = listarInstrumentos($yo, $q, $filtros);
$anuncios = $resultadoMercado['items'];
$totalPaginas = max(1, (int)ceil($resultadoMercado['total'] / $resultadoMercado['por_pagina']));
if ($filtros['pagina'] > $totalPaginas) {
    $filtros['pagina'] = $totalPaginas;
    $resultadoMercado = listarInstrumentos($yo, $q, $filtros);
    $anuncios = $resultadoMercado['items'];
}
$urlPagina = static function (int $n) use ($q, $categoria, $filtros): string {
    $args = ['q' => $q,'categoria' => $categoria,'ciudad' => $filtros['ciudad'],'min' => $filtros['min'],'max' => $filtros['max'],'orden' => $filtros['orden'],'guardados' => $filtros['guardados'] ? '1' : '','pagina' => $n];
    return 'mercado.php?' . http_build_query(array_filter($args, static fn ($v) => $v !== '' && $v !== null));
};
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$volver = 'mercado.php' . (($qs = http_build_query(array_filter(['q' => $q,'categoria' => $categoria,'ciudad' => $filtros['ciudad'],'min' => $filtros['min'],'max' => $filtros['max'],'orden' => $filtros['orden'],'guardados' => $filtros['guardados'] ? '1' : '','anuncio' => $filtros['anuncio'] ?: '']))) ? '?' . $qs : '');
$pageTitle = 'Instrumentos usados';
$pageCss = ['CSS/social.css'];
$pageJs = ['js/market.js','js/upload-progress.js'];
$navActivo = 'mercado';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/navbar.php';
?>
<main class="container market-page">
  <?php if ($flash): ?><div class="alert alert-<?= e($flash[0]) ?>" data-autohide="6000"><?= e($flash[1]) ?></div><?php endif; ?>
  <header class="market-hero"><div><span class="community-kicker">Equipo con otra historia</span><h1>Instrumentos usados, listos para sonar.</h1><p>Compra y vende entre músicos. Habla directamente con quien publica.</p></div><span class="market-note">Sin comisión · Trato directo</span></header>

  <details class="market-compose card" data-sheet data-sheet-title="Publicar un instrumento"><summary><span>＋ Publicar un instrumento</span><small>Vendes algo que merece otra canción</small></summary>
    <form method="POST" action="<?= e(rutaAplicacion('social-accion.php', 'POST')) ?>" enctype="multipart/form-data" class="market-form" data-upload-form>
      <?= csrfCampo() ?><input type="hidden" name="accion" value="instrumento_publicar"><input type="hidden" name="volver" value="mercado.php">
      <div class="market-form-grid">
        <label>Qué vendes<input name="titulo" maxlength="120" required placeholder="Ej. Fender Squier Jazz Bass"></label>
        <label>Categoría<select name="categoria" required><?php foreach (MERCADO_CATEGORIAS as $cat): ?><option value="<?= e($cat) ?>"><?= e($cat) ?></option><?php endforeach; ?></select></label>
        <label>Precio (MXN)<input name="precio" type="number" min="1" max="10000000" step="0.01" inputmode="decimal" required placeholder="4500"></label>
        <label>Estado<select name="condicion" required><option>Buen estado</option><option>Como nuevo</option><option>Con detalles</option></select></label>
        <label>Ciudad<input name="ciudad" maxlength="80" placeholder="Tu ciudad (opcional)"></label>
        <label class="market-photo-label">Fotos del instrumento <small>JPG, PNG o WebP · hasta 20 MB de origen; se optimizan · hasta 4 fotos</small><input type="file" name="fotos[]" accept="image/jpeg,image/png,image/webp" multiple data-max-files="4"></label>
        <label class="market-description">Descripción<textarea name="descripcion" maxlength="700" rows="3" placeholder="Cuenta cómo suena, qué incluye y si tiene algún detalle…"></textarea></label>
      </div>
      <button class="btn btn-primary" type="submit">Publicar anuncio</button>
    </form>
  </details>

  <form method="GET" class="market-search card"><label class="sr-only" for="market-quick-q">Buscar instrumentos</label><input id="market-quick-q" name="q" maxlength="80" value="<?= e($q) ?>" placeholder="¿Qué instrumento buscas?"><button class="btn btn-primary" type="submit">Buscar</button></form>
  <details class="card quick-sheet" data-sheet data-sheet-title="Filtros del mercado"><summary><strong>Filtros, precios y favoritos</strong><span aria-hidden="true">☷</span></summary>
  <form method="GET" class="market-filters">
    <div class="market-filter-title"><div><span>AFINA TU BÚSQUEDA</span><strong>Encuentra tu próximo sonido</strong></div><a href="<?= e(rutaAplicacion('mercado.php')) ?>">Limpiar filtros</a></div>
    <label class="sr-only" for="market-q">Buscar instrumentos</label><input id="market-q" name="q" maxlength="80" value="<?= e($q) ?>" placeholder="Buscar instrumento o ciudad…">
    <label class="sr-only" for="market-cat">Categoría</label><select id="market-cat" name="categoria"><option value="">Todas las categorías</option><?php foreach (MERCADO_CATEGORIAS as $cat): ?><option value="<?= e($cat) ?>" <?= $categoria === $cat ? 'selected' : '' ?>><?= e($cat) ?></option><?php endforeach; ?></select>
    <label class="sr-only" for="market-city">Ciudad</label><input id="market-city" name="ciudad" maxlength="80" value="<?= e($filtros['ciudad']) ?>" placeholder="Ciudad">
    <label class="sr-only" for="market-min">Precio mínimo</label><input id="market-min" name="min" type="number" min="0" step="0.01" value="<?= e($filtros['min']) ?>" placeholder="Desde $">
    <label class="sr-only" for="market-max">Precio máximo</label><input id="market-max" name="max" type="number" min="0" step="0.01" value="<?= e($filtros['max']) ?>" placeholder="Hasta $">
    <label class="sr-only" for="market-sort">Ordenar anuncios</label><select id="market-sort" name="orden"><option value="">Más recientes</option><option value="precio_bajo" <?= $filtros['orden'] === 'precio_bajo' ? 'selected' : '' ?>>Precio: menor primero</option><option value="precio_alto" <?= $filtros['orden'] === 'precio_alto' ? 'selected' : '' ?>>Precio: mayor primero</option></select>
    <label class="market-saved-filter"><input type="checkbox" name="guardados" value="1" <?= $filtros['guardados'] ? 'checked' : '' ?>> Favoritos</label>
    <button class="btn btn-ghost" type="submit">Filtrar</button>
  </form></details>

  <section class="market-results"><div class="market-results-title"><div><span class="market-section-kicker"><?= (int)$resultadoMercado['total'] ?> anuncios publicados</span><h2>Equipo en la comunidad</h2></div><span>Sin comisión · Trato directo</span></div>
    <?php if (!$anuncios): ?><div class="card vacio"><p>No encontramos anuncios con esos filtros.</p><p>Cuando alguien publique un instrumento, aparecerá aquí.</p></div><?php endif; ?>
    <div class="market-grid">
    <?php foreach ($anuncios as $item): $esMio = (int)$item['usuario_id'] === $yo;
        $nombres = array_filter(explode(',', (string)($item['galeria'] ?? '')));
        if (!$nombres && !empty($item['foto'])) {
            $nombres = [$item['foto']];
        }
        $fotos = array_values(array_filter($nombres, fn ($f) => preg_match('/^[a-f0-9]{24}\.jpg$/', $f)));
        $foto = $fotos ? 'uploads/market/' . $fotos[0] : (isset($item['foto']) && is_string($item['foto']) && preg_match('/^[a-f0-9]{24}\.jpg$/', $item['foto']) ? 'uploads/market/' . $item['foto'] : ''); ?>
      <article class="market-card card <?= $item['estado'] !== 'disponible' ? 'is-sold' : '' ?>">
        <div class="market-image-wrap">
          <?php if ($foto): ?><img class="market-photo" data-market-main src="<?= e(musicmadPublicImageUrl($foto)) ?>" alt="<?= e($item['titulo']) ?>" loading="lazy"><?php else: ?><div class="market-photo market-placeholder" aria-hidden="true"><span>♬</span><small><?= e($item['categoria']) ?></small></div><?php endif; ?>
          <?php if (count($fotos) > 1): ?><div class="market-thumbs" aria-label="Fotos del instrumento"><?php foreach ($fotos as $i => $fn): ?><button type="button" data-market-thumb="<?= e(musicmadPublicImageUrl('uploads/market/' . $fn)) ?>" aria-label="Ver foto <?= $i + 1 ?>" class="<?= $i === 0 ? 'is-active' : '' ?>"><img src="<?= e(musicmadPublicImageUrl('uploads/market/' . $fn)) ?>" alt="" loading="lazy"></button><?php endforeach; ?></div><?php endif; ?><button type="button" class="market-share" data-market-share="mercado.php?anuncio=<?= (int)$item['id'] ?>">↗ Compartir</button>
          <?php if (!$esMio && $item['estado'] === 'disponible'): ?><form class="market-fav-form" method="POST" action="<?= e(rutaAplicacion('social-accion.php', 'POST')) ?>"><?= csrfCampo() ?><input type="hidden" name="accion" value="instrumento_favorito"><input type="hidden" name="id" value="<?= (int)$item['id'] ?>"><input type="hidden" name="volver" value="<?= e($volver) ?>"><button class="market-fav <?= !empty($item['yo_favorito']) ? 'is-favorite' : '' ?>" type="submit" aria-label="<?= !empty($item['yo_favorito']) ? 'Quitar de favoritos' : 'Guardar en favoritos' ?>" title="<?= !empty($item['yo_favorito']) ? 'Quitar de favoritos' : 'Guardar en favoritos' ?>">♥ <span><?= (int)$item['favoritos'] ?></span></button></form><?php endif; ?>
        </div>
        <div class="market-card-body">
          <div class="market-card-meta"><span><?= e($item['categoria']) ?></span><span><?= e($item['condicion']) ?></span></div>
          <h3><?= e($item['titulo']) ?></h3><strong class="market-price">$<?= number_format((float)$item['precio'], 2) ?> <small>MXN</small></strong>
          <?php if ($item['descripcion'] !== ''): ?><p class="market-description"><?= nl2br(e($item['descripcion'])) ?></p><?php endif; ?>
          <div class="market-seller"><img src="<?= e(musicmadPublicImageUrl($item['avatar'])) ?>" alt=""><span><a href="<?= e(rutaAplicacion('musico.php?id=' . (int)$item['usuario_id'])) ?>"><?= e($item['nombre'] . ' ' . $item['apellido']) ?></a><small><?= $item['ciudad'] !== '' ? '📍 ' . e($item['ciudad']) : 'Músico de MusicMad' ?> · <?= e(hace($item['created_at'])) ?></small></span></div>
          <?php if ($item['estado'] === 'vendido'): ?><span class="market-sold-label">Vendido</span><?php if ($esMio): ?><form method="POST" action="<?= e(rutaAplicacion('social-accion.php', 'POST')) ?>"><?= csrfCampo() ?><input type="hidden" name="accion" value="instrumento_reactivar"><input type="hidden" name="id" value="<?= (int)$item['id'] ?>"><input type="hidden" name="volver" value="<?= e($volver) ?>"><button class="btn btn-ghost btn-sm" type="submit">Volver a publicar</button></form><?php endif; ?>
          <?php elseif ($item['estado'] === 'oculto'): ?><span class="market-sold-label">Retirado para revisión</span><?php if ($esMio): ?><form class="market-delete-hidden" method="POST" action="<?= e(rutaAplicacion('social-accion.php', 'POST')) ?>"><?= csrfCampo() ?><input type="hidden" name="accion" value="instrumento_eliminar"><input type="hidden" name="id" value="<?= (int)$item['id'] ?>"><input type="hidden" name="volver" value="<?= e($volver) ?>"><button type="submit" class="btn btn-ghost btn-sm">Eliminar anuncio</button></form><?php endif; ?>
          <?php elseif ($item['estado'] === 'reservado' && !$esMio): ?><span class="market-sold-label market-reserved-label">Reservado</span>
          <?php elseif (!$esMio): ?><a class="btn btn-primary btn-full" href="<?= e(rutaAplicacion('mensajes.php?con=' . (int)$item['usuario_id'])) ?>">✉ Contactar por chat</a>
          <?php else: ?><div class="market-owner-actions">
            <?php if ($item['estado'] === 'disponible'): ?><form method="POST" action="<?= e(rutaAplicacion('social-accion.php', 'POST')) ?>"><?= csrfCampo() ?><input type="hidden" name="accion" value="instrumento_reservar"><input type="hidden" name="id" value="<?= (int)$item['id'] ?>"><input type="hidden" name="volver" value="<?= e($volver) ?>"><button class="btn btn-ghost btn-sm" type="submit">Marcar reservado</button></form><?php else: ?><form method="POST" action="<?= e(rutaAplicacion('social-accion.php', 'POST')) ?>"><?= csrfCampo() ?><input type="hidden" name="accion" value="instrumento_reactivar"><input type="hidden" name="id" value="<?= (int)$item['id'] ?>"><input type="hidden" name="volver" value="<?= e($volver) ?>"><button class="btn btn-ghost btn-sm" type="submit">Volver disponible</button></form><?php endif; ?>
            <form method="POST" action="<?= e(rutaAplicacion('social-accion.php', 'POST')) ?>"><?= csrfCampo() ?><input type="hidden" name="accion" value="instrumento_vendido"><input type="hidden" name="id" value="<?= (int)$item['id'] ?>"><input type="hidden" name="volver" value="<?= e($volver) ?>"><button class="btn btn-ghost btn-sm" type="submit">Marcar vendido</button></form>
            <form method="POST" action="<?= e(rutaAplicacion('social-accion.php', 'POST')) ?>" data-confirm="¿Eliminar este anuncio?"><?= csrfCampo() ?><input type="hidden" name="accion" value="instrumento_eliminar"><input type="hidden" name="id" value="<?= (int)$item['id'] ?>"><input type="hidden" name="volver" value="<?= e($volver) ?>"><button class="btn btn-ghost btn-sm" type="submit">Eliminar</button></form>
          </div><?php endif; ?>
          <?php if (!$esMio && $item['estado'] === 'disponible'): ?><details class="market-report" data-sheet data-sheet-title="Reportar anuncio"><summary>Reportar anuncio</summary><form method="POST" action="<?= e(rutaAplicacion('social-accion.php', 'POST')) ?>"><?= csrfCampo() ?><input type="hidden" name="accion" value="instrumento_reportar"><input type="hidden" name="id" value="<?= (int)$item['id'] ?>"><input type="hidden" name="volver" value="<?= e($volver) ?>"><label>Motivo<select name="motivo" required><option value="">Selecciona</option><option>Información engañosa</option><option>Posible fraude</option><option>Producto prohibido</option><option>Contenido inapropiado</option><option>Otro</option></select></label><textarea name="detalle" maxlength="250" rows="2" placeholder="Detalle opcional"></textarea><button type="submit" class="btn btn-ghost btn-sm">Enviar reporte</button></form></details><?php endif; ?>
        </div>
      </article>
    <?php endforeach; ?>
    </div>
    <?php if ($totalPaginas > 1): ?><nav class="market-pagination" aria-label="Páginas del marketplace"><a class="btn btn-ghost <?= $filtros['pagina'] <= 1 ? 'is-disabled' : '' ?>" href="<?= e($urlPagina(max(1, $filtros['pagina'] - 1))) ?>" <?= $filtros['pagina'] <= 1 ? 'aria-disabled="true" tabindex="-1"' : '' ?>>← Anterior</a><span>Página <?= (int)$filtros['pagina'] ?> de <?= $totalPaginas ?></span><a class="btn btn-ghost <?= $filtros['pagina'] >= $totalPaginas ? 'is-disabled' : '' ?>" href="<?= e($urlPagina(min($totalPaginas, $filtros['pagina'] + 1))) ?>" <?= $filtros['pagina'] >= $totalPaginas ? 'aria-disabled="true" tabindex="-1"' : '' ?>>Siguiente →</a></nav><?php endif; ?>
  </section>
</main>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
