<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/social.php';
require_once __DIR__ . '/includes/video_recovery.php';
requerirSesion();
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    exit;
}
$pub = idCampo($_GET, 'id');
$video = null;
$notice = '';
try {
    $video = videoPropioParaRestaurar((int)sesion('usuario_id'), $pub);
} catch (OutOfBoundsException $error) {
    http_response_code(404);
    $notice = $error->getMessage();
} catch (DomainException $error) {
    $notice = $error->getMessage();
}
$pageTitle = 'Restaurar mi video';
$pageCss = ['CSS/social.css'];
$pageJs = ['js/upload-progress.js'];
require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/navbar.php';
?>
<main class="container"><section class="card auth-card"><div class="auth-body"><h1>Restaurar mi video</h1><?php if (!$video): ?><p role="status"><?= e($notice) ?></p><?php else: ?><h2><?= e($video['titulo']) ?></h2><p>Adjunta el archivo original. Se conservarán esta publicación, sus likes y comentarios. El archivo nuevo tendrá una copia privada de respaldo antes de confirmar la restauración.</p><form method="POST" action="<?= e(rutaAplicacion('social-accion.php', 'POST')) ?>" enctype="multipart/form-data" data-upload-form data-chunk-upload><?= csrfCampo() ?><input type="hidden" name="restaurar_publicacion" value="<?= $pub ?>"><label for="restore-video-file">Video original (MP4 o WebM, hasta 256 MB)</label><input id="restore-video-file" type="file" name="video" accept="video/mp4,video/webm,.mp4,.webm" required><p>Elige el mismo formato que tenía el original<?= $video['formato'] ? ' (' . e(strtoupper($video['formato'])) . ')' : '' ?>.</p><button type="submit" class="btn btn-primary">Restaurar video</button><div class="upload-controls"><button type="button" class="btn btn-ghost" data-upload-pause hidden>Pausar</button><button type="button" class="btn btn-ghost" data-upload-resume hidden>Reanudar</button><button type="button" class="btn btn-ghost" data-upload-cancel hidden>Cancelar subida</button></div></form><noscript><p>Activa JavaScript para restaurar mediante la subida reanudable. Tu archivo original seguirá en el dispositivo.</p></noscript><?php endif; ?><p><a href="publicacion.php?id=<?= $pub ?>">Volver a la publicación</a></p></div></section></main>
<?php require __DIR__ . '/includes/footer.php'; ?>
