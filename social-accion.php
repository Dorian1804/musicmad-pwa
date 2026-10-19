<?php

// ============================================================
//  MusicMad – Acciones de la red social (solo POST)
//  social-accion.php
//  publicar · aplaudir · comentar · eliminar · seguir · mensaje
//  banda_* · busqueda_*
// ============================================================
ob_start();
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/social.php';
require_once __DIR__ . '/includes/chat_live.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('Método no permitido.');
}
requerirSesion();

// Si el archivo supera post_max_size, PHP vacía $_POST por completo
if (empty($_POST) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    $mensajeTamanio = 'El hosting rechazó el archivo por su límite de subida (post_max_size: ' . (string) ini_get('post_max_size') . '). Para videos de 100 MB, configura upload_max_filesize en 100M y post_max_size en 110M en el hosting.';
    if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'MusicMad-PJAX') {
        http_response_code(413);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'flash' => ['error', $mensajeTamanio]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
    $_SESSION['flash'] = ['error', $mensajeTamanio];
    header('Location: feed.php');
    exit;
}
exigirCsrf();

$yo = (int) sesion('usuario_id');
$accion = textoCampo($_POST, 'accion');
$pubId = idCampo($_POST, 'id');

// Solo se puede volver a páginas propias de la app
$volver = textoCampo($_POST, 'volver') ?: 'feed.php';
if (!preg_match('/^(feed|publicacion|musicos|musico|mensajes|bandas|banda|busco|mercado|mercado-reportes|moderacion|listas|eventos)\.php(\?[A-Za-z0-9_=&%-]*)?$/', $volver)) {
    $volver = 'feed.php';
}

$flash = null;
$ancla = '';

try {
    switch ($accion) {
        case 'publicar':
            $tipoNuevo = textoCampo($_POST, 'tipo');
            $adjuntos = (int)archivoSubido('audio') + (int)archivoSubido('partitura') + (int)archivoSubido('video');
            if ($adjuntos > 1) {
                $flash = ['error', 'Elige solo un medio: audio, partitura o video.'];
                break;
            }
            if ($tipoNuevo === 'partitura' && !archivoSubido('partitura')) {
                $flash = ['error', 'Adjunta el PDF de la partitura.'];
                break;
            }
            if (archivoSubido('video')) {
                // El servidor decide por el archivo elegido; la subida no depende del JavaScript del selector.
                $tipoNuevo = 'video';
                $_POST['tipo'] = 'video';
            } elseif ($tipoNuevo === 'video') {
                $flash = ['error', 'Adjunta el video para publicarlo.'];
                break;
            }
            $r = crearPublicacion($yo, $_POST);
            $flash = [$r['ok'] ? 'success' : 'error', $r['mensaje']];
            if ($r['ok'] && archivoSubido('audio')) {
                $a = guardarAudio($r['id'], $_FILES['audio']);
                if (!$a['ok']) {
                    eliminarPublicacion((int)$r['id'], $yo, false);
                    $flash = ['error', 'No se publicó porque el audio no pudo guardarse. ' . $a['mensaje']];
                }
            }
            if ($r['ok'] && archivoSubido('partitura')) {
                $a = guardarPartitura($r['id'], $_FILES['partitura']);
                if (!$a['ok']) {
                    eliminarPublicacion((int)$r['id'], $yo, false);
                    $flash = ['error', $a['mensaje']];
                }
            }
            if ($r['ok'] && archivoSubido('video')) {
                $v = guardarVideo($r['id'], $_FILES['video']);
                if (!$v['ok']) {
                    eliminarPublicacion((int)$r['id'], $yo, false);
                    $flash = ['error', $v['mensaje']];
                }
            }
            if ($r['ok'] && $flash[0] === 'success' && social3Disponible()) {
                guardarLicenciaPublicacion($yo, (int) $r['id'], textoCampo($_POST, 'licencia') ?: 'todos_derechos');
            }
            break;

        case 'repostear':
            if ($pubId > 0) {
                alternarRepost($yo, $pubId);
                $ancla = '#pub-' . $pubId;
            }
            break;

        case 'instrumento_publicar':
            $r = crearAnuncioInstrumento($yo, $_POST, isset($_FILES['fotos']) && is_array($_FILES['fotos']) ? $_FILES['fotos'] : null);
            $flash = [$r['ok'] ? 'success' : 'error', $r['mensaje']];
            $volver = 'mercado.php';
            break;

        case 'instrumento_reservar':
            $flash = cambiarEstadoInstrumento($pubId, $yo, 'reservado') ? ['success','Anuncio marcado como reservado.'] : ['error','No se pudo actualizar el anuncio.'];
            $volver = 'mercado.php';
            break;

        case 'instrumento_vendido':
            $flash = cambiarEstadoInstrumento($pubId, $yo, 'vendido') ? ['success','Marcado como vendido.'] : ['error','No se pudo actualizar el anuncio.'];
            $volver = 'mercado.php';
            break;

        case 'instrumento_reactivar':
            $flash = cambiarEstadoInstrumento($pubId, $yo, 'disponible') ? ['success','El anuncio volvió a estar disponible.'] : ['error','No se pudo actualizar el anuncio.'];
            $volver = 'mercado.php';
            break;

        case 'instrumento_eliminar':
            $flash = eliminarInstrumento($pubId, $yo) ? ['success','Anuncio eliminado.'] : ['error','No se pudo eliminar el anuncio.'];
            $volver = 'mercado.php';
            break;

        case 'instrumento_favorito':
            if ($pubId > 0) {
                $guardado = alternarFavoritoInstrumento($yo, $pubId);
                $flash = ['success',$guardado ? 'Guardado en tus favoritos.' : 'Quitado de tus favoritos.'];
            }
            break;

        case 'instrumento_reportar':
            $r = reportarInstrumento($yo, $pubId, textoCampo($_POST, 'motivo'), textoCampo($_POST, 'detalle'));
            $flash = [$r['ok'] ? 'success' : 'error',$r['mensaje']];
            break;

        case 'instrumento_reporte_resolver':
            if (!puedeModerar($yo)) {
                http_response_code(403);
                exit('No autorizado.');
            }
            $ok = resolverReporteInstrumento($pubId, $yo, textoCampo($_POST, 'decision'));
            $flash = [$ok ? 'success' : 'error',$ok ? 'Reporte revisado.' : 'No se pudo revisar el reporte.'];
            $volver = 'mercado-reportes.php';
            break;

        case 'aplaudir':
            if ($pubId > 0) {
                alternarAplauso($yo, $pubId);
                $ancla = '#pub-' . $pubId;
            }
            break;

        case 'comentar':
            if ($pubId > 0) {
                if (!agregarComentario($yo, $pubId, textoCampo($_POST, 'texto'))) {
                    $flash = ['error', 'El comentario está vacío o es demasiado largo (máx. 400).'];
                }
                $ancla = '#pub-' . $pubId;
            }
            break;

        case 'guardar_publicacion':
            if ($pubId > 0 && social3Disponible()) {
                $guardada = alternarGuardadoPublicacion($yo, $pubId);
                $flash = ['success', $guardada ? 'Publicación guardada.' : 'Publicación quitada de guardados.'];
                $ancla = '#pub-' . $pubId;
            }
            break;

        case 'reaccion':
            if ($pubId > 0 && social3Disponible()) {
                $ok = guardarReaccionPublicacion($yo, $pubId, textoCampo($_POST, 'reaccion'));
                if (!$ok) {
                    $flash = ['error', 'No se pudo guardar esa reacción.'];
                }
                $ancla = '#pub-' . $pubId;
            }
            break;

        case 'responder_comentario':
            if ($pubId > 0) {
                $ok = responderComentario($yo, idCampo($_POST, 'comentario'), textoCampo($_POST, 'texto'));
                if (!$ok) {
                    $flash = ['error', 'No se pudo agregar la respuesta.'];
                }
                $ancla = '#pub-' . $pubId;
            }
            break;

        case 'comentario_tiempo':
            if ($pubId > 0) {
                $moment = textoCampo($_POST, 'segundo');
                $ok = preg_match('/^(?:0|[1-9][0-9]{0,4})(?:[.][0-9]{1,3})?$/D', $moment) && crearComentarioTiempo($yo, $pubId, (float)$moment, textoCampo($_POST, 'texto'), textoCampo($_POST, 'solicitud'));
                if (!$ok) {
                    $flash = ['error', 'No se pudo guardar el comentario de audio.'];
                }
                $ancla = '#pub-' . $pubId;
            }
            break;

        case 'reportar_publicacion':
            $r = reportarContenidoSocial3($yo, $pubId, textoCampo($_POST, 'motivo'), textoCampo($_POST, 'detalle'));
            $flash = [$r['ok'] ? 'success' : 'error', $r['mensaje']];
            $ancla = '#pub-' . $pubId;
            break;

        case 'reportar_usuario':
            $otro = idCampo($_POST, 'usuario');
            $r = reportarUsuarioSocial3($yo, $otro, textoCampo($_POST, 'motivo'), textoCampo($_POST, 'detalle'));
            $flash = [$r['ok'] ? 'success' : 'error', $r['mensaje']];
            break;

        case 'social_reporte_resolver':
            if (!puedeModerar($yo)) {
                http_response_code(403);
                exit('No autorizado.');
            }
            $decision = textoCampo($_POST, 'decision');
            $ok = resolverReporteSocial3($pubId, $yo, $decision);
            $flash = [$ok ? 'success' : 'error', $ok ? 'Reporte actualizado.' : 'No se pudo actualizar el reporte.'];
            $volver = 'moderacion.php';
            break;

        case 'playlist_crear':
            $r = crearPlaylistSocial3($yo, textoCampo($_POST, 'nombre'), textoCampo($_POST, 'descripcion'), !empty($_POST['publica']));
            $flash = [$r['ok'] ? 'success' : 'error', $r['mensaje']];
            if ($r['ok']) {
                $volver = 'listas.php?id=' . (int) $r['id'];
            }
            break;

        case 'playlist_agregar':
            $listaId = idCampo($_POST, 'playlist');
            $pistaId = idCampo($_POST, 'publicacion');
            $ok = agregarPistaPlaylistSocial3($yo, $listaId, $pistaId);
            $flash = [$ok ? 'success' : 'error', $ok ? 'Pista añadida a la playlist.' : 'No se pudo añadir: quizá no es una pista propia o ya está guardada.'];
            $volver = 'listas.php?id=' . $listaId;
            break;

        case 'playlist_quitar':
            $listaId = idCampo($_POST, 'playlist');
            $pistaId = idCampo($_POST, 'publicacion');
            $ok = quitarPistaPlaylistSocial3($yo, $listaId, $pistaId);
            $flash = [$ok ? 'success' : 'error', $ok ? 'Pista quitada de la playlist.' : 'No se pudo quitar la pista.'];
            $volver = 'listas.php?id=' . $listaId;
            break;

        case 'playlist_eliminar':
            $ok = eliminarPlaylistSocial3($yo, $pubId);
            $flash = [$ok ? 'success' : 'error', $ok ? 'Playlist eliminada.' : 'No se pudo eliminar la playlist.'];
            $volver = 'listas.php';
            break;

        case 'evento_crear':
            $r = crearEventoSocial3($yo, $_POST);
            $flash = [$r['ok'] ? 'success' : 'error', $r['mensaje']];
            break;

        case 'evento_rsvp':
            $estado = textoCampo($_POST, 'estado');
            $ok = actualizarRsvpEventoSocial3($yo, $pubId, $estado);
            $flash = [$ok ? 'success' : 'error', $ok ? ($estado === 'asistire' ? 'Te esperamos. Quedaste en la lista de asistencia.' : 'Te avisaremos en la app cuando te acerques a la fecha.') : 'No se pudo actualizar tu respuesta.'];
            $volver = 'eventos.php';
            break;

        case 'evento_rsvp_quitar':
            $ok = quitarRsvpEventoSocial3($yo, $pubId);
            $flash = [$ok ? 'success' : 'error', $ok ? 'Quitamos tu respuesta del evento.' : 'No se pudo actualizar tu respuesta.'];
            $volver = 'eventos.php';
            break;

        case 'evento_cancelar':
            $ok = cancelarEventoSocial3($yo, $pubId);
            $flash = [$ok ? 'success' : 'error', $ok ? 'Evento cancelado.' : 'No se pudo cancelar el evento.'];
            $volver = 'eventos.php';
            break;

        case 'eliminar':
            if ($pubId > 0) {
                eliminarPublicacion($pubId, $yo, esAdmin());
                $flash = ['success', 'Publicación eliminada.'];
            }
            break;

        case 'seguir':
            $otro = idCampo($_POST, 'usuario');
            if ($otro > 0) {
                $solicitudAntes = social3Disponible() ? estadoSolicitudSeguimiento($yo, $otro) : '';
                alternarSeguir($yo, $otro);
                $solicitudDespues = social3Disponible() ? estadoSolicitudSeguimiento($yo, $otro) : '';
                if ($solicitudDespues === 'pendiente' && $solicitudAntes !== 'pendiente') {
                    $flash = ['success', 'Solicitud de seguimiento enviada.'];
                }
            }
            break;

        case 'bloquear_usuario':
        case 'silenciar_usuario':
            $otro = idCampo($_POST, 'usuario');
            $modoBloqueo = $accion === 'bloquear_usuario' ? 'bloquear' : 'silenciar';
            if ($otro > 0 && $otro !== $yo && social3Disponible()) {
                $activado = cambiarBloqueoUsuario($yo, $otro, $modoBloqueo);
                $flash = ['success', $modoBloqueo === 'bloquear' ? ($activado ? 'Usuario bloqueado.' : 'Bloqueo quitado.') : ($activado ? 'Usuario silenciado.' : 'Silencio quitado.')];
            }
            break;

            // ── Mensajes ────────────────────────────────────────────
        case 'mensaje':
            $para = idCampo($_POST, 'para');
            if (chatLiveDisponible()) {
                try {
                    enviarChatSeguro($yo, $para, textoCampo($_POST, 'texto'), textoCampo($_POST, 'solicitud') ?: bin2hex(random_bytes(16)), idCampo($_POST, 'pista'));
                    $r = ['ok' => true,'mensaje' => ''];
                } catch (LogicException|OverflowException $error) {
                    $r = ['ok' => false,'mensaje' => $error->getMessage()];
                }
            } else {
                $r = enviarMensaje($yo, $para, textoCampo($_POST, 'texto'));
            }
            $volver = 'mensajes.php?con=' . $para;
            if (!$r['ok']) {
                $flash = ['error', $r['mensaje']];
            }
            $ancla = '#fin';
            break;

            // ── Bandas ──────────────────────────────────────────────
        case 'banda_crear':
            $r = crearBanda($yo, $_POST);
            $flash = [$r['ok'] ? 'success' : 'error', $r['mensaje']];
            $volver = $r['ok'] ? 'banda.php?id=' . $r['id'] : 'bandas.php';
            break;

        case 'banda_unirse':
            $ok = unirseBanda($yo, $pubId, textoCampo($_POST, 'instrumento'));
            $flash = $ok ? ['success', '¡Ya eres parte de la banda!'] : ['error', 'No se pudo unir a la banda.'];
            $volver = 'banda.php?id=' . $pubId;
            break;

        case 'banda_salir':
            salirBanda($yo, $pubId);
            $flash = ['success', 'Saliste de la banda.'];
            $volver = 'bandas.php';
            break;

        case 'banda_expulsar':
            expulsarMiembro($yo, $pubId, idCampo($_POST, 'usuario'));
            $volver = 'banda.php?id=' . $pubId;
            break;

        case 'banda_eliminar':
            eliminarBanda($yo, $pubId, esAdmin());
            $flash = ['success', bandasWorkspaceDisponible() ? 'Banda archivada. Puedes restaurarla desde Bandas.' : 'Banda eliminada.'];
            $volver = 'bandas.php';
            break;

            // ── "Se busca" ──────────────────────────────────────────
        case 'busqueda_crear':
            $r = crearBusqueda($yo, $_POST);
            $flash = [$r['ok'] ? 'success' : 'error', $r['mensaje']];
            $volver = 'busco.php';
            break;

        case 'busqueda_cerrar':
            cerrarBusqueda($pubId, $yo, esAdmin());
            $volver = 'busco.php';
            break;

        case 'busqueda_eliminar':
            eliminarBusqueda($pubId, $yo, esAdmin());
            $flash = ['success', 'Búsqueda eliminada.'];
            $volver = 'busco.php';
            break;
    }

} catch (OutOfBoundsException|LogicException|OverflowException|UnexpectedValueException $error) {
    $flash = ['error',$error->getMessage()];
}

if ($flash) {
    $_SESSION['flash'] = $flash;
}

$destino = $volver . $ancla;
if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'MusicMad-PJAX') {
    unset($_SESSION['flash']);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => !$flash || $flash[0] !== 'error', 'redirect' => rutaAplicacion($destino), 'flash' => $flash], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
header('Location: ' . $destino);
exit;
