<?php

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/social.php';
if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'HEAD'], true)) {
    header('Allow: GET, HEAD');
    http_response_code(405);
    exit;
}
exigirLimiteLecturaPortafolio();
$kind = textoCampo($_GET, 'tipo');
$id = idCampo($_GET, 'id');
$resource = recursoCompartiblePublico($kind, $id);
header('Cache-Control: no-store');
if (!$resource) {
    http_response_code(404);
    $pageTitle = 'Contenido no disponible';
} else {
    $pageTitle = $resource['titulo'];
    $publicUrl = enlaceCompartiblePublico($kind, $id);
    if ($publicUrl) {
        $pageCanonical = $publicUrl;
        $pageOpenGraph = ['og:type' => 'website','og:title' => $resource['titulo'] . ' · MusicMad',
            'og:description' => mb_substr($resource['descripcion'], 0, 180),'og:url' => $publicUrl];
    }
}
$pageCss = ['CSS/social.css', 'CSS/account.css', 'CSS/public-share.css'];
$pageJs = ['assets/vendor/qrcode/qrcodegen-1.8.0.js', 'js/qr.js'];
require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/navbar.php';
?>
<main class="container public-preview">
  <?php if (!$resource): ?>
    <section class="card"><h1>Contenido no disponible</h1><p>Este enlace no está disponible para visitas públicas.</p><a class="btn btn-primary" href="<?= e(rutaAplicacion('index.php')) ?>">Volver a MusicMad</a></section>
  <?php else: ?>
    <section class="card public-preview-card">
      <span class="community-kicker">MUSICMAD / <?= e(mb_strtoupper($kind)) ?></span>
      <h1><?= e($resource['titulo']) ?></h1>
      <?php if ($resource['ciudad']): ?><p><?= e($resource['ciudad']) ?></p><?php endif; ?>
      <?php if (!empty($resource['fecha'])): ?><p><?= e($resource['fecha']) ?></p><?php endif; ?>
      <p><?= nl2br(e(mb_substr($resource['descripcion'], 0, 1500))) ?></p>
      <p>Abre MusicMad para participar. Los mensajes, integrantes y archivos privados se quedan dentro de la comunidad.</p>
      <a class="btn btn-primary" href="<?= e($resource['ruta']) ?>">Abrir <?= e($kind) ?> en MusicMad →</a>
      <?php renderCompartirPublico($kind, $id); ?>
    </section>
  <?php endif; ?>
</main>
<?php require __DIR__ . '/includes/footer.php'; ?>
