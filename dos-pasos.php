<?php
require_once __DIR__ . '/includes/auth.php';
if (!in_array($_SERVER['REQUEST_METHOD'], ['GET','POST'], true)) {
    header('Allow: GET, POST');
    http_response_code(405);
    exit;
}
if (estaAutenticado()) {
    header('Location: feed.php');
    exit;
}
$challenge = $_SESSION['login_pendiente'] ?? null;
if (!is_array($challenge) || ($challenge['expira'] ?? 0) < time()) {
    unset($_SESSION['login_pendiente']);
    $_SESSION['flash'] = ['error','Tu acceso de cinco minutos expiró. Inicia sesión de nuevo.'];
    header('Location: login.php');
    exit;
}
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    exigirCsrf();
    $db = getDB();
    $db->beginTransaction();
    try {
        $st = $db->prepare('SELECT * FROM usuarios WHERE id=? AND activo=1 FOR UPDATE');
        $st->execute([$challenge['uid']]);
        $user = $st->fetch();
        if (!$user || !hash_equals($challenge['stamp'], hash('sha256', $user['REPLACE_WITH_LOCAL_VALUE'])) || !tieneSegundoFactor((int)$user['id'])) {
            unset($_SESSION['login_pendiente']);
            $db->rollBack();
            header('Location: login.php');
            exit;
        }
        if (!consumirSegundoFactor((int)$user['id'], textoCampo($_POST, 'codigo'))) {
            $db->commit();
            $error = 'El código no es válido, ya se utilizó o alcanzaste el límite de intentos. Espera y usa uno nuevo.';
        } else {
            completarAccesoUsuario($user, (bool)$challenge['recordar']);
            $db->commit();
            $destination = destinoAccesoMusicMad((int)sesion('usuario_id'), (string)($_SESSION['destino_post_login'] ?? 'feed.php'));
            if ($destination !== 'bienvenida.php' && $destination !== 'verificar-correo.php') {
                unset($_SESSION['destino_post_login']);
            }
            header('Location: ' . $destination);
            exit;
        }
    } catch (Throwable $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log('Second factor unavailable: ' . get_class($exception));
        $error = 'La verificación no está disponible por el momento. Reintenta.';
    }
}
$pageTitle = 'Verificación en dos pasos';
require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/navbar.php';
?>
<main class="auth-wrap"><section class="card auth-card"><div class="auth-body"><span class="community-kicker">PROTEGE TU MÚSICA</span><h1>Verificación en dos pasos</h1><p>Escribe el código de tu app autenticadora. También puedes usar un código de recuperación.</p><?php if ($error):?><p class="alert alert-error" role="alert"><?= e($error) ?></p><?php endif;?><form method="POST" action="<?= e(rutaAplicacion('dos-pasos.php', 'POST')) ?>"><?= csrfCampo() ?><label for="factor-code">Código de seguridad<input id="factor-code" name="codigo" autocomplete="one-time-code" inputmode="text" maxlength="24" required autofocus></label><button type="submit" class="btn btn-primary btn-full">Confirmar acceso</button></form><p><a href="<?= e(rutaAplicacion('login.php')) ?>">Volver al inicio de sesión</a></p></div></section></main>
<?php require __DIR__ . '/includes/footer.php'; ?>
