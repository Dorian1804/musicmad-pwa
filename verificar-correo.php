<?php

require_once __DIR__ . '/includes/auth.php';
requerirSesion();
header('Referrer-Policy: no-referrer');
if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    http_response_code(405);
    exit;
}
$uid = (int) sesion('usuario_id');
if (verificacionObligatoriaDisponible() && correoCuentaVerificado($uid)) {
    $destination = destinoAccesoMusicMad($uid, (string)($_SESSION['destino_post_login'] ?? 'feed.php'));
    if (onboardingCompleto($uid)) {
        unset($_SESSION['destino_post_login']);
    }
    header('Location: ' . $destination);
    exit;
}
$message = '';
$kind = 'info';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    exigirCsrf();
    try {
        if (!verificacionObligatoriaDisponible()) {
            throw new RuntimeException('Verification schema missing.');
        }
        iniciarPlazoVerificacion($uid);
        $st = getDB()->prepare('SELECT email FROM usuarios WHERE id=? AND activo=1');
        $st->execute([$uid]);
        $queued = solicitarCorreoCuenta((string) $st->fetchColumn(), 'verificar');
        $message = $queued ? 'Enlace solicitado. Revisa tu bandeja y la carpeta de spam.'
            : 'Ya solicitaste varios enlaces. Espera 15 minutos antes de pedir otro.';
    } catch (Throwable $error) {
        error_log('Required email delivery unavailable: ' . get_class($error));
        $kind = 'error';
        $message = 'El envío no está disponible por ahora. Reintenta más tarde o contacta a MusicMad.';
    }
}
$st = getDB()->prepare('SELECT email FROM usuarios WHERE id=?');
$st->execute([$uid]);
$email = (string) $st->fetchColumn();
$deadline = '';
if (verificacionObligatoriaDisponible()) {
    $st = getDB()->prepare('SELECT vence_en FROM musicmad_email_enrollment WHERE usuario_id=?');
    $st->execute([$uid]);
    $deadline = (string) $st->fetchColumn();
}
$pageTitle = 'Confirma tu correo';
$pageCss = ['CSS/email-verification.css'];
require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/navbar.php';
?>
<main class="auth-wrap email-check">
  <section class="card auth-card" aria-labelledby="email-title">
    <div class="auth-body">
      <p class="email-check-kicker">ANTES DE ENTRAR AL ESCENARIO</p>
      <h1 id="email-title">Confirma tu correo</h1>
      <p>Usa el enlace que enviamos a <strong class="email-check-address"><?= e($email) ?></strong>.</p>
      <p>Puedes explorar la comunidad. Para publicar, comentar, seguir, escribir y subir archivos debes confirmar tu correo.</p>
      <?php if ($deadline): ?><p>Confírmalo antes del <time><?= e($deadline) ?> UTC</time>. Las cuentas pendientes se eliminan después de siete días.</p><?php endif; ?>
      <?php if ($message): ?><p class="alert alert-<?= e($kind) ?>" role="status"><?= e($message) ?></p><?php endif; ?>
      <form method="post" action="<?= e(rutaAplicacion('verificar-correo.php', 'POST')) ?>">
        <?= csrfCampo() ?>
        <button class="btn btn-primary btn-full" type="submit">Enviar otro enlace</button>
      </form>
      <p>El enlace dura 24 horas y sirve una sola vez. Abre el más reciente y pulsa «Confirmar mi correo».</p>
      <nav class="email-check-links" aria-label="Opciones de verificación">
        <a href="<?= e(rutaAplicacion('feed.php')) ?>">Explorar la comunidad</a>
        <a href="<?= e(rutaAplicacion('configuracion.php')) ?>">Configuración de cuenta</a>
      </nav>
    </div>
  </section>
</main>
<?php require __DIR__ . '/includes/footer.php'; ?>
