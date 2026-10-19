<?php

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/social.php';
requerirSesion();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit;
}
exigirCsrf();
$uid = (int)sesion('usuario_id');
$target = 'clips.php';
$flash = null;
try {
    if (textoCampo($_POST, 'accion') === 'crear') {
        $id = crearClipMusical($uid, $_POST, textoCampo($_POST, 'solicitud'));
        $target = 'clip.php?id=' . $id;
    } elseif (textoCampo($_POST, 'accion') === 'retirar') {
        retirarClipMusical($uid, idCampo($_POST, 'id'));
    } else {
        throw new InvalidArgumentException('Acción no válida.');
    }
} catch (LogicException|OutOfBoundsException|OverflowException|UnexpectedValueException $e) {
    $flash = $_SESSION['flash'] = ['error',$e->getMessage()];
} catch (Throwable $e) {
    error_log('Clip action: ' . get_class($e));
    $flash = $_SESSION['flash'] = ['error','No pudimos crear el clip. El original se conserva.'];
}
if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'MusicMad-PJAX') {
    header('Content-Type: application/json; charset=utf-8');
    unset($_SESSION['flash']);
    echo json_encode(['ok' => $flash === null,'flash' => $flash,'redirect' => rutaAplicacion($target)]);
    exit;
}
header('Location: ' . $target, true, 303);
