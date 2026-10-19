<?php
require_once __DIR__ . '/includes/auth.php';
requerirSesion();
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    exit;
}
$pageTitle = 'Acciones pendientes';
$pageCss = ['CSS/social.css'];
$pageJs = ['js/pending-review.js'];
require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/navbar.php';
?>
<main class="container narrow" data-pending-review>
 <header><span class="community-kicker">TU CONEXIÓN</span><h1>Acciones pendientes</h1><p>Aplausos, guardados y comentarios que están esperando a enviarse.</p></header>
 <div class="card pending-summary"><p role="status" data-pending-status>Consultando este navegador…</p><button type="button" class="btn btn-primary" data-review-sync>Sincronizar ahora</button></div>
 <ul class="pending-list" data-pending-list></ul>
 <p class="settings-hint">Solo se envían desde tu cuenta. Caducan tras siete días; cerrar sesión las elimina del dispositivo. Puedes descartar una acción antes de que se envíe.</p>
</main>
<?php require __DIR__ . '/includes/footer.php'; ?>
