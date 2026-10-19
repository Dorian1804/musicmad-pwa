<?php

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/social.php';
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit;
}
requerirSesion();
exigirCsrf();
$yo = (int)sesion('usuario_id');
$id = idCampo($_POST, 'id');
$action = textoCampo($_POST, 'accion');
$target = 'eventos.php?ver=agenda';
$error = '';
$message = 'Cambios guardados.';
try {
    if (!eventosWorkspaceDisponible()) {
        throw new RuntimeException('Actualización pendiente.');
    }
    switch ($action) {
        case 'crear':$id = guardarEventoWorkspace($yo, $_POST);
            $message = 'Evento publicado. Tu cartel y calendario están listos.';
            break;
        case 'editar':guardarEventoWorkspace($yo, $_POST, $id);
            $message = 'Evento actualizado. Avisaremos a quienes respondieron.';
            break;
        case 'rsvp':responderEventoWorkspace($yo, $id, textoCampo($_POST, 'estado'), textoCampo($_POST, 'aviso') === '1');
            $message = 'Tu respuesta quedó guardada.';
            break;
        case 'quitar':responderEventoWorkspace($yo, $id, 'quitar', false);
            $message = 'Quitamos tu respuesta y recordatorios.';
            break;
        case 'cancelar':cancelarEventoWorkspace($yo, $id);
            $message = 'Evento cancelado. Avisaremos a quienes respondieron.';
            break;
        default:throw new InvalidArgumentException('Acción no válida.');
    }
} catch (OutOfBoundsException $e) {
    http_response_code(404);
    $error = $e->getMessage();
} catch (LogicException|OverflowException $e) {
    http_response_code(422);
    $error = $e->getMessage();
} catch (Throwable $e) {
    http_response_code(503);
    error_log('Event action: ' . get_class($e));
    $error = 'No pudimos guardar. Conserva tu formulario y reintenta.';
}
$target .= '#evento-' . $id;
$flash = [$error ? 'error' : 'success',$error ?: $message];
if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'MusicMad-PJAX') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => $error === '','redirect' => $target,'flash' => $flash], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
$_SESSION['flash'] = $flash;
header('Location: ' . $target, true, 303);
exit;
