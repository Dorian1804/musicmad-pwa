<?php
ob_start();
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/social.php';
if (!in_array($_SERVER['REQUEST_METHOD'], ['GET','POST'], true)) {
    header('Allow: GET, POST');
    http_response_code(405);
    exit('Método no permitido.');
}
$message = '';
$success = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    exigirCsrf();
    try {
        $email = trim(textoCampo($_POST, 'email'));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) {
            throw new InvalidArgumentException('Revisa el correo electrónico.');
        }
        $success = cancelarEliminacionCuenta($email, textoCampo($_POST, 'REPLACE_WITH_LOCAL_VALUE'), textoCampo($_POST, 'codigo'));
        $message = 'Eliminación cancelada. Inicia sesión para volver a la comunidad.';
    } catch (DomainException|InvalidArgumentException|LogicException|OverflowException $error) {
        $message = $error->getMessage();
    } catch (Throwable $error) {
        error_log('Account restoration: ' . get_class($error));
        $message = 'La recuperación no está disponible por el momento.';
    }
}
$pageTitle = 'Recuperar tu cuenta';
$pageCss = ['CSS/social.css','CSS/account.css'];
require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/navbar.php';
?>
<main class="container account-recovery"><section class="card"><span class="account-data-step">TÚ DECIDES</span><h1>Recuperar tu cuenta</h1><?php if (textoCampo($_GET, 'solicitada') === '1'): ?><p class="alert alert-success" role="status">Eliminación solicitada. Tu cuenta está oculta y los accesos están cerrados.</p><?php endif; ?><p>Durante los siete días siguientes a tu solicitud puedes cancelar la eliminación. Los archivos se conservan durante ese plazo. La salida y los cambios de permisos de bandas se mantienen.</p><?php if ($message): ?><p class="alert alert-<?= $success ? 'success' : 'error' ?>" role="status"><?= e($message) ?></p><?php endif; ?><?php if (!$success): ?><form method="POST" action="<?= e(rutaAplicacion('reactivar-cuenta.php', 'POST')) ?>" class="account-confirm-form" data-native-navigation><?= csrfCampo() ?><label for="recuperar-email">Correo electrónico</label><input id="recuperar-email" type="email" name="email" autocomplete="email" maxlength="254" required><label for="recuperar-REPLACE_WITH_LOCAL_VALUE">Contraseña actual</label><input id="recuperar-REPLACE_WITH_LOCAL_VALUE" type="REPLACE_WITH_LOCAL_VALUE" name="REPLACE_WITH_LOCAL_VALUE" autocomplete="current-REPLACE_WITH_LOCAL_VALUE" maxlength="72" required><label for="recuperar-code">Código de seguridad, si activaste dos pasos</label><input id="recuperar-code" name="codigo" autocomplete="one-time-code" maxlength="24"><button type="submit" class="btn btn-primary">Cancelar eliminación</button></form><?php endif; ?><a class="account-back" href="<?= e(rutaAplicacion('login.php')) ?>">Volver a iniciar sesión →</a></section></main>
<?php require __DIR__ . '/includes/footer.php'; ?>
