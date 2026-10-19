<?php

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/social.php';
header('Cache-Control: private, no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit;
}
requerirSesion();
exigirCsrf();
$uid = (int)sesion('usuario_id');
$id = idCampo($_POST, 'id');
$action = textoCampo($_POST, 'accion');
try {
    accionEquipoPlaylist($uid, $id, $action, idCampo($_POST, 'musico'));
    $flash = ['success','Equipo de la playlist actualizado.'];
} catch (LogicException|OutOfBoundsException|OverflowException $e) {
    $flash = ['error',$e->getMessage()];
} catch (Throwable $e) {
    error_log('Playlist team: ' . get_class($e));
    $flash = ['error','No se pudo guardar. Inténtalo de nuevo.'];
}
$target = in_array($action, ['salir','rechazar'], true) ? 'playlist-equipo.php' : 'playlist-equipo.php?id=' . $id;
if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'MusicMad-PJAX') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => $flash[0] === 'success','flash' => $flash,'redirect' => $flash[0] === 'success' ? rutaAplicacion($target) : null]);
    exit;
}
$_SESSION['flash'] = $flash;
header('Location: ' . $target, true, 303);
