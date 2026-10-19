<?php

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/social.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit;
}
if (!estaAutenticado() && ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'MusicMad-PJAX') {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(['ok' => false,'flash' => ['error','Inicia sesión para continuar.'],'redirect' => null]);
    exit;
}
requerirSesion();
exigirCsrf();
$uid = (int)sesion('usuario_id');
$action = textoCampo($_POST, 'accion');
$target = idCampo($_POST, 'usuario');
$redirect = 'recomendaciones.php?usuario=' . (in_array($action, ['ocultar','mostrar'], true) ? $uid : $target);
if ($action === 'retirar' && textoCampo($_POST, 'origen') === 'enviadas') {
    $redirect = 'recomendaciones.php?usuario=' . $uid . '&ver=enviadas';
}
try {
    accionRecomendacion($uid, $target, $action, textoCampo($_POST, 'texto'), textoCampo($_POST, 'solicitud'));
    $flash = ['success','Listo. Tu recomendación quedó actualizada.'];
} catch (LogicException|OutOfBoundsException|UnexpectedValueException|OverflowException $e) {
    $flash = ['error',$e->getMessage()];
} catch (Throwable $e) {
    error_log('Endorsement action: ' . get_class($e));
    $flash = ['error','No se pudo completar. Vuelve a intentarlo.'];
}
if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'MusicMad-PJAX') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(['ok' => $flash[0] === 'success','flash' => $flash,'redirect' => rutaAplicacion($redirect)]);
    exit;
}
$_SESSION['flash'] = $flash;
header('Location: ' . $redirect, true, 303);
