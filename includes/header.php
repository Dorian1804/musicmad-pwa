<?php
// ============================================================
//  MusicMad – Cabecera HTML común (+ etiquetas PWA)
//  includes/header.php
//
//  Antes de incluirlo define (opcional):
//    $pageTitle  → título de la pestaña
//    $pageCss    → array de hojas de estilo, ej. ['CSS/social.css']
//  Este archivo ABRE <head> y lo CIERRA; el <body> lo abre includes/navbar.php.
// ============================================================
header('Content-Language: ' . idiomaMusicMad());
$pageTitle = $pageTitle ?? 'MusicMad';
$pageCss = $pageCss ?? [];
$assetVersion = '107';
if (in_array(basename((string) ($_SERVER['SCRIPT_NAME'] ?? '')), ['perfil.php','musico.php','banda.php','evento.php','sala.php','portafolio.php','epk.php','vista-publica.php'], true)) {
    $pageCss[] = 'CSS/public-share.css';
    $pageCss = array_values(array_unique($pageCss));
    $pageJs = array_values(array_unique(array_merge($pageJs ?? [], ['assets/vendor/qrcode/qrcodegen-1.8.0.js','js/qr.js'])));
}
require_once __DIR__ . '/action_receipts.php';
if (estaAutenticado() && empty($_SESSION['offline_scope'])) {
    $_SESSION['offline_scope'] = bin2hex(random_bytes(32));
}
$offlineScope = estaAutenticado() ? $_SESSION['offline_scope'] : '';
?>
<!DOCTYPE html>
<html lang="<?= e(idiomaMusicMad()) ?>">
<head>
    <meta charset="UTF-8">
<?php if (defined('MUSICMAD_ROUTED') && MUSICMAD_ROUTED): ?>
    <base href="<?= e(rtrim(APP_URL, '/') . '/') ?>">
<?php endif; ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="musicmad-version" content="<?= $assetVersion ?>">
    <meta name="musicmad-account" content="<?= e($offlineScope) ?>">
    <meta name="musicmad-user" content="<?= (int)sesion('usuario_id') ?>">
    <meta name="musicmad-sync" content="<?= estaAutenticado() && sincronizacionDisponible() ? '1' : '0' ?>">
    <meta name="musicmad-csrf" content="<?= e(csrfToken()) ?>">
    <meta name="description" content="MusicMad – La comunidad para compartir música, encontrar músicos y crear bandas.">
    <title><?= e(tituloAplicacionIdioma($pageTitle, (string)($_SERVER['SCRIPT_NAME'] ?? ''))) ?> | MusicMad</title>
<?php foreach (($pageOpenGraph ?? []) as $property => $content): if (!in_array($property, ['og:type','og:title','og:description','og:url','og:image'], true)) {
    continue;
} ?>
    <meta property="<?= e($property) ?>" content="<?= e($content) ?>">
<?php endforeach; ?>
<?php if (!empty($pageCanonical)): ?><link rel="canonical" href="<?= e($pageCanonical) ?>"><?php endif; ?>

    <!-- Progressive Web App -->
    <link rel="manifest" href="<?= e(rutaAplicacion('manifesto.php?lang=' . idiomaMusicMad())) ?>">
    <meta name="theme-color" content="#190d22">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="MusicMad">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <link rel="apple-touch-icon" href="img/icon-192.png">
    <link rel="icon" type="image/png" sizes="192x192" href="img/icon-192.png">

    <link rel="preload" href="assets/fonts/manrope-latin.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="CSS/core.css?v=<?= $assetVersion ?>" data-core-css>
<?php foreach ($pageCss as $css): ?>
    <link rel="stylesheet" href="<?= e($css) ?>?v=<?= $assetVersion ?>" data-pjax-css>
<?php endforeach; ?>
<?php if (!empty($pageProfileIds)): $styleIds = implode(',', array_slice(array_unique(array_map('intval', $pageProfileIds)), 0, 100)); ?>
    <link rel="stylesheet" href="perfil-estilos.php?ids=<?= e($styleIds) ?>&amp;stamp=<?= bin2hex(random_bytes(6)) ?>" data-pjax-css data-profile-theme>
<?php endif; ?>
    <link rel="stylesheet" href="CSS/shell.css?v=<?= $assetVersion ?>" data-core-css data-mobile-css>
    <script src="assets/i18n/<?= e(idiomaMusicMad()) ?>.js?v=<?= $assetVersion ?>" defer></script>
    <script src="js/i18n.js?v=<?= $assetVersion ?>" defer></script>
    <script src="js/session-state.js?v=<?= $assetVersion ?>" defer></script>
    <script src="js/route-catalogue.js?v=<?= $assetVersion ?>" defer></script>
    <script src="js/mobile-ui.js?v=<?= $assetVersion ?>" defer></script>
    <script src="js/sheets.js?v=<?= $assetVersion ?>" defer></script>
    <script src="js/pending-store.js?v=<?= $assetVersion ?>" defer></script>
    <script src="js/pending-actions.js?v=<?= $assetVersion ?>" defer></script>
    <script src="js/reaction-menu.js?v=<?= $assetVersion ?>" defer></script>
    <script src="js/reaction-burst.js?v=<?= $assetVersion ?>" defer></script>
    <script src="js/app-badge.js?v=<?= $assetVersion ?>" defer></script>
    <script src="js/waveform-math.js?v=<?= $assetVersion ?>" defer></script>
    <script src="js/waveform.js?v=<?= $assetVersion ?>" defer></script>
    <script src="js/comments.js?v=<?= $assetVersion ?>" defer></script>
<?php foreach (($pageJs ?? []) as $js): ?>
    <script src="<?= e($js) ?>?v=<?= $assetVersion ?>" defer></script>
<?php endforeach; ?>
    <script src="js/app.js?v=<?= $assetVersion ?>" defer></script>
    <script src="js/listening-math.js?v=<?= $assetVersion ?>" defer></script>
    <script src="js/listening-statistics.js?v=<?= $assetVersion ?>" defer></script>
    <script src="js/playback-errors.js?v=<?= $assetVersion ?>" defer></script>
    <script src="js/player.js?v=<?= $assetVersion ?>" defer></script>
    <script src="js/navigation.js?v=<?= $assetVersion ?>" defer></script>
    <script src="js/refresh-math.js?v=<?= $assetVersion ?>" defer></script>
    <script src="js/refresh.js?v=<?= $assetVersion ?>" defer></script>
    <script src="js/pwa.js?v=<?= $assetVersion ?>" defer></script>
</head>
