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
$reportes = listarReportesInstrumentos();
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$pageTitle = 'Reportes del marketplace';
$pageCss = ['CSS/social.css'];
$navActivo = 'mercado-reportes';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/navbar.php';
?>
<main class="container market-admin-page">
  <?php if ($flash): ?><div class="alert alert-<?= e($flash[0]) ?>" data-autohide="6000"><?= e($flash[1]) ?></div><?php endif; ?>
  <header class="market-admin-heading"><span class="community-kicker">Moderación</span><h1>Reportes de instrumentos</h1><p>Revisa los reportes de la comunidad. Retirar oculta el anuncio; descartar deja el artículo disponible.</p></header>
  <?php if (!$reportes): ?><div class="card vacio">No hay reportes pendientes. Todo tranquilo por ahora.</div><?php endif; ?>
  <?php foreach ($reportes as $r): $foto = is_string($r['foto']) && preg_match('/^[a-f0-9]{24}\.jpg$/', $r['foto']) ? 'uploads/market/' . $r['foto'] : ''; ?>
    <article class="market-report-card card">
      <?php if ($foto): ?><img src="<?= e(musicmadPublicImageUrl($foto)) ?>" alt="" loading="lazy"><?php else: ?><div class="market-report-thumb">♬</div><?php endif; ?>
      <div class="market-report-content"><span class="market-report-date">Reporte recibido · <?= e(hace($r['created_at'])) ?></span><h2><?= e($r['titulo']) ?></h2>
        <p><strong>Motivo:</strong> <?= e($r['motivo']) ?></p><?php if ($r['detalle'] !== ''): ?><p><?= nl2br(e($r['detalle'])) ?></p><?php endif; ?>
        <p class="market-report-people">Reportó <?= e($r['reportador_nombre'] . ' ' . $r['reportador_apellido']) ?> · Publicó <?= e($r['vendedor_nombre'] . ' ' . $r['vendedor_apellido']) ?></p>
        <div class="market-report-actions"><form method="POST" action="<?= e(rutaAplicacion('social-accion.php', 'POST')) ?>"><?= csrfCampo() ?><input type="hidden" name="accion" value="instrumento_reporte_resolver"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="decision" value="retirar"><input type="hidden" name="volver" value="mercado-reportes.php"><button type="submit" class="btn btn-primary">Retirar anuncio</button></form>
          <form method="POST" action="<?= e(rutaAplicacion('social-accion.php', 'POST')) ?>"><?= csrfCampo() ?><input type="hidden" name="accion" value="instrumento_reporte_resolver"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="decision" value="descartar"><input type="hidden" name="volver" value="mercado-reportes.php"><button type="submit" class="btn btn-ghost">Descartar reporte</button></form>
          <a href="<?= e(rutaAplicacion('mercado.php')) ?>" class="btn btn-ghost">Ver marketplace</a>
        </div>
      </div>
    </article>
  <?php endforeach; ?>
</main>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
