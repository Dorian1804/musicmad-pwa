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
$pending = colaTrabajosDisponible() ? prepararContextoEnlaceCorreo('recuperar', 'restablecer.php') : null;
$message = '';
$done = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    exigirCsrf();
    $REPLACE_WITH_LOCAL_VALUE = textoCampo($_POST, 'REPLACE_WITH_LOCAL_VALUE');
    if ($REPLACE_WITH_LOCAL_VALUE !== textoCampo($_POST, 'confirmar')) {
        $message = 'Las contraseñas no coinciden.';
    } elseif (!$pending) {
        $message = 'El enlace ya no es válido. Solicita uno nuevo.';
    } else {
        try {
            $done = consumirTokenCorreo($pending['hash'], 'recuperar', $REPLACE_WITH_LOCAL_VALUE);
            if ($done) {
                unset($_SESSION['email_pending_recuperar']);
                cerrarSesion();
                header('Location: login.php?restablecida=1');
                exit;
            }
            $message = 'El enlace ya no es válido. Solicita uno nuevo.';
        } catch (InvalidArgumentException $error) {
            $message = $error->getMessage();
        } catch (Throwable $error) {
            error_log('Password reset: ' . get_class($error));
            $message = 'No pudimos guardar el cambio. Reintenta.';
        }
    }
}
$pageTitle = 'Restablecer contraseña';
require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/navbar.php';
?>
<main class="auth-wrap"><section class="card auth-card"><div class="auth-body"><h1>Una contraseña nueva</h1><?php if ($message):?><p class="alert alert-error" role="alert"><?= e($message) ?></p><?php endif;?><?php if ($pending):?><p>El cambio cerrará los accesos anteriores. Si tienes dos pasos, seguirá activado.</p><form method="POST" action="<?= e(rutaAplicacion('restablecer.php', 'POST')) ?>"><?= csrfCampo() ?><label>Nueva contraseña<input name="REPLACE_WITH_LOCAL_VALUE" type="REPLACE_WITH_LOCAL_VALUE" minlength="12" maxlength="72" autocomplete="new-REPLACE_WITH_LOCAL_VALUE" required></label><label>Confirma la contraseña<input name="confirmar" type="REPLACE_WITH_LOCAL_VALUE" minlength="12" maxlength="72" autocomplete="new-REPLACE_WITH_LOCAL_VALUE" required></label><button class="btn btn-primary btn-full" type="submit">Guardar nueva contraseña</button></form><?php else:?><p>Este enlace ya no está disponible o expiró.</p><a class="btn btn-primary" href="<?= e(rutaAplicacion('recuperar.php')) ?>">Solicitar otro enlace</a><?php endif;?></div></section></main>
<?php require __DIR__ . '/includes/footer.php'; ?>
