<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/totp_account.php';
requerirSesion();
if (!in_array($_SERVER['REQUEST_METHOD'], ['GET','POST'], true)) {
    header('Allow: GET, POST');
    http_response_code(405);
    exit;
}
$uid = (int)sesion('usuario_id');
$result = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    exigirCsrf();
    try {
        $action = textoCampo($_POST, 'accion');
        $result = match($action) {
            'preparar' => prepararSegundoFactor($uid, textoCampo($_POST, 'REPLACE_WITH_LOCAL_VALUE')),'confirmar' => confirmarSegundoFactor($uid, textoCampo($_POST, 'codigo')),'regenerar' => cambiarSegundoFactor($uid, textoCampo($_POST, 'REPLACE_WITH_LOCAL_VALUE'), textoCampo($_POST, 'codigo'), false),'desactivar' => cambiarSegundoFactor($uid, textoCampo($_POST, 'REPLACE_WITH_LOCAL_VALUE'), textoCampo($_POST, 'codigo'), true),default => ['ok' => false,'mensaje' => 'Acción no válida.']
        };
    } catch (Throwable $error) {
        error_log('Two factor settings: ' . get_class($error));
        $result = ['ok' => false,'mensaje' => 'No pudimos guardar el cambio. Reintenta.'];
    }
}
$enabled = tieneSegundoFactor($uid);
$pending = totpDisponible() && !$enabled ? secretoSegundoFactorPendiente($uid) : null;
$pageTitle = 'Verificación en dos pasos';
$pageCss = ['CSS/social.css','CSS/account.css'];
$pageJs = ['assets/vendor/qrcode/qrcodegen-1.8.0.js','js/qr.js'];
require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/navbar.php';
?>
<main class="container narrow settings-page"><a href="<?= e(rutaAplicacion('configuracion.php')) ?>" class="btn btn-ghost">Volver a configuración</a><header class="settings-heading"><span class="settings-kicker">ACCESO A TU CUENTA</span><h1>Verificación en dos pasos</h1><p>Tu contraseña y un código de tu teléfono protegen tu música.</p></header>
<?php if ($result):?><div class="alert alert-<?= $result['ok'] ? 'success' : 'error' ?>" role="status"><?= e($result['mensaje']) ?></div><?php endif;?>
<?php if (!empty($result['codigos'])):?><section class="card settings-panel"><h2>Guarda tus códigos de recuperación</h2><p>Se muestran ahora. Cada código sirve una sola vez si pierdes el teléfono; guárdalos en un lugar privado.</p><ul class="recovery-codes"><?php foreach ($result['codigos'] as $code):?><li><code><?= e($code) ?></code></li><?php endforeach;?></ul></section><?php endif;?>
<section class="card settings-panel"><h2><?= $enabled ? 'Protección activada' : 'Añade una segunda protección' ?></h2>
<?php if (!totpDisponible()):?><p>La verificación en dos pasos todavía no está disponible en este sitio.</p>
<?php elseif ($pending !== null):?><?php $totpUri = 'otpauth://totp/' . rawurlencode('MusicMad:' . (string)sesion('usuario_email')) . '?' . http_build_query(['secret' => $pending,'issuer' => 'MusicMad','algorithm' => 'SHA1','digits' => 6,'period' => 30], '', '&', PHP_QUERY_RFC3986); ?><div class="account-qr" data-qr-kind="totp" data-qr-value="<?= e($totpUri) ?>"><p>El QR se genera en este dispositivo. También puedes usar la clave escrita.</p></div><p>En tu app autenticadora, escanea el QR o añade una cuenta con esta clave y el nombre MusicMad. Elige códigos basados en tiempo.</p><p><code class="totp-key"><?= e($pending) ?></code></p><form method="POST" action="<?= e(rutaAplicacion('seguridad.php', 'POST')) ?>" class="settings-form"><?= csrfCampo() ?><input type="hidden" name="accion" value="confirmar"><label>Código de seis dígitos<input name="codigo" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" required></label><button type="submit" class="btn btn-primary">Confirmar y activar</button></form>
<?php elseif (!$enabled):?><p>Usa una app autenticadora de tu teléfono. Primero confirma tu contraseña para preparar la clave.</p><form method="POST" action="<?= e(rutaAplicacion('seguridad.php', 'POST')) ?>" class="settings-form"><?= csrfCampo() ?><input type="hidden" name="accion" value="preparar"><label>Contraseña actual<input name="REPLACE_WITH_LOCAL_VALUE" type="REPLACE_WITH_LOCAL_VALUE" autocomplete="current-REPLACE_WITH_LOCAL_VALUE" required maxlength="72"></label><button type="submit" class="btn btn-primary">Preparar verificación</button></form>
<?php else:?><p>El código se solicita al entrar con contraseña, Google o API. Guardar sesión evita repetirlo mientras ese acceso siga vigente.</p><details data-sheet data-sheet-title="Administrar verificación" class="quick-sheet"><summary>Administrar protección y códigos</summary><form method="POST" action="<?= e(rutaAplicacion('seguridad.php', 'POST')) ?>" class="settings-form"><?= csrfCampo() ?><label>Contraseña actual<input name="REPLACE_WITH_LOCAL_VALUE" type="REPLACE_WITH_LOCAL_VALUE" autocomplete="current-REPLACE_WITH_LOCAL_VALUE" required></label><label>Código nuevo o de recuperación<input name="codigo" autocomplete="one-time-code" maxlength="24" required></label><button class="btn btn-primary" type="submit" name="accion" value="regenerar">Reemplazar códigos de recuperación</button><button class="btn btn-ghost" type="submit" name="accion" value="desactivar">Desactivar verificación</button></form></details><?php endif; ?>
</section><p class="settings-hint">No compartas la clave ni los códigos. Un código usado no vuelve a aceptarse; espera al siguiente si ya lo utilizaste.</p></main>
<?php require __DIR__ . '/includes/footer.php'; ?>
