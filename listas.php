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
$yo = (int) sesion('usuario_id');
$listaId = idCampo($_GET, 'id');
$agregarId = idCampo($_GET, 'agregar');
$lista = $listaId ? obtenerPlaylistSocial3($listaId, $yo) : null;
if ($listaId && !$lista) {
    http_response_code(404);
}
$misListas = social3Disponible() ? playlistsDelUsuarioSocial3($yo) : [];
$compartidas = playlistsEquipoDisponible() ? listasEquipoUsuario($yo) : [];
foreach ($compartidas as &$item) {
    $item['pistas'] = count(pistasPlaylistSocial3((int)$item['id'], $yo));
}
unset($item);
$listasEditables = array_merge($misListas, $compartidas);
$publicas = social3Disponible() ? playlistsPublicasSocial3($yo) : [];
$pistas = $lista ? pistasPlaylistSocial3($listaId, $yo) : [];
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$pageTitle = $lista ? $lista['nombre'] : 'Playlists';
$pageCss = ['CSS/social.css'];
$navActivo = 'listas';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/navbar.php';
?>
<main class="container playlists-page"><?php if (playlistsEquipoDisponible() && funcionalidadMusicMad('playlists_equipo', $yo)): ?><p><a class="btn btn-ghost" href="<?= e(rutaAplicacion('playlist-equipo.php')) ?>">Colecciones compartidas e invitaciones →</a></p><?php endif; ?>
  <?php if ($flash): ?><div class="alert alert-<?= e($flash[0]) ?>" role="status"><?= e($flash[1]) ?></div><?php endif; ?>
  <?php if (!social3Disponible()): ?>
    <section class="card vacio"><h1>Playlists</h1><p>Importa <code>config/social3.sql</code> en tu base para activar las listas personales.</p></section>
  <?php elseif ($lista): ?>
    <header class="playlist-hero"><span class="community-kicker">COLECCIÓN MUSICAL · <?= $lista['publica'] ? 'PÚBLICA' : 'PRIVADA' ?></span><h1><?= e($lista['nombre']) ?></h1><p><?= e($lista['descripcion']) ?></p><div class="playlist-author"><img src="<?= e(musicmadPublicImageUrl($lista['autor_avatar'])) ?>" alt=""><span>Creada por <?= e($lista['autor'] . ' ' . $lista['autor_apellido']) ?> · <?= count($pistas) ?> pistas</span></div>
      <?php if ((int) $lista['usuario_id'] === $yo): ?><form method="POST" action="<?= e(rutaAplicacion('social-accion.php', 'POST')) ?>" data-confirm="¿Eliminar esta playlist? Las publicaciones y archivos originales se conservan."><?= csrfCampo() ?><input type="hidden" name="accion" value="playlist_eliminar"><input type="hidden" name="id" value="<?= $listaId ?>"><input type="hidden" name="volver" value="listas.php"><button type="submit" class="btn btn-ghost">Eliminar playlist</button></form><?php endif; ?>
    </header>
    <?php if (playlistsEquipoDisponible() && funcionalidadMusicMad('playlists_equipo', $yo) && puedeEditarPlaylist($yo, $lista)): ?><p><a class="btn btn-ghost" href="playlist-equipo.php?id=<?= $listaId ?>">Administrar equipo →</a></p><?php endif; ?><div class="playlist-tracks">
      <?php if (!$pistas): ?><section class="card vacio"><h2>Esta playlist todavía no tiene pistas</h2><p>Desde una publicación con audio, toca “Playlist” para guardarla aquí.</p></section><?php endif; ?>
      <?php foreach ($pistas as $indice => $p): ?>
        <article class="card playlist-track-row">
          <span class="playlist-track-number"><?= str_pad((string) ($indice + 1), 2, '0', STR_PAD_LEFT) ?></span>
          <button class="track-play" type="button" data-track-play data-track-id="<?= (int) $p['id'] ?>" data-track-src="audio.php?id=<?= (int) $p['id'] ?>" data-track-title="<?= e($p['titulo']) ?>" data-track-artist="<?= e($p['nombre'] . ' ' . $p['apellido']) ?>" aria-label="Reproducir <?= e($p['titulo']) ?>"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m8 5 12 7-12 7z"/></svg></button>
          <div class="playlist-track-copy"><strong><?= e($p['titulo']) ?></strong><a href="<?= e(rutaAplicacion('musico.php?id=' . (int) $p['usuario_id'])) ?>"><?= e($p['nombre'] . ' ' . $p['apellido']) ?></a><small><?= e($p['genero'] ?: 'Música') ?></small></div>
          <?php if (puedeEditarPlaylist($yo, $lista) && ((int)$lista['usuario_id'] === $yo || (int)$p['agregado_por'] === $yo)): ?><form method="POST" action="<?= e(rutaAplicacion('social-accion.php', 'POST')) ?>"><?= csrfCampo() ?><input type="hidden" name="accion" value="playlist_quitar"><input type="hidden" name="playlist" value="<?= $listaId ?>"><input type="hidden" name="publicacion" value="<?= (int) $p['id'] ?>"><input type="hidden" name="volver" value="listas.php?id=<?= $listaId ?>"><button class="btn btn-ghost btn-sm" type="submit" aria-label="Quitar <?= e($p['titulo']) ?> de la playlist">Quitar</button></form><?php endif; ?>
        </article>
      <?php endforeach; ?>
    </div>
    <p><a class="btn btn-ghost" href="<?= e(rutaAplicacion('listas.php')) ?>">← Volver a playlists</a></p>
  <?php else: ?>
    <header class="playlist-heading"><span class="community-kicker">TU MÚSICA, EN ORDEN</span><h1>Playlists</h1><p>Guarda pistas de la comunidad y arma colecciones para cada ensayo, mood o proyecto.</p></header>
    <?php if ($agregarId): ?><section class="card playlist-add-panel"><h2>Añadir pista a una lista</h2><p>Elige una playlist tuya o compartida contigo. Solo se guardará una referencia; la pista original no se copia.</p>
      <?php if (!$listasEditables): ?><p>Aún no tienes listas. Crea una aquí abajo y vuelve a la publicación.</p><?php else: ?><div class="playlist-choose-list"><?php foreach ($listasEditables as $opcion): ?><form method="POST" action="<?= e(rutaAplicacion('social-accion.php', 'POST')) ?>"><?= csrfCampo() ?><input type="hidden" name="accion" value="playlist_agregar"><input type="hidden" name="playlist" value="<?= (int) $opcion['id'] ?>"><input type="hidden" name="publicacion" value="<?= $agregarId ?>"><input type="hidden" name="volver" value="listas.php?id=<?= (int) $opcion['id'] ?>"><button type="submit"><strong><?= e($opcion['nombre']) ?></strong><small><?= (int) $opcion['pistas'] ?> pistas · <?= $opcion['publica'] ? 'Pública' : 'Privada' ?></small><span aria-hidden="true">＋</span></button></form><?php endforeach; ?></div><?php endif; ?>
    </section><?php endif; ?>
    <details class="card quick-sheet" data-sheet data-sheet-title="Nueva playlist"><summary><strong>Nueva playlist</strong><span aria-hidden="true">＋</span></summary><section class="playlist-create-panel"><div><span class="community-kicker">EMPIEZA UNA COLECCIÓN</span><h2>Nueva playlist</h2><p>Elige si otros músicos pueden descubrirla.</p></div><form method="POST" action="<?= e(rutaAplicacion('social-accion.php', 'POST')) ?>" class="playlist-create-form"><?= csrfCampo() ?><input type="hidden" name="accion" value="playlist_crear"><input type="hidden" name="volver" value="listas.php"><label>Nombre<input type="text" name="nombre" maxlength="80" required placeholder="Ej. Ideas para el ensayo"></label><label>Descripción<textarea name="descripcion" maxlength="300" rows="2" placeholder="Qué tienen en común estas pistas"></textarea></label><label class="playlist-public-toggle"><input type="checkbox" name="publica" value="1"><span><strong>Hacer pública</strong><small>Se podrá abrir desde el perfil de su creador.</small></span></label><button class="btn btn-primary" type="submit">Crear playlist</button></form></section></details>
    <section class="playlist-section"><div class="playlist-section-title"><div><span class="community-kicker">TU BIBLIOTECA</span><h2>Mis playlists</h2></div><span><?= count($misListas) ?> listas</span></div>
      <?php if (!$misListas): ?><div class="card vacio">Cuando crees una lista, aparecerá aquí.</div><?php else: ?><div class="playlist-grid"><?php foreach ($misListas as $p): ?><a class="card playlist-tile" href="<?= e(rutaAplicacion('listas.php?id=' . (int) $p['id'])) ?>"><span class="playlist-tile-art" aria-hidden="true">♫</span><span class="playlist-tile-copy"><strong><?= e($p['nombre']) ?></strong><small><?= (int) $p['pistas'] ?> pistas · <?= $p['publica'] ? 'Pública' : 'Privada' ?></small></span><span aria-hidden="true">↗</span></a><?php endforeach; ?></div><?php endif; ?>
    </section>
    <?php if ($publicas): ?><section class="playlist-section"><div class="playlist-section-title"><div><span class="community-kicker">DE LA ESCENA</span><h2>Playlists públicas</h2></div></div><div class="playlist-grid"><?php foreach ($publicas as $p): ?><a class="card playlist-tile" href="<?= e(rutaAplicacion('listas.php?id=' . (int) $p['id'])) ?>"><span class="playlist-tile-art" aria-hidden="true">♫</span><span class="playlist-tile-copy"><strong><?= e($p['nombre']) ?></strong><small><?= (int) $p['pistas'] ?> pistas · <?= e($p['autor'] . ' ' . $p['autor_apellido']) ?></small></span><span aria-hidden="true">↗</span></a><?php endforeach; ?></div></section><?php endif; ?>
  <?php endif; ?>
</main>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
