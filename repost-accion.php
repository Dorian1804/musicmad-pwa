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
try {
    guardarRepostComentario($uid, $id, textoCampo($_POST, 'accion'), textoCampo($_POST, 'texto'), textoCampo($_POST, 'solicitud'));
    $flash = ['success','Tu publicación compartida se actualizó.'];
} catch (LogicException|OutOfBoundsException|OverflowException|UnexpectedValueException $e) {
    $flash = ['error',$e->getMessage()];
} catch (Throwable $e) {
    error_log('Repost comment: ' . get_class($e));
    $flash = ['error','No se pudo guardar. Conserva tu comentario e inténtalo de nuevo.'];
}
$target = 'musico.php?id=' . $uid;
if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'MusicMad-PJAX') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => $flash[0] === 'success','flash' => $flash,'redirect' => $flash[0] === 'success' ? rutaAplicacion($target) : null]);
    exit;
}
$_SESSION['flash'] = $flash;
header('Location: ' . $target, true, 303);
