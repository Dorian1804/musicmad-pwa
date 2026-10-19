<?php

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/social.php';
requerirSesion();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('Método no permitido.');
}
exigirCsrf();
$uid = (int)sesion('usuario_id');
$id = idCampo($_POST, 'id');
$destination = 'salas.php';
$flash = null;
try {
    $action = textoCampo($_POST, 'accion');
    if ($action === 'crear') {
        $id = crearSalaEscucha($uid, $_POST);
        $destination = 'sala.php?id=' . $id;
    } elseif ($action === 'entrar') {
        entrarSalaEscucha($uid, $id, textoCampo($_POST, 'codigo'));
        $destination = 'sala.php?id=' . $id;
    } elseif ($action === 'salir') {
        cerrarOSalirSala($uid, $id, false);
    } elseif ($action === 'cerrar') {
        cerrarOSalirSala($uid, $id, true);
    } elseif ($action === 'mensaje') {
        enviarMensajeSala($uid, $id, textoCampo($_POST, 'texto'), textoCampo($_POST, 'solicitud'));
        $destination = 'sala.php?id=' . $id;
    } else {
        throw new InvalidArgumentException('Acción no válida.');
    }
} catch (InvalidArgumentException|LogicException|OverflowException|OutOfBoundsException $error) {
    $flash = $_SESSION['flash'] = ['error',$error->getMessage()];
} catch (Throwable $error) {
    error_log('Listening room action: ' . get_class($error));
    $flash = $_SESSION['flash'] = ['error','No se pudo completar el cambio. Inténtalo de nuevo.'];
}
if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'MusicMad-PJAX') {
    header('Content-Type: application/json; charset=utf-8');
    unset($_SESSION['flash']);
    echo json_encode(['ok' => $flash === null,'flash' => $flash,'redirect' => rutaAplicacion($destination)]);
    exit;
}
header('Location: ' . $destination, true, 303);
