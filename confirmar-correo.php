<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/email_account.php';
require_once __DIR__ . '/includes/email_link_context.php';
header('Referrer-Policy: no-referrer');
if (!in_array($_SERVER['REQUEST_METHOD'], ['GET','POST'], true)) {
    header('Allow: GET, POST');
    http_response_code(405);
    exit;
}
$pending = colaTrabajosDisponible() ? prepararContextoEnlaceCorreo('verificar', 'confirmar-correo.php') : null;
$done = false;
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    exigirCsrf();
    try {
        $done = $pending && consumirTokenCorreo($pending['hash'], 'verificar');
        $message = $done ? 'Tu correo quedó confirmado.' : 'El enlace ya no está disponible.';
        if ($done) {
            unset($_SESSION['email_pending_verificar']);
        }
    } catch (Throwable $error) {
        error_log('Email confirmation: ' . get_class($error));
        $message = 'No pudimos confirmar el correo. Reintenta.';
    }
}
$pageTitle = 'Confirmar correo';
require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/navbar.php';
?>
<main class="auth-wrap"><section class="card auth-card"><div class="auth-body"><h1>Confirma tu correo</h1><?php if ($message):?><p class="alert alert-<?= $done ? 'success' : 'error' ?>" role="status"><?= e($message) ?></p><?php endif;?><?php if ($pending && !$done):?><p>Confirma que recibiste este enlace en el correo de tu cuenta de MusicMad.</p><form method="POST" action="<?= e(rutaAplicacion('confirmar-correo.php', 'POST')) ?>"><?= csrfCampo() ?><button class="btn btn-primary btn-full" type="submit">Confirmar mi correo</button></form><?php elseif (!$done):?><p>El enlace expiró o ya se utilizó. Solicita otro desde configuración.</p><?php endif;?><p><a href="<?= estaAutenticado() ? 'configuracion.php' : 'login.php' ?>">Continuar en MusicMad</a></p></div></section></main>
<?php require __DIR__ . '/includes/footer.php'; ?>
