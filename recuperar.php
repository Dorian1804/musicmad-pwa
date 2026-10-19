<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/email_account.php';
if (!in_array($_SERVER['REQUEST_METHOD'], ['GET','POST'], true)) {
    header('Allow: GET, POST');
    http_response_code(405);
    exit;
}
$message = '';
$available = false;
try {
    $available = correoConfigurado();
    if ($available) {
        direccionCanonicaCorreo();
    }
} catch (Throwable $error) {
    $available = false;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    exigirCsrf();
    $started = hrtime(true);
    $fingerprints = huellasLimite('recover-request:' . (string)($_SERVER['REMOTE_ADDR'] ?? ''));
    if (loginBloqueado($fingerprints)) {
        $message = 'Espera 15 minutos antes de volver a solicitar un correo.';
        http_response_code(429);
    } else {
        if (!registrarIntentoLogin($fingerprints, false)) {
            http_response_code(503);
            exit('El acceso no está disponible por el momento.');
        }
        $email = trim(textoCampo($_POST, 'email'));
        if ($available && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            try {
                solicitarCorreoCuenta($email, 'recuperar');
            } catch (Throwable $error) {
                error_log('Recovery request: ' . get_class($error));
            }
        }
        $message = $available ? 'Si corresponde a una cuenta activa, recibirás un enlace para restablecer tu contraseña.' : 'El envío de correo no está disponible todavía en este sitio.';
    }
    $remaining = 150000000 - (hrtime(true) - $started);
    if ($remaining > 0) {
        usleep((int)ceil($remaining / 1000));
    }
}
$pageTitle = 'Recuperar contraseña';
require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/navbar.php';
?>
<main class="auth-wrap"><section class="card auth-card"><div class="auth-body"><span class="community-kicker">VUELVE A TU MÚSICA</span><h1>Recupera tu contraseña</h1><p>Te enviaremos un enlace de un solo uso al correo de tu cuenta.</p><?php if ($message):?><p class="alert alert-info" role="status"><?= e($message) ?></p><?php endif;?><form method="POST" action="<?= e(rutaAplicacion('recuperar.php', 'POST')) ?>"><?= csrfCampo() ?><label>Correo de tu cuenta<input name="email" type="email" autocomplete="email" maxlength="254" required></label><button class="btn btn-primary btn-full" type="submit" <?= $available ? '' : 'disabled' ?>>Solicitar enlace</button></form><?php if (!$available):?><p>El envío de correo todavía no está disponible. Contacta al equipo de MusicMad.</p><?php endif;?><p><a href="<?= e(rutaAplicacion('login.php')) ?>">Volver al inicio de sesión</a></p></div></section></main>
<?php require __DIR__ . '/includes/footer.php'; ?>
