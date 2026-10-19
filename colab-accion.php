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
$id = idCampo($_POST, 'id');
$target = 'colabs.php';
$flash = null;
try {
    $action = textoCampo($_POST, 'accion');
    if ($action === 'crear') {
        $id = crearColaboracion($uid, $_POST);
        $target = 'colab.php?id=' . $id;
    } elseif ($action === 'aportar') {
        $stem = aportarStemColab($uid, $id, $_POST, textoCampo($_POST, 'solicitud'));
        $target = 'colab.php?id=' . $id . '#stem-' . $stem;
    } elseif ($action === 'estado') {
        cambiarEstadoColab($uid, $id, textoCampo($_POST, 'estado'), idCampo($_POST, 'final'));
        $target = textoCampo($_POST, 'estado') === 'archivada' ? 'colabs.php' : 'colab.php?id=' . $id;
    } else {
        throw new InvalidArgumentException('Acción no válida.');
    }
} catch (LogicException|OutOfBoundsException|OverflowException|UnexpectedValueException $e) {
    $flash = $_SESSION['flash'] = ['error',$e->getMessage()];
} catch (Throwable $e) {
    error_log('Collaboration action: ' . get_class($e));
    $flash = $_SESSION['flash'] = ['error','No se pudo completar. Tu pista original se conserva.'];
}
if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'MusicMad-PJAX') {
    header('Content-Type: application/json; charset=utf-8');
    unset($_SESSION['flash']);
    echo json_encode(['ok' => $flash === null,'flash' => $flash,'redirect' => rutaAplicacion($target)]);
    exit;
}
header('Location: ' . $target, true, 303);
