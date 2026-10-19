<?php
// ============================================================
//  MusicMad – Centro de configuración de la cuenta
//  configuracion.php
// ============================================================
ob_start();
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/social.php';
require_once __DIR__ . '/includes/email_account.php';
requerirSesion();

if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    http_response_code(405);
    exit('Método no permitido.');
}

$mensaje = '';
$tipoMsg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    exigirCsrf();
    $accion = textoCampo($_POST, 'accion');
    if ($accion === 'verificar_correo') {
        $st = getDB()->prepare('SELECT email FROM usuarios WHERE id=? AND activo=1');
        $st->execute([(int)sesion('usuario_id')]);
        $email = $st->fetchColumn();
        try {
            if (is_string($email)) {
                solicitarCorreoCuenta($email, 'verificar');
            }
            $mensaje = 'Si corresponde, recibirás un enlace para confirmar el correo.';
            $tipoMsg = 'success';
        } catch (Throwable $error) {
            $mensaje = 'El envío de correo no está disponible por el momento.';
            $tipoMsg = 'error';
        }
    } elseif (in_array($accion, ['revocar_dispositivo','revocar_otros'], true)) {
        $uid = (int)sesion('usuario_id');
        if (!registroSesionesDisponible()) {
            $mensaje = 'La gestión de dispositivos no está disponible todavía.';
            $tipoMsg = 'error';
        } elseif ($accion === 'revocar_dispositivo') {
            $mensaje = revocarDispositivoCuenta($uid, idCampo($_POST, 'dispositivo')) ? 'Acceso cerrado.' : 'No se encontró ese dispositivo.';
            $tipoMsg = 'success';
            if (!estaAutenticado()) {
                header('Location: login.php');
                exit;
            }
        } else {
            $db = getDB();
            $db->beginTransaction();
            try {
                $db->prepare('SELECT id FROM usuarios WHERE id=? FOR UPDATE')->execute([$uid]);
                revocarAccesosCuenta($uid);
                revocarCookieRecordada();
                session_regenerate_id(true);
                registrarDispositivoSesion($uid);
                unset($_SESSION['csrf']);
                if (controlesCuentaDisponibles()) {
                    auditarCuenta($uid, 'otros_revocados');
                }
                $db->commit();
            } catch (Throwable $error) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                throw $error;
            }
            $mensaje = 'Cerramos todos los otros accesos, incluidas las apps conectadas.';
            $tipoMsg = 'success';
        }
    } elseif ($accion === 'guardar_privacidad') {
        $ok = social3Disponible() && cambiarPrivacidadPerfil((int) sesion('usuario_id'), textoCampo($_POST, 'visibilidad'), textoCampo($_POST, 'permitir_mensajes'));
        $mensaje = $ok ? 'Preferencias de privacidad guardadas.' : 'No se pudieron guardar. Importa la actualización social3.sql si aún no lo has hecho.';
        $tipoMsg = $ok ? 'success' : 'error';
    } elseif ($accion === 'quitar_ignorado' && social3Disponible()) {
        $st = getDB()->prepare('DELETE FROM usuarios_bloqueados WHERE usuario_id=:u AND objetivo_id=:o AND modo=:m');
        $st->execute([':u' => (int) sesion('usuario_id'), ':o' => idCampo($_POST, 'usuario'), ':m' => textoCampo($_POST, 'modo')]);
        $mensaje = $st->rowCount() ? 'La preferencia se quitó.' : 'No se encontró esa preferencia.';
        $tipoMsg = $st->rowCount() ? 'success' : 'error';
    } elseif ($accion === 'cambiar_REPLACE_WITH_LOCAL_VALUE' && controlesCuentaDisponibles()) {
        try {
            cambiarPasswordCuentaConfirmada((int)sesion('usuario_id'), textoCampo($_POST, 'actual'), textoCampo($_POST, 'nueva'), textoCampo($_POST, 'confirmar'), textoCampo($_POST, 'codigo'));
            $mensaje = 'Contraseña actualizada. Los demás dispositivos deben iniciar sesión de nuevo.';
            $tipoMsg = 'success';
        } catch (DomainException|InvalidArgumentException|OverflowException $error) {
            $mensaje = $error->getMessage();
            $tipoMsg = 'error';
        } catch (Throwable $error) {
            error_log('Password change unavailable: ' . get_class($error));
            $mensaje = 'No se pudo completar el cambio. Inténtalo de nuevo.';
            $tipoMsg = 'error';
        }
    } elseif ($accion === 'cambiar_REPLACE_WITH_LOCAL_VALUE') {
        $actual = textoCampo($_POST, 'actual');
        $nueva = textoCampo($_POST, 'nueva');
        $confirmar = textoCampo($_POST, 'confirmar');
        $id = (int) sesion('usuario_id');

        $st = getDB()->prepare('SELECT REPLACE_WITH_LOCAL_VALUE FROM usuarios WHERE id = :id AND activo = 1 LIMIT 1');
        $st->execute([':id' => $id]);
        $hash = $st->fetchColumn();

        if (!is_string($hash) || !REPLACE_WITH_LOCAL_VALUE_verify($actual, $hash)) {
            $mensaje = 'La contraseña actual no es correcta.';
            $tipoMsg = 'error';
        } elseif (mb_strlen($nueva) < 12 || strlen($nueva) > 72 || str_contains($nueva, chr(0))) {
            $mensaje = 'La nueva contraseña debe tener al menos 12 caracteres y máximo 72 bytes.';
            $tipoMsg = 'error';
        } elseif ($nueva !== $confirmar) {
            $mensaje = 'La confirmación no coincide.';
            $tipoMsg = 'error';
        } elseif (hash_equals($actual, $nueva)) {
            $mensaje = 'Elige una contraseña distinta de la actual.';
            $tipoMsg = 'error';
        } else {
            $db = getDB();
            $db->beginTransaction();
            try {
                $locked = $db->prepare('SELECT REPLACE_WITH_LOCAL_VALUE FROM usuarios WHERE id=? AND activo=1 FOR UPDATE');
                $locked->execute([$id]);
                $lockedHash = $locked->fetchColumn();
                if (!is_string($lockedHash) || !hash_equals($hash, $lockedHash)) {
                    throw new RuntimeException('Las credenciales cambiaron durante la solicitud.');
                }
                if (tieneSegundoFactor($id) && !consumirSegundoFactor($id, textoCampo($_POST, 'codigo'))) {
                    $db->commit();
                    $mensaje = 'Confirma un código nuevo de seguridad o recuperación.';
                    $tipoMsg = 'error';
                } else {
                    $db->prepare('UPDATE usuarios SET REPLACE_WITH_LOCAL_VALUE = :REPLACE_WITH_LOCAL_VALUE WHERE id = :id AND activo = 1')->execute([
                        ':REPLACE_WITH_LOCAL_VALUE' => REPLACE_WITH_LOCAL_VALUE_hash($nueva, PASSWORD_BCRYPT, ['cost' => 12]),
                        ':id' => $id,
                    ]);
                    revocarAccesosCuenta($id);
                    revocarCookieRecordada();
                    session_regenerate_id(true);
                    registrarDispositivoSesion($id);
                    $db->commit();
                    unset($_SESSION['csrf']);
                    $_SESSION['csrf'] = bin2hex(random_bytes(32));
                    $mensaje = 'Contraseña actualizada. Los demás dispositivos tendrán que iniciar sesión de nuevo.';
                    $tipoMsg = 'success';
                }
            } catch (Throwable $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                error_log('No se pudo actualizar la contraseña: ' . get_class($e));
                $mensaje = 'No se pudo guardar el cambio. Inténtalo de nuevo.';
                $tipoMsg = 'error';
            }
        }
    } else {
        $mensaje = 'Acción no válida.';
        $tipoMsg = 'error';
        http_response_code(422);
    }
}

$privacidad = obtenerPrivacidadPerfil((int) sesion('usuario_id'));
$dispositivos = registroSesionesDisponible() ? dispositivosCuenta((int)sesion('usuario_id')) : [];
$ignorados = social3Disponible() ? usuariosIgnorados((int) sesion('usuario_id')) : [];

$pageTitle = 'Configuración';
$pageCss = ['CSS/social.css'];
$navActivo = 'configuracion';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/navbar.php';
?>
<main class="container settings-page">
      <?php selectorIdiomaMusicMad('configuracion'); ?>
  <header class="settings-heading">
    <span class="settings-kicker">TU CUENTA · MUSICMAD</span>
    <h1>Configuración</h1>
    <p>Cuida tu acceso y personaliza tu espacio en la comunidad.</p>
  </header>

  <div class="settings-layout">
    <nav class="card settings-nav" aria-label="Secciones de configuración">
      <span class="settings-nav-title">TU ESPACIO</span>
      <a href="<?= e(rutaAplicacion('perfil.php')) ?>"><span class="settings-nav-icon">♫</span><span><strong>Mi perfil</strong><small>Portada, instrumento y bio</small></span><b aria-hidden="true">›</b></a>
      <a href="<?= e(anclaAplicacion('#seguridad')) ?>" class="active" aria-current="page"><span class="settings-nav-icon">⌑</span><span><strong>Seguridad</strong><small>Contraseña y acceso</small></span><b aria-hidden="true">›</b></a>
      <a href="<?= e(rutaAplicacion('seguridad.php')) ?>"><span class="settings-nav-icon">⌑</span><span><strong>Verificación en dos pasos</strong><small>App autenticadora y recuperación</small></span><b aria-hidden="true">›</b></a>
      <a href="<?= e(anclaAplicacion('#privacidad')) ?>"><span class="settings-nav-icon">◉</span><span><strong>Privacidad</strong><small>Quién ve tu perfil y te escribe</small></span><b aria-hidden="true">›</b></a>
      <a href="<?= e(rutaAplicacion('notificaciones.php')) ?>"><span class="settings-nav-icon">♢</span><span><strong>Notificaciones</strong><small>Tu actividad reciente</small></span><b aria-hidden="true">›</b></a>
      <a href="<?= e(rutaAplicacion('epk.php')) ?>"><span class="settings-nav-icon">♫</span><span><strong>Portafolio de artista</strong><small>Nombre, pistas, enlaces y QR</small></span><b aria-hidden="true">›</b></a>
      <a href="<?= e(rutaAplicacion('datos.php')) ?>"><span class="settings-nav-icon">↓</span><span><strong>Tus datos</strong><small>Descarga, actividad y cuenta</small></span><b aria-hidden="true">›</b></a>
      <a href="<?= e(rutaAplicacion('avisos-dispositivo.php')) ?>"><span class="settings-nav-icon">♧</span><span><strong>Avisos del dispositivo</strong><small>Permisos y preferencias</small></span><b aria-hidden="true">›</b></a>
    </nav>

    <div class="settings-content">
      <section class="card settings-panel" id="seguridad">
        <div class="settings-panel-heading">
          <span class="settings-panel-icon" aria-hidden="true">⌑</span>
          <div><span class="settings-kicker">ACCESO A TU CUENTA</span><h2>Contraseña</h2><p>Usa una contraseña única que no compartas con otros sitios.</p></div>
        </div>
        <?php if ($mensaje): ?><div class="alert alert-<?= e($tipoMsg) ?>" role="status"><?= e($mensaje) ?></div><?php endif; ?>
        <form method="POST" action="configuracion.php#seguridad" class="settings-form" autocomplete="off">
          <?= csrfCampo() ?><input type="hidden" name="accion" value="cambiar_REPLACE_WITH_LOCAL_VALUE">
          <div class="form-group"><label for="actual">Contraseña actual</label><input id="actual" name="actual" type="REPLACE_WITH_LOCAL_VALUE" autocomplete="current-REPLACE_WITH_LOCAL_VALUE" required></div>
          <div class="form-group"><label for="nueva">Nueva contraseña</label><input id="nueva" name="nueva" type="REPLACE_WITH_LOCAL_VALUE" minlength="12" maxlength="72" autocomplete="new-REPLACE_WITH_LOCAL_VALUE" aria-describedby="REPLACE_WITH_LOCAL_VALUE-hint" required><small id="REPLACE_WITH_LOCAL_VALUE-hint" class="settings-hint">Mínimo 12 caracteres.</small></div>
          <div class="form-group"><label for="confirmar">Confirmar contraseña nueva</label><input id="confirmar" name="confirmar" type="REPLACE_WITH_LOCAL_VALUE" minlength="12" maxlength="72" autocomplete="new-REPLACE_WITH_LOCAL_VALUE" required></div>
          <?php if (tieneSegundoFactor((int)sesion('usuario_id'))): ?><label>Código de seguridad o recuperación<input name="codigo" autocomplete="one-time-code" maxlength="24" required></label><?php endif; ?>
          <button class="btn btn-primary" type="submit">Actualizar contraseña</button>
        </form>
      </section>


      <section class="card settings-panel" id="correo"><h2>Correo de tu cuenta</h2><p><?= correoCuentaVerificado((int)sesion('usuario_id')) ? 'Correo confirmado.' : 'Confirma tu correo para recuperar tu acceso cuando lo necesites.' ?></p><form method="POST" action="configuracion.php#correo"><?= csrfCampo() ?><input type="hidden" name="accion" value="verificar_correo"><button class="btn btn-primary" type="submit" <?= correoConfigurado() ? '' : 'disabled' ?>>Enviar enlace de confirmación</button></form><?php if (!correoConfigurado()):?><p>El envío de correo aún no está disponible.</p><?php endif;?></section>
      <section class="card settings-panel" id="sesion">
        <div class="settings-panel-heading">
          <span class="settings-panel-icon" aria-hidden="true">◷</span>
          <div><span class="settings-kicker">EN ESTE DISPOSITIVO</span><h2>Sesión guardada</h2><p>Al entrar puedes elegir si MusicMad recuerda tu sesión hasta 30 días.</p></div>
        </div>
        <div class="settings-note"><span aria-hidden="true">ⓘ</span><p>Si compartes el dispositivo, desmarca <strong>Guardar sesión</strong> al iniciar. Cerrar sesión elimina el acceso recordado de este dispositivo; cambiar tu contraseña revoca los demás.</p></div>
      </section>


      <?php if (registroSesionesDisponible()): ?>
      <section class="card settings-panel" id="dispositivos"><div class="settings-panel-heading"><div><span class="settings-kicker">ACCESOS ACTIVOS</span><h2>Tus dispositivos</h2><p>Los nombres son aproximados. Puedes cerrar un acceso que no reconozcas.</p></div></div>
        <ul class="device-list"><?php foreach ($dispositivos as $device):$current = hash_equals((string)($_SESSION['device_selector'] ?? ''), $device['selector']);?><li><div><strong><?= e($device['etiqueta']) ?></strong><small><?= $current ? 'Este dispositivo · ' : '' ?>Último uso: <?= e($device['ultimo_uso']) ?> UTC</small></div><?php if (!$current): ?><form method="POST" action="configuracion.php#dispositivos"><?= csrfCampo() ?><input type="hidden" name="accion" value="revocar_dispositivo"><input type="hidden" name="dispositivo" value="<?= (int)$device['id'] ?>"><button type="submit" class="btn btn-ghost">Cerrar acceso</button></form><?php endif; ?></li><?php endforeach;?></ul>
        <form method="POST" action="configuracion.php#dispositivos"><?= csrfCampo() ?><input type="hidden" name="accion" value="revocar_otros"><button class="btn btn-primary" type="submit">Cerrar los demás accesos</button></form>
      </section>
      <?php endif; ?>
      <section class="card settings-panel" id="privacidad">
        <div class="settings-panel-heading"><span class="settings-panel-icon" aria-hidden="true">◉</span><div><span class="settings-kicker">TU ESPACIO</span><h2>Privacidad y mensajes</h2><p>Elige cómo compartir tu actividad con otros músicos.</p></div></div>
        <?php if (!social3Disponible()): ?><p class="settings-note">Importa config/social3.sql para activar estos controles.</p><?php else: ?>
          <form method="POST" action="configuracion.php#privacidad" class="settings-form">
            <?= csrfCampo() ?><input type="hidden" name="accion" value="guardar_privacidad">
            <div class="form-group"><label for="visibilidad">Visibilidad del perfil</label><select id="visibilidad" name="visibilidad"><option value="publico" <?= $privacidad['visibilidad'] === 'publico' ? 'selected' : '' ?>>Público</option><option value="seguidores" <?= $privacidad['visibilidad'] === 'seguidores' ? 'selected' : '' ?>>Solo seguidores</option><option value="privado" <?= $privacidad['visibilidad'] === 'privado' ? 'selected' : '' ?>>Privado, requiere aprobación</option></select></div>
            <div class="form-group"><label for="permitir-mensajes">Quién puede enviarte mensajes</label><select id="permitir-mensajes" name="permitir_mensajes"><option value="todos" <?= $privacidad['permitir_mensajes'] === 'todos' ? 'selected' : '' ?>>Todos</option><option value="seguidores" <?= $privacidad['permitir_mensajes'] === 'seguidores' ? 'selected' : '' ?>>Solo quienes sigues</option><option value="nadie" <?= $privacidad['permitir_mensajes'] === 'nadie' ? 'selected' : '' ?>>Nadie</option></select></div>
            <button class="btn btn-primary" type="submit">Guardar privacidad</button>
          </form>
        <?php endif; ?>
      </section>

      <?php if (social3Disponible()): ?>
      <section class="card settings-panel" id="usuarios-ignorados">
        <div class="settings-panel-heading"><span class="settings-panel-icon" aria-hidden="true">⊘</span><div><span class="settings-kicker">CONTROL DE TU ESPACIO</span><h2>Bloqueados y silenciados</h2><p>Administra qué perfiles no aparecen en tu comunidad.</p></div></div>
        <?php if (!$ignorados): ?><p class="settings-note">Todavía no has bloqueado ni silenciado músicos.</p><?php else: ?><ul class="ignored-users-list">
          <?php foreach ($ignorados as $ignorado): ?><li><a href="<?= e(rutaAplicacion('musico.php?id=' . (int) $ignorado['id'])) ?>"><img src="<?= e(musicmadPublicImageUrl($ignorado['avatar'])) ?>" alt=""><span><strong><?= e($ignorado['nombre'] . ' ' . $ignorado['apellido']) ?></strong><small><?= $ignorado['modo'] === 'bloquear' ? 'Bloqueado' : 'Silenciado' ?></small></span></a><form method="POST" action="configuracion.php#usuarios-ignorados"><?= csrfCampo() ?><input type="hidden" name="accion" value="quitar_ignorado"><input type="hidden" name="usuario" value="<?= (int) $ignorado['id'] ?>"><input type="hidden" name="modo" value="<?= e($ignorado['modo']) ?>"><button type="submit" class="btn btn-ghost btn-sm">Quitar</button></form></li><?php endforeach; ?>
        </ul><?php endif; ?>
      </section>
      <?php endif; ?>

      <section class="settings-profile-link">
        <div><strong>¿Quieres personalizar tu tarjeta de músico?</strong><p>Cambia tu portada, avatar, instrumento y géneros.</p></div>
        <a href="<?= e(rutaAplicacion('perfil.php')) ?>" class="btn btn-ghost">Editar perfil <span aria-hidden="true">↗</span></a>
      </section>
    </div>
  </div>
</main>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
