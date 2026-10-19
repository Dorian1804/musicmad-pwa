<?php
ob_start();
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/social.php';
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    exit('Método no permitido.');
}
requerirModeracion();
$activa = social3Disponible();
$reportes = $activa ? listarReportesSocial3() : [];
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$pageTitle = 'Moderación';
$pageCss = ['CSS/social.css'];
$navActivo = 'moderacion';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/navbar.php';
?>
<main class="container moderation-page">
  <?php if ($flash): ?><div class="alert alert-<?= e($flash[0]) ?>" role="status"><?= e($flash[1]) ?></div><?php endif; ?>
  <header class="moderation-heading"><span class="community-kicker">CUIDADO DE LA COMUNIDAD</span><h1>Moderación</h1><p>Revisa los reportes de perfiles y publicaciones. Retirar una publicación también borra su archivo adjunto.</p><?php if (esAdmin()): ?><a class="btn btn-ghost" href="<?= e(rutaAplicacion('permisos.php')) ?>">Equipo y permisos →</a><?php endif; ?><a class="btn btn-ghost" href="<?= e(rutaAplicacion('mercado-reportes.php')) ?>">Reportes del marketplace ↗</a></header>
  <?php if (!$activa): ?>
    <section class="card vacio"><h2>Falta activar los reportes sociales</h2><p>Importa <code>config/social3.sql</code> en la base MusicMad para crear la bandeja de moderación.</p></section>
  <?php elseif (!$reportes): ?>
    <section class="card vacio"><h2>No hay reportes pendientes</h2><p>La bandeja está al día.</p></section>
  <?php else: ?>
    <div class="moderation-list">
      <?php foreach ($reportes as $r): ?>
        <article class="card moderation-card">
          <div class="moderation-card-top"><span class="moderation-kind"><?= $r['publicacion_id'] ? 'Publicación' : 'Perfil' ?></span><time datetime="<?= e($r['created_at']) ?>"><?= e(hace($r['created_at'])) ?></time></div>
          <h2><?= e($r['titulo'] ?: 'Reporte de perfil') ?></h2>
          <?php if ($r['texto']): ?><p><?= nl2br(e($r['texto'])) ?></p><?php endif; ?>
          <dl class="moderation-details"><div><dt>Motivo</dt><dd><?= e($r['motivo']) ?></dd></div><div><dt>Reportó</dt><dd><?= e($r['reportante']) ?></dd></div><div><dt>Cuenta señalada</dt><dd><?= e($r['autor'] ?: 'Cuenta no disponible') ?></dd></div></dl>
          <?php if ($r['detalle'] !== ''): ?><blockquote><?= nl2br(e($r['detalle'])) ?></blockquote><?php endif; ?>
          <div class="moderation-actions">
            <?php if ((int) $r['publicacion_id'] > 0): ?><form method="POST" action="<?= e(rutaAplicacion('social-accion.php', 'POST')) ?>" data-confirm="¿Retirar la publicación y eliminar sus archivos adjuntos?"><?= csrfCampo() ?><input type="hidden" name="accion" value="social_reporte_resolver"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><input type="hidden" name="decision" value="retirar"><input type="hidden" name="volver" value="moderacion.php"><button type="submit" class="btn btn-primary">Retirar publicación</button></form><?php endif; ?>
            <form method="POST" action="<?= e(rutaAplicacion('social-accion.php', 'POST')) ?>"><?= csrfCampo() ?><input type="hidden" name="accion" value="social_reporte_resolver"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><input type="hidden" name="decision" value="revisar"><input type="hidden" name="volver" value="moderacion.php"><button type="submit" class="btn btn-ghost">Marcar revisado</button></form>
            <form method="POST" action="<?= e(rutaAplicacion('social-accion.php', 'POST')) ?>"><?= csrfCampo() ?><input type="hidden" name="accion" value="social_reporte_resolver"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><input type="hidden" name="decision" value="descartar"><input type="hidden" name="volver" value="moderacion.php"><button type="submit" class="btn btn-ghost">Descartar</button></form>
            <?php if ((int) $r['publicacion_id'] > 0): ?><a class="btn btn-ghost" href="feed.php#pub-<?= (int) $r['publicacion_id'] ?>">Abrir publicación</a><?php elseif ((int) $r['usuario_reportado_id'] > 0): ?><a class="btn btn-ghost" href="<?= e(rutaAplicacion('musico.php?id=' . (int) $r['usuario_reportado_id'])) ?>">Abrir perfil</a><?php endif; ?>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</main>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
