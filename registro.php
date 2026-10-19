<?php

// ============================================================
//  MusicMad – Registro de usuarios
//  registro.php
// ============================================================
ob_start(); // <-- TRUCO CLAVE: Guarda el HTML en memoria y evita errores de cabeceras en InfinityFree
require_once __DIR__ . '/includes/auth.php';

redirigirSiAutenticado();

$mensaje = '';
$tipoMsg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    exigirCsrf();
    try {
        $huellaRegistro = huellaIntentoRegistro();
    } catch (RuntimeException $e) {
        $huellaRegistro = [];
        $mensaje = $e->getMessage();
    }
    if (!$huellaRegistro || loginBloqueado($huellaRegistro)) {
        $mensaje = $mensaje ?: 'Se alcanzó el límite de registros desde esta conexión. Espera 15 minutos y vuelve a intentarlo.';
        $tipoMsg = 'error';
    } elseif (!registrarIntentoLogin($huellaRegistro, false)) {
        $mensaje = 'El control de acceso no está disponible. Inténtalo más tarde.';
        $tipoMsg = 'error';
    } else {
        $datos = [
            'nombre' => textoCampo($_POST, 'nombre'),
            'apellido' => textoCampo($_POST, 'apellido'),
            'email' => trim(textoCampo($_POST, 'email')),
            'REPLACE_WITH_LOCAL_VALUE' => textoCampo($_POST, 'REPLACE_WITH_LOCAL_VALUE'),
            'confirm' => textoCampo($_POST, 'confirm'),
        ];

        $resultado = registrarUsuario($datos);
        $mensaje = $resultado['mensaje'];
        $tipoMsg = $resultado['ok'] ? 'success' : 'error';

        if ($resultado['ok']) {
            header('Location: login.php?registered=1');
            exit;
        }
    }
}
if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    http_response_code(405);
    exit('Método no permitido.');
}

$old = [
    'nombre' => e(textoCampo($_POST, 'nombre')),
    'apellido' => e(textoCampo($_POST, 'apellido')),
    'email' => e(textoCampo($_POST, 'email')),
];

$pageTitle = 'Crear cuenta';
$navActivo = 'login';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/navbar.php';
?>
<main class="auth-wrap">
  <div class="card auth-card">
    <nav class="tabs">
      <a class="tab" href="<?= e(rutaAplicacion('login.php')) ?>"><?= e(mmTexto('Iniciar sesión')) ?></a>
      <a class="tab active" href="<?= e(rutaAplicacion('registro.php')) ?>"><?= e(mmTexto('Registrarse')) ?></a>
    </nav>

    <div class="auth-body">
      <?php selectorIdiomaMusicMad('registro'); ?>
      <h1><?= e(mmTexto('Crea tu cuenta')) ?></h1>
      <p class="page-sub"><?= e(mmTexto('Completa el formulario para registrarte en MusicMad.')) ?></p>

      <?php if ($mensaje): ?>
        <div class="alert alert-<?= e($tipoMsg) ?>" data-autohide="8000"><?= e($mensaje) ?></div>
      <?php endif; ?>

      <form method="POST" action="" id="formRegistro" novalidate>
        <?= csrfCampo() ?>

        <div class="grid-2">
          <div class="form-group">
            <label for="nombre"><?= e(mmTexto('Nombre')) ?></label>
            <input type="text" id="nombre" name="nombre" placeholder="Juan"
                   value="<?= $old['nombre'] ?>" autocomplete="given-name" required>
          </div>
          <div class="form-group">
            <label for="apellido"><?= e(mmTexto('Apellido')) ?></label>
            <input type="text" id="apellido" name="apellido" placeholder="Pérez"
                   value="<?= $old['apellido'] ?>" autocomplete="family-name" required>
          </div>
        </div>

        <div class="form-group">
          <label for="email"><?= e(mmTexto('Correo electrónico')) ?></label>
          <input type="email" id="email" name="email" placeholder="correo@ejemplo.com"
                 value="<?= $old['email'] ?>" autocomplete="email" required>
        </div>

        <div class="grid-2">
          <div class="form-group">
            <label for="REPLACE_WITH_LOCAL_VALUE"><?= e(mmTexto('Contraseña')) ?></label>
            <div class="pw-wrap">
              <input type="REPLACE_WITH_LOCAL_VALUE" id="REPLACE_WITH_LOCAL_VALUE" name="REPLACE_WITH_LOCAL_VALUE" placeholder="<?= e(mmTexto('Mín. 12 caracteres')) ?>"
                     autocomplete="new-REPLACE_WITH_LOCAL_VALUE" minlength="12" required>
              <button type="button" class="pw-toggle" data-toggle-pw="REPLACE_WITH_LOCAL_VALUE" aria-label="<?= e(mmTexto('Mostrar u ocultar contraseña')) ?>">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7z"/><circle cx="12" cy="12" r="3"/></svg>
              </button>
            </div>
          </div>
          <div class="form-group">
            <label for="confirm"><?= e(mmTexto('Confirmar contraseña')) ?></label>
            <div class="pw-wrap">
              <input type="REPLACE_WITH_LOCAL_VALUE" id="confirm" name="confirm" placeholder="<?= e(mmTexto('Repite tu contraseña')) ?>"
                     autocomplete="new-REPLACE_WITH_LOCAL_VALUE" required>
              <button type="button" class="pw-toggle" data-toggle-pw="confirm" aria-label="<?= e(mmTexto('Mostrar u ocultar contraseña')) ?>">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7z"/><circle cx="12" cy="12" r="3"/></svg>
              </button>
            </div>
          </div>
        </div>

        <button type="submit" class="btn btn-primary btn-full"><?= e(mmTexto('Crear cuenta')) ?></button>
      </form>
    </div>
  </div>
</main>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
