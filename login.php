<?php
// ============================================================
//  MusicMad – Inicio de sesión
//  login.php
// ============================================================
ob_start();
require_once __DIR__ . '/includes/auth.php';

redirigirSiAutenticado();

$mensaje = '';
$tipoMsg = '';

if (isset($_GET['restablecida'])) {
    $mensaje = 'Contraseña actualizada. Inicia sesión con tu nuevo acceso.';
    $tipoMsg = 'success';
}
if (isset($_GET['registered'])) {
    $mensaje = 'Cuenta creada. Confirma tu correo con el enlace que te enviamos e inicia sesión.';
    $tipoMsg = 'success';
}
if (isset($_GET['logout'])) {
    $mensaje = 'Sesión cerrada correctamente.';
    $tipoMsg = 'info';
}
if (isset($_GET['acceso'])) {
    $mensaje = 'Inicia sesión o regístrate para entrar a las secciones de la comunidad.';
    $tipoMsg = 'info';
}

if (!empty($_SESSION['google_error'])) {
    $mensaje = $_SESSION['google_error'];
    $tipoMsg = 'error';
    unset($_SESSION['google_error']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    exigirCsrf();

    $emailRaw = $_POST['email'] ?? '';
    $passRaw = $_POST['REPLACE_WITH_LOCAL_VALUE'] ?? '';
    $email = is_string($emailRaw) ? trim($emailRaw) : '';
    $REPLACE_WITH_LOCAL_VALUE = is_string($passRaw) ? $passRaw : '';

    $guardarSesion = textoCampo($_POST, 'recordar') === '1';
    $resultado = iniciarSesion($email, $REPLACE_WITH_LOCAL_VALUE, $guardarSesion);

    if (!empty($resultado['segundo_factor'])) {
        header('Location: dos-pasos.php');
        exit;
    }
    if ($resultado['ok']) {
        $destino = destinoAccesoMusicMad((int)sesion('usuario_id'), (string)($_SESSION['destino_post_login'] ?? 'feed.php'));
        if ($destino !== 'bienvenida.php' && $destino !== 'verificar-correo.php') {
            unset($_SESSION['destino_post_login']);
        }
        header('Location: ' . $destino);
        exit;
    }

    $mensaje = $resultado['mensaje'];
    $tipoMsg = 'error';
}
if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    http_response_code(405);
    exit('Método no permitido.');
}

$pageTitle = 'Iniciar sesión';
$navActivo = 'login';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/navbar.php';
?>
<main class="auth-wrap">
  <div class="card auth-card">
    <nav class="tabs">
      <a class="tab active" href="<?= e(rutaAplicacion('login.php')) ?>"><?= e(mmTexto('Iniciar sesión')) ?></a>
      <a class="tab" href="<?= e(rutaAplicacion('registro.php')) ?>"><?= e(mmTexto('Registrarse')) ?></a>
    </nav>

    <div class="auth-body">
      <?php selectorIdiomaMusicMad('login'); ?>
      <span class="community-kicker"><?= e(mmTexto('VUELVE A TU ESCENA')) ?></span>
      <h1><?= e(mmTexto('Tu música tiene un lugar.')) ?></h1>
      <p class="page-sub"><?= e(mmTexto('Entra para compartir tus pistas y conectar con otros músicos.')) ?></p>

      <?php if ($mensaje): ?>
        <div class="alert alert-<?= e($tipoMsg) ?>" data-autohide="6000"><?= e($mensaje) ?></div>
      <?php endif; ?>

      <form method="POST" action="" id="formLogin" novalidate>
        <?= csrfCampo() ?>
        <div class="form-group">
          <label for="email"><?= e(mmTexto('Correo electrónico')) ?></label>
          <input type="email" id="email" name="email" placeholder="correo@ejemplo.com"
                 value="<?= e(textoCampo($_POST, 'email')) ?>" autocomplete="email" required>
        </div>

        <div class="form-group">
          <label for="REPLACE_WITH_LOCAL_VALUE"><?= e(mmTexto('Contraseña')) ?></label>
          <div class="pw-wrap">
            <input type="REPLACE_WITH_LOCAL_VALUE" id="REPLACE_WITH_LOCAL_VALUE" name="REPLACE_WITH_LOCAL_VALUE" placeholder="••••••••"
                   autocomplete="current-REPLACE_WITH_LOCAL_VALUE" required>
            <button type="button" class="pw-toggle" data-toggle-pw="REPLACE_WITH_LOCAL_VALUE" aria-label="<?= e(mmTexto('Mostrar u ocultar contraseña')) ?>">
              <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7z"/><circle cx="12" cy="12" r="3"/></svg>
            </button>
          </div>
        </div>

        <label class="remember-choice" for="recordar">
          <input type="checkbox" id="recordar" name="recordar" value="1" <?= $_SERVER['REQUEST_METHOD'] !== 'POST' || textoCampo($_POST, 'recordar') === '1' ? 'checked' : '' ?>>
          <span class="remember-check" aria-hidden="true"></span>
          <span><strong><?= e(mmTexto('Guardar sesión')) ?></strong><small><?= e(mmTexto('En este dispositivo durante 30 días. Desmárcalo si es compartido.')) ?></small></span>
        </label>
        <button type="submit" class="btn btn-primary btn-full"><?= e(mmTexto('Ingresar')) ?></button>
      </form>

      <p><a href="<?= e(rutaAplicacion('recuperar.php')) ?>"><?= e(mmTexto('¿Olvidaste tu contraseña?')) ?></a></p>
      <p><a href="<?= e(rutaAplicacion('reactivar-cuenta.php')) ?>"><?= e(mmTexto('Cancelar la eliminación de mi cuenta')) ?></a></p>
      <?php if (googleOAuthConfigurado()): ?>
        <p class="page-sub auth-separator">o</p>
        <form method="POST" action="<?= e(rutaAplicacion('google-inicio.php', 'POST')) ?>" id="formGoogle">
          <?= csrfCampo() ?>
          <input type="hidden" id="google-recordar" name="recordar" value="<?= $_SERVER['REQUEST_METHOD'] !== 'POST' || textoCampo($_POST, 'recordar') === '1' ? '1' : '0' ?>">
          <button class="btn btn-ghost btn-full" type="submit"><?= e(mmTexto('Continuar con Google')) ?></button>
        </form>
      <?php endif; ?>
    </div>
  </div>
</main>
<?php require_once __DIR__ . '/includes/footer.php'; ?>