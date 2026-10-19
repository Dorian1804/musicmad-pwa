<?php
// ============================================================
//  MusicMad – Mi perfil
//  perfil.php
//
//  Etiqueta de usuario editable (avatar + biografía + color),
//  igual en espíritu a la actividad 1.4, pero:
//   - Sin consumo de la API de GitHub (solo avatares locales).
//   - Los datos se guardan en la base de datos de MusicMad,
//     no en localStorage ni en memoria temporal.
// ============================================================
ob_start();
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/social.php';
require_once __DIR__ . '/includes/avatar_upload.php';

requerirSesion();

$mensaje = '';
$tipoMsg = '';

$usuarioId = (int) sesion('usuario_id');
$perfil = obtenerPerfil($usuarioId);
$portada = obtenerPortadaPerfil($usuarioId);
$portadaValor = str_starts_with($portada, 'uploads/covers/') ? basename($portada) : $portada;
$portadas = portadasPerfilDisponibles();

if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    http_response_code(405);
    exit('Método no permitido.');
}

if (!$perfil) {
    header('Location: index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    $mensaje = 'El formulario superó el límite de subida que permite este hosting. Prueba con un archivo menor o pide aumentar post_max_size.';
    $tipoMsg = 'error';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    exigirCsrf();
    $avatarAnterior = $perfil['avatar'];
    $datos = [
        'avatar' => textoCampo($_POST, 'avatar'),
        'bio' => textoCampo($_POST, 'bio'),
        'color' => textoCampo($_POST, 'color') ?: COLOR_PERFIL_DEFECTO,
    ];

    $avatarNuevo = null;
    $portadaNuevaArchivo = textoCampo($_POST, 'portada');
    $portadaNueva = null;
    $errorArchivo = null;
    if (archivoSubido('foto_perfil')) {
        $carga = guardarAvatarSubido($usuarioId, $_FILES['foto_perfil']);
        if ($carga['ok']) {
            $avatarNuevo = $carga['ruta'];
            $datos['avatar'] = $avatarNuevo;
        } else {
            $errorArchivo = $carga['mensaje'];
        }
    }
    if (!$errorArchivo && archivoSubido('foto_portada')) {
        $carga = guardarPortadaSubida($usuarioId, $_FILES['foto_portada']);
        if ($carga['ok']) {
            $portadaNueva = $carga['archivo'];
            $portadaNuevaArchivo = $carga['archivo'];
        } else {
            $errorArchivo = $carga['mensaje'];
        }
    }

    $resultado = ['ok' => false, 'mensaje' => $errorArchivo ?: 'No se pudieron guardar los cambios.'];
    if (!$errorArchivo) {
        $db = getDB();
        try {
            $db->beginTransaction();
            $resultado = actualizarPerfil($usuarioId, $datos);
            if (!$resultado['ok']) {
                throw new InvalidArgumentException($resultado['mensaje']);
            }
            if (!guardarPortadaPerfil($usuarioId, $portadaNuevaArchivo)) {
                throw new InvalidArgumentException('Elige una portada disponible o sube una imagen válida.');
            }
            guardarPerfilMusical($usuarioId, [
                'instrumento' => textoCampo($_POST, 'instrumento'),
                'ciudad' => textoCampo($_POST, 'ciudad'),
                'nivel' => textoCampo($_POST, 'nivel') ?: nivelMusico($usuarioId),
                'generos' => is_array($_POST['generos'] ?? null) ? $_POST['generos'] : [],
            ]);
            $db->commit();
            $resultado = ['ok' => true, 'mensaje' => '¡Perfil actualizado correctamente!'];
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $_SESSION['usuario_avatar'] = $perfil['avatar'];
            $_SESSION['usuario_bio'] = $perfil['bio'] ?? '';
            $_SESSION['usuario_color'] = $perfil['color_perfil'] ?: COLOR_PERFIL_DEFECTO;
            $resultado = ['ok' => false, 'mensaje' => $e instanceof LogicException ? $e->getMessage() : 'No se pudieron guardar los cambios. Inténtalo de nuevo.'];
            if (!$e instanceof LogicException) {
                error_log('No se pudo guardar el perfil: ' . get_class($e));
            }
        }
    }

    if ($resultado['ok']) {
        if ($avatarNuevo !== null && $avatarAnterior !== $avatarNuevo) {
            eliminarAvatarSubido($usuarioId, $avatarAnterior);
        } elseif ($avatarNuevo === null && $datos['avatar'] !== $avatarAnterior) {
            eliminarAvatarSubido($usuarioId, $avatarAnterior);
        }
        $portadaAnteriorArchivo = str_starts_with($portada, 'uploads/covers/') ? basename($portada) : '';
        if ($portadaAnteriorArchivo !== '' && $portadaAnteriorArchivo !== $portadaNuevaArchivo) {
            eliminarPortadaSubida($usuarioId, $portadaAnteriorArchivo);
        }
        $portada = obtenerPortadaPerfil($usuarioId);
        $portadaValor = str_starts_with($portada, 'uploads/covers/') ? basename($portada) : $portada;
    } else {
        if ($avatarNuevo !== null) {
            eliminarAvatarSubido($usuarioId, $avatarNuevo);
        }
        if ($portadaNueva !== null) {
            eliminarPortadaSubida($usuarioId, $portadaNueva);
        }
    }
    $mensaje = $resultado['mensaje'];
    $tipoMsg = $resultado['ok'] ? 'success' : 'error';

    // Refrescamos con los datos ya guardados
    $perfil = obtenerPerfil($usuarioId);
}

$musical = obtenerPerfilMusical($usuarioId);
$miNivel = nivelMusico($usuarioId);
$misGeneros = array_map('trim', explode(',', $musical['generos']));
$avatares = avataresDisponibles();
$pageTitle = 'Mi perfil';
$pageCss = ['CSS/social.css'];
$pageProfileIds = [$usuarioId];
$pageJs = ['js/crop-photo.js', 'js/perfil.js', 'js/upload-progress.js'];
$navActivo = 'perfil';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/navbar.php';
?>
<main class="container">
<?php renderCompartirPublico('perfil', (int) sesion('usuario_id')); ?>
  <h1 class="page-title"><?= e(mmTexto('Tu espacio en la escena')) ?></h1>
  <p class="page-sub"><?= e(mmTexto('Arma una ficha clara para que otros músicos sepan quién eres y cómo colaborar contigo.')) ?> <a href="<?= e(rutaAplicacion('musico.php?id=' . $usuarioId)) ?>"><?= e(mmTexto('Ver mi perfil público ↗')) ?></a> · <a href="<?= e(rutaAplicacion('epk.php')) ?>"><?= e(mmTexto('Mi portafolio de artista ↗')) ?></a></p>

  <?php if ($mensaje): ?>
    <div class="alert alert-<?= e($tipoMsg) ?>" data-autohide="6000"><?= e(mmTexto($mensaje)) ?></div>
  <?php endif; ?>

  <div class="perfil-grid">

    <section class="card profile-editor-card">
      <form method="POST" action="" id="form-perfil" class="profile-edit-form" data-upload-form enctype="multipart/form-data" novalidate>
        <?= csrfCampo() ?>
        <input type="hidden" id="input-avatar" name="avatar" value="<?= e($perfil['avatar']) ?>">
        <input type="hidden" id="input-portada" name="portada" value="<?= e($portadaValor) ?>">
        <input class="file-picker-hidden" type="file" id="photo-upload" aria-label="Foto de perfil" name="foto_perfil" accept="image/*">
        <input class="file-picker-hidden" type="file" id="cover-upload" aria-label="Foto de portada" name="foto_portada" accept="image/*">

        <details class="profile-preset-picker">
          <summary><span class="profile-section-number">01</span><span><strong><?= e(mmTexto('Portadas ilustradas')) ?></strong><small><?= e(mmTexto('Elige una de MusicMad si prefieres no subir una foto.')) ?></small></span><b aria-hidden="true">⌄</b></summary>
          <div id="portada-selector" class="portada-grid">
            <?php foreach ($portadas as $ruta => $nombre): ?>
              <button type="button" class="portada-option" data-url="<?= e($ruta) ?>" aria-label="Portada <?= e($nombre) ?>" aria-pressed="<?= $portada === $ruta ? 'true' : 'false' ?>">
                <img src="<?= e(musicmadPublicImageUrl($ruta)) ?>" alt="">
                <span><?= e($nombre) ?></span>
              </button>
            <?php endforeach; ?>
          </div>
        </details>

        <details class="avatar-library profile-preset-picker">
          <summary><span class="profile-section-number">02</span><span><strong><?= e(mmTexto('Avatares ilustrados')) ?></strong><small><?= e(mmTexto('Usa uno de los perfiles musicales de MusicMad.')) ?></small></span><b aria-hidden="true">⌄</b></summary>
          <div id="avatar-selector" class="avatar-grid">
            <?php foreach ($avatares as $ruta): ?>
              <button type="button" class="avatar-option" data-url="<?= e($ruta) ?>" aria-label="Usar avatar musical <?= (int) array_search($ruta, $avatares, true) + 1 ?>" aria-pressed="false"><img src="<?= e(musicmadPublicImageUrl($ruta)) ?>" alt="" width="72" height="72" loading="lazy"></button>
            <?php endforeach; ?>
          </div>
        </details>

        <section class="profile-form-section">
          <div class="profile-section-heading"><span>03</span><div><h2><?= e(mmTexto('Tu ficha musical')) ?></h2><p><?= e(mmTexto('Ayuda a la comunidad a encontrar a la persona correcta.')) ?></p></div></div>
        <div class="form-group">
          <label><?= e(mmTexto('Nombre a mostrar')) ?></label>
          <p class="perfil-static">
            <?= e($perfil['nombre'] . ' ' . $perfil['apellido']) ?><br>
            <small><?= e(mmTexto('El nombre se cambia desde tu cuenta, no desde aquí.')) ?></small>
          </p>
        </div>

        <div class="grid-2">
          <div class="form-group">
            <label for="input-instrumento"><?= e(mmTexto('Instrumento principal')) ?></label>
            <input type="text" id="input-instrumento" name="instrumento" maxlength="60"
                   placeholder="Guitarra, voz, producción…" value="<?= e($musical['instrumento']) ?>">
          </div>
          <div class="form-group">
            <label for="input-ciudad"><?= e(mmTexto('Ciudad')) ?></label>
            <input type="text" id="input-ciudad" name="ciudad" maxlength="80"
                   placeholder="Guadalajara, Jal." value="<?= e($musical['ciudad']) ?>">
          </div>
        </div>

        <?php if (matchingDisponible()): ?><div class="form-group"><label for="nivel-musical"><?= e(mmTexto('Tu nivel musical')) ?></label><select name="nivel" id="nivel-musical"><?php foreach (NIVELES_MUSICALES as $key => $label): ?><option value="<?= $key ?>" <?= $miNivel === $key ? 'selected' : '' ?>><?= e(mmTexto($key === 'sin_definir' ? 'Prefiero no indicarlo' : $label)) ?></option><?php endforeach; ?></select><small><?= e(mmTexto('Es opcional y ayuda a encontrar proyectos compatibles.')) ?></small></div><?php endif; ?>
        <div class="form-group">
          <label><?= e(mmTexto('Géneros')) ?> <span class="hint"><?= e(mmTexto('(hasta 4)')) ?></span></label>
          <div class="chips-check">
            <?php foreach (GENEROS as $g): ?>
              <label class="chip-check">
                <input type="checkbox" name="generos[]" value="<?= e($g) ?>" <?= in_array($g, $misGeneros, true) ? 'checked' : '' ?>>
                <span><?= e($g) ?></span>
              </label>
            <?php endforeach; ?>
          </div>
        </div>

        <div class="form-group">
          <label for="input-bio"><?= e(mmTexto('Biografía')) ?></label>
          <textarea id="input-bio" name="bio" rows="3" maxlength="280"
                    placeholder="<?= e(mmTexto('Cuéntanos sobre ti...')) ?>"><?= e($perfil['bio']) ?></textarea>
        </div>

        <div class="form-group">
          <label for="input-color"><?= e(mmTexto('Color de acento')) ?></label>
          <input type="color" id="input-color" name="color" value="<?= e($perfil['color_perfil']) ?>">
        </div>
        </section>

        <div class="profile-form-footer"><p><?= e(mmTexto('Las fotos se ajustan automáticamente para que carguen más rápido.')) ?></p><button type="submit" class="btn btn-primary"><?= e(mmTexto('Guardar cambios')) ?></button></div>
      </form>
    </section>

    <aside class="profile-preview-sticky">
      <div class="preview-live-label"><span class="preview-live-dot"></span> <?= e(mmTexto('VISTA PREVIA EN VIVO')) ?></div>
      <article class="profile-card" id="profile-card">
        <svg class="profile-accent-strip" viewBox="0 0 300 4" preserveAspectRatio="none" aria-hidden="true"><rect width="300" height="4" data-preview-accent fill="<?= e($perfil['color_perfil']) ?>"/></svg>
        <div class="profile-card-header" id="preview-header"
             data-profile-style="<?= $usuarioId ?>">
          <img class="profile-cover-photo" id="preview-cover-photo" src="<?= e(musicmadPublicImageUrl($portada)) ?>" alt="">
          <button type="button" class="profile-media-edit profile-cover-edit" data-open-cover><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h3l2-3h6l2 3h3v13H4z"/><circle cx="12" cy="13" r="3.5"/></svg><span><?= e(mmTexto('Cambiar portada')) ?></span></button>
        </div>
        <div class="profile-avatar-control">
          <img id="avatar-preview" class="profile-card-avatar" src="<?= e(musicmadPublicImageUrl($perfil['avatar'])) ?>" alt="Foto de perfil" tabindex="0" role="button" aria-label="Ver foto de perfil ampliada">
          <button type="button" class="profile-media-edit profile-avatar-edit" data-open-avatar aria-label="<?= e(mmTexto('Cambiar foto de perfil')) ?>"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h3l2-3h6l2 3h3v13H4z"/><circle cx="12" cy="13" r="3.5"/></svg></button>
        </div>
        <div class="profile-card-body">
          <div class="profile-card-identity">
            <h3 id="name-preview"><?= e($perfil['nombre'] . ' ' . $perfil['apellido']) ?></h3>
            <span class="profile-card-role" id="role-preview"><?= e($musical['instrumento'] ?: mmTexto('Músico')) ?></span>
          </div>
          <p class="profile-card-bio" id="bio-preview">
            <?= $perfil['bio'] !== '' ? e($perfil['bio']) : e(mmTexto('La biografía aparecerá aquí...')) ?>
          </p>
          <span class="profile-card-hint"><?= e(mmTexto('Toca la foto para verla más cerca · usa la cámara para cambiarla')) ?></span>
          <button type="submit" form="form-perfil" class="profile-save-quick"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg> <?= e(mmTexto('Guardar perfil')) ?></button>
        </div>
      </article>
    </aside>

  </div>
</main>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
