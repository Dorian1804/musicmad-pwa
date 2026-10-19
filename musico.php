<?php
// ============================================================
//  MusicMad – Perfil público de un músico
//  musico.php?id=N
// ============================================================
ob_start();
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/social.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    exit('Método no permitido.');
}
require_once __DIR__ . '/includes/publicacion.php';

requerirSesion();

$yo = (int) sesion('usuario_id');
$id = idCampo($_GET, 'id') ?: $yo;

$perfil = obtenerPerfil($id);
if (!$perfil) {
    header('Location: feed.php');
    exit;
}
if (estadoAccesoPerfilSocial3($yo, $id) === 'bloqueado') {
    $_SESSION['flash'] = ['error', 'Este perfil es privado o no está disponible para tu cuenta.'];
    header('Location: feed.php');
    exit;
}
$estadoAcceso = estadoAccesoPerfilSocial3($yo, $id);
$esYo = $id === $yo;
$musical = obtenerPerfilMusical($id);
if ($estadoAcceso !== 'permitido' && !$esYo) {
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    $solicitud = $estadoAcceso === 'solicitar' ? estadoSolicitudSeguimiento($yo, $id) : '';
    $pageTitle = 'Perfil de ' . $perfil['nombre'];
    $pageCss = ['CSS/social.css'];
    $navActivo = 'feed';
    require_once __DIR__ . '/includes/header.php';
    require_once __DIR__ . '/includes/navbar.php';
    ?>
    <main class="container narrow-wide">
      <?php if ($flash): ?><div class="alert alert-<?= e($flash[0]) ?>" role="status"><?= e($flash[1]) ?></div><?php endif; ?>
      <section class="card restricted-profile">
        <img src="<?= e(musicmadPublicImageUrl($perfil['avatar'])) ?>" alt="Foto de <?= e($perfil['nombre']) ?>" class="musico-avatar" tabindex="0" role="button" aria-label="Ver foto de perfil ampliada">
        <div><span class="community-kicker">PERFIL DE MÚSICO</span><h1><?= e($perfil['nombre'] . ' ' . $perfil['apellido']) ?></h1><p><?= e($musical['instrumento'] ?: 'Músico') ?></p>
          <p><?= $estadoAcceso === 'solicitar' ? 'Este perfil es privado. Envía una solicitud para ver sus publicaciones.' : 'Sigue a este músico para ver sus publicaciones.' ?></p>
          <?php if ($estadoAcceso === 'solicitar' && $solicitud === 'pendiente'): ?><button type="button" class="btn btn-ghost" disabled>Solicitud enviada</button>
          <?php else: ?><form method="POST" action="<?= e(rutaAplicacion('social-accion.php', 'POST')) ?>"><?= csrfCampo() ?><input type="hidden" name="accion" value="seguir"><input type="hidden" name="usuario" value="<?= $id ?>"><input type="hidden" name="volver" value="musico.php?id=<?= $id ?>"><button type="submit" class="btn btn-primary"><?= $estadoAcceso === 'solicitar' ? 'Solicitar seguimiento' : 'Seguir para ver' ?></button></form><?php endif; ?>
        </div>
      </section>
    </main>
    <?php require_once __DIR__ . '/includes/footer.php';
    exit;
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$portada = obtenerPortadaPerfil($id);
$stats = estadisticasMusico($id, $yo);
$actividad = actividadMusico($id);
$esFragmento = ($_GET['fragmento'] ?? null) === 'feed';
try {
    $paginaPerfil = paginaComunidad($yo, 'todo', ['autor' => $id], $esFragmento ? mb_substr(textoCampo($_GET, 'cursor'), 0, 180) : '', 10);
} catch (InvalidArgumentException|OutOfBoundsException $error) {
    http_response_code($error instanceof OutOfBoundsException ? 410 : 422);
    header('Content-Type: text/plain; charset=utf-8');
    echo e($error->getMessage());
    exit;
}
$publicaciones = $paginaPerfil['publicaciones'];
$comentarios = comentariosDe(array_column($publicaciones, 'id'));
$comentarioIds = [];
foreach ($comentarios as $listaComentarios) {
    foreach ($listaComentarios as $comentario) {
        $comentarioIds[] = (int) $comentario['id'];
    }
}
$respuestas = social3Disponible() ? respuestasComentarios($comentarioIds) : [];
$comentariosTiempo = social3Disponible() ? comentariosTiempoDe(array_column($publicaciones, 'id')) : [];
$playlistsPerfil = social3Disponible() ? playlistsDePerfilSocial3($id, $yo) : [];
$volver = 'musico.php?id=' . $id;

$generos = array_filter(array_map('trim', explode(',', $musical['generos'])));

if ($esFragmento) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<div data-feed-fragment data-has-more="' . ($paginaPerfil['hay_mas'] ? '1' : '0') . '" data-feed-cursor="' . e($paginaPerfil['cursor']) . '">';
    foreach ($publicaciones as $p) {
        renderPublicacion($p, $comentarios[$p['id']] ?? [], $yo, $volver, $respuestas, $comentariosTiempo[$p['id']] ?? []);
    }
    echo '</div>';
    exit;
}

$pageTitle = $perfil['nombre'] . ' ' . $perfil['apellido'];
$insigniasArtista = insigniasVisiblesArtista($yo, $id);
$vocesMusico = recomendacionesMusico($yo, $id);
$pageCss = ['CSS/social.css','CSS/artist.css','CSS/endorsements.css'];
$pageProfileIds = array_merge([$id], array_column($publicaciones, 'usuario_id'));
$pageJs = ['js/actividad.js','js/infinite-feed.js'];
$navActivo = $esYo ? 'perfil' : 'feed';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/navbar.php';
?>
<main class="container narrow-wide">
<?php renderCompartirPublico('perfil', (int) $id); ?>
  <?php if ($flash): ?>
    <div class="alert alert-<?= e($flash[0]) ?>" data-autohide="6000"><?= e($flash[1]) ?></div>
  <?php endif; ?>

  <section class="card musico-head">
    <div class="musico-banner" data-profile-style="<?= $id ?>"></div>
    <div class="musico-body">
      <img src="<?= e(musicmadPublicImageUrl($perfil['avatar'])) ?>" alt="Foto de perfil de <?= e($perfil['nombre'] . ' ' . $perfil['apellido']) ?>" class="musico-avatar" tabindex="0" role="button" aria-label="Ver foto de perfil ampliada">
      <div class="musico-info">
        <h1><?= e($perfil['nombre'] . ' ' . $perfil['apellido']) ?> <?php renderInsigniaIdentidad($id, $yo); ?></h1>
        <p class="musico-sub">
          <?= e($musical['instrumento'] ?: 'Músico') ?>
          <?php if ($musical['ciudad'] !== ''): ?> · 📍 <?= e($musical['ciudad']) ?><?php endif; ?>
        </p>
        <?php if ($perfil['bio'] !== ''): ?><p class="musico-bio"><?= nl2br(e($perfil['bio'])) ?></p><?php endif; ?>
        <?php if ($generos): ?>
          <p class="post-tags"><?php foreach ($generos as $g): ?><span class="chip">#<?= e($g) ?></span> <?php endforeach; ?></p>
        <?php endif; ?>
      </div>
      <?php if ($insigniasArtista): ?><div class="profile-awards" aria-label="Insignias de este músico"><?php foreach ($insigniasArtista as $badge): ?><span title="<?= e($badge['titulo']) ?>"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m12 2 8 5v10l-8 5-8-5V7Z"/><path d="m8 12 3 3 5-6"/></svg><?= e($badge['titulo']) ?></span><?php endforeach; ?></div><?php endif; ?>
      <div class="musico-cta">
        <?php if ($esYo): ?>
          <a href="<?= e(rutaAplicacion('perfil.php')) ?>" class="btn btn-ghost">Editar perfil</a>
        <?php else: ?>
          <a href="mensajes.php?con=<?= $id ?>" class="btn btn-ghost">✉️ Mensaje</a>
          <form method="POST" action="<?= e(rutaAplicacion('social-accion.php', 'POST')) ?>">
            <?= csrfCampo() ?>
            <input type="hidden" name="accion" value="seguir">
            <input type="hidden" name="usuario" value="<?= $id ?>">
            <input type="hidden" name="volver" value="<?= e($volver) ?>">
            <button type="submit" class="btn <?= $stats['lo_sigo'] ? 'btn-ghost' : 'btn-primary' ?>">
              <?= $stats['lo_sigo'] ? 'Siguiendo ✓' : 'Seguir' ?>
            </button>
          </form>
          <?php if (social3Disponible()): ?>
            <details class="profile-more-actions"><summary>Más opciones</summary>
              <form method="POST" action="<?= e(rutaAplicacion('social-accion.php', 'POST')) ?>">
                <?= csrfCampo() ?><input type="hidden" name="accion" value="silenciar_usuario"><input type="hidden" name="usuario" value="<?= $id ?>"><input type="hidden" name="volver" value="<?= e($volver) ?>">
                <button type="submit" class="btn btn-ghost">Silenciar / quitar silencio</button>
              </form>
              <form method="POST" action="<?= e(rutaAplicacion('social-accion.php', 'POST')) ?>">
                <?= csrfCampo() ?><input type="hidden" name="accion" value="bloquear_usuario"><input type="hidden" name="usuario" value="<?= $id ?>"><input type="hidden" name="volver" value="<?= e($volver) ?>">
                <button type="submit" class="btn btn-ghost">Bloquear / desbloquear</button>
              </form>
              <form method="POST" action="<?= e(rutaAplicacion('social-accion.php', 'POST')) ?>" class="profile-report-form">
                <?= csrfCampo() ?><input type="hidden" name="accion" value="reportar_usuario"><input type="hidden" name="usuario" value="<?= $id ?>"><input type="hidden" name="volver" value="<?= e($volver) ?>">
                <label>Reportar perfil<select name="motivo" required><option value="spam">Spam</option><option value="acoso">Acoso</option><option value="suplantacion">Suplantación</option><option value="derechos">Derechos de autor</option><option value="contenido">Contenido inapropiado</option><option value="otro">Otro</option></select></label>
                <textarea name="detalle" maxlength="500" rows="2" placeholder="Contexto (opcional)"></textarea><button type="submit" class="btn btn-ghost btn-sm">Enviar reporte</button>
              </form>
            </details>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>
    <ul class="stats stats-fila">
      <li><strong><?= $stats['publicaciones'] ?></strong> publicaciones</li>
      <li><a href="musicos.php?lista=seguidores&amp;usuario=<?= $id ?>"><strong><?= $stats['seguidores'] ?></strong> seguidores</a></li>
      <li><a href="musicos.php?lista=siguiendo&amp;usuario=<?= $id ?>"><strong><?= $stats['siguiendo'] ?></strong> siguiendo</a></li>
      <li><strong><?= $stats['aplausos'] ?></strong> aplausos recibidos</li>
    </ul>
  </section>

  <?php if ($playlistsPerfil): ?><section class="card profile-playlists"><div class="playlist-section-title"><div><span class="community-kicker">COLECCIONES</span><h2>Playlists de <?= e($esYo ? 'tu biblioteca' : $perfil['nombre']) ?></h2></div><a class="btn btn-ghost btn-sm" href="<?= e(rutaAplicacion('listas.php')) ?>">Ver las mías</a></div><div class="playlist-grid"><?php foreach ($playlistsPerfil as $listaPerfil): ?><a class="card playlist-tile" href="<?= e(rutaAplicacion('listas.php?id=' . (int) $listaPerfil['id'])) ?>"><span class="playlist-tile-art" aria-hidden="true">♫</span><span class="playlist-tile-copy"><strong><?= e($listaPerfil['nombre']) ?></strong><small><?= $listaPerfil['publica'] ? 'Playlist pública' : 'Privada · solo tú' ?></small></span><span aria-hidden="true">↗</span></a><?php endforeach; ?></div></section><?php endif; ?>

  <section class="card actividad">
    <div class="activity-heading"><div><span class="community-kicker">Ritmo de actividad</span><h3>Contribuciones</h3></div><span class="activity-period">Últimos 3 meses</span></div>
    <?php
      $hoy = new DateTimeImmutable('today');
$inicio = $hoy->modify('-90 days');
$inicio = $inicio->modify('-' . (int)$inicio->format('w') . ' days');
$fin = $hoy->modify('+' . (6 - (int)$hoy->format('w')) . ' days');
$semanas = (int)floor($inicio->diff($fin)->days / 7) + 1;
$meses = [];
$cursor = $inicio;
while ($cursor <= $fin) {
    if ($cursor->format('j') === '1' || $cursor == $inicio) {
        $col = (int)floor($inicio->diff($cursor)->days / 7) + 1;
        $meses[] = ['col' => $col, 'nombre' => ['ene','feb','mar','abr','may','jun','jul','ago','sep','oct','nov','dic'][(int)$cursor->format('n') - 1]];
    }
    $cursor = $cursor->modify('+1 day');
}
?>
    <div class="heat-scroll">
      <div class="heat-months heat-weeks-<?= $semanas ?>"><?php foreach ($meses as $mes): ?><span class="heat-column-<?= (int) $mes['col'] ?>"><?= e($mes['nombre']) ?></span><?php endforeach; ?></div>
      <div class="heat heat-weeks-<?= $semanas ?>" role="group" aria-label="Calendario de contribuciones" aria-describedby="heat-keyboard-help">
        <?php for ($d = $inicio; $d <= $fin; $d = $d->modify('+1 day')):
            $fecha = $d->format('Y-m-d');
            $n = (int)($actividad[$fecha] ?? 0);
            $nivel = $n === 0 ? 0 : ($n === 1 ? 1 : ($n <= 3 ? 2 : ($n <= 5 ? 3 : 4)));
            ?>
          <button class="heat-d n<?= $nivel ?>" type="button" <?= $d > $hoy ? 'disabled' : '' ?> tabindex="<?= $d == $hoy ? '0' : '-1' ?>" data-fecha="<?= e($fecha) ?>" data-cantidad="<?= $n ?>" aria-label="<?= e($fecha) ?>: <?= $n ?> <?= $n === 1 ? 'contribución' : 'contribuciones' ?>" title="<?= e($fecha) ?> · <?= $n ?> contribuciones"></button>
        <?php endfor; ?>
      </div>
    </div>
    <p id="heat-keyboard-help" class="sr-only">Usa las flechas para elegir un día y consultar su actividad.</p>
    <p class="heat-detail" data-heat-detail aria-live="polite"><strong><?= e($hoy->format('d/m/Y')) ?></strong><span><?php $hoyTotal = (int)($actividad[$hoy->format('Y-m-d')] ?? 0); ?><?= $hoyTotal ?> <?= $hoyTotal === 1 ? 'contribución' : 'contribuciones' ?></span></p>
    <label class="heat-date-picker">Consultar una fecha<input type="date" data-heat-date min="<?= e($inicio->format('Y-m-d')) ?>" max="<?= e($hoy->format('Y-m-d')) ?>" value="<?= e($hoy->format('Y-m-d')) ?>"></label>
    <div class="heat-legend"><span>Menos</span><i class="n0"></i><i class="n1"></i><i class="n2"></i><i class="n3"></i><i class="n4"></i><span>Más</span></div>
  </section>

  <?php if (recomendacionesDisponibles()): ?><section class="profile-endorsements"><span>VOCES DE LA ESCENA</span><h2>Recomendaciones musicales</h2><?php if ($vocesMusico): ?><div class="endorsement-list"><?php foreach (array_slice($vocesMusico, 0, 2) as $voice): ?><article class="endorsement-card"><blockquote><?= nl2br(e($voice['texto'])) ?></blockquote><a class="endorsement-author" href="<?= e(rutaAplicacion('musico.php?id=' . (int)$voice['autor_id'])) ?>"><img src="<?= e(musicmadPublicImageUrl($voice['avatar'])) ?>" width="40" height="40" alt="" loading="lazy"><strong><?= e($voice['autor']) ?></strong></a></article><?php endforeach; ?></div><?php else: ?><p>La música también se construye con lo que otros músicos comparten de ti.</p><?php endif; ?><a href="recomendaciones.php?usuario=<?= $id ?>"><?= $esYo ? 'Ver mis recomendaciones' : 'Recomendar y ver todas las voces' ?> ↗</a></section><?php endif; ?>
  <h2 class="seccion-titulo">Publicaciones</h2>
  <?php if (!$publicaciones): ?>
    <div class="card vacio"><p><?= $esYo ? 'Aún no has publicado nada. ¡Anímate desde la Comunidad!' : 'Este músico todavía no ha publicado nada.' ?></p>
      <?php if ($esYo): ?><a href="<?= e(rutaAplicacion('feed.php')) ?>" class="btn btn-accent">Ir a la comunidad</a><?php endif; ?></div>
  <?php endif; ?>
  <div class="feed-posts" data-feed-list data-feed-has-more="<?= $paginaPerfil['hay_mas'] ? '1' : '0' ?>" data-feed-cursor="<?= e($paginaPerfil['cursor']) ?>">
  <?php foreach ($publicaciones as $p): ?>
    <?php renderPublicacion($p, $comentarios[$p['id']] ?? [], $yo, $volver, $respuestas, $comentariosTiempo[$p['id']] ?? []); ?>
  <?php endforeach; ?>
  </div>
  <?php if ($paginaPerfil['hay_mas']): ?><div class="feed-loading" data-feed-sentinel><div class="feed-skeleton" data-feed-skeleton hidden aria-hidden="true"><span></span><span></span><span></span></div><button type="button" class="btn btn-ghost" data-feed-more>Ver más publicaciones</button><p data-feed-status role="status" aria-live="polite"></p></div><?php endif; ?>
</main>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
