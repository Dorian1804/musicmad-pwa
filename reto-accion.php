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
$id = idCampo($_POST, 'id');
$target = $id ? 'reto.php?id=' . $id : 'retos.php';
$flash = null;
try {
    $action = textoCampo($_POST, 'accion');
    $request = textoCampo($_POST, 'solicitud');
    if ($action === 'crear') {
        $id = crearReto($uid, $_POST, $request);
        $target = 'reto.php?id=' . $id;
    } elseif ($action === 'participar') {
        participarReto($uid, $id, idCampo($_POST, 'pista'), $request);
    } elseif ($action === 'votar') {
        votarReto($uid, $id, idCampo($_POST, 'participante'), $request);
    } elseif ($action === 'retirar') {
        retirarParticipacionReto($uid, $id, $request);
    } elseif (in_array($action, ['cancelar','publicar'], true)) {
        administrarReto($uid, $id, $action);
    } elseif ($action === 'programar') {
        configurarRetosSemanales($uid, textoCampo($_POST, 'habilitado') === '1');
        $target = 'retos.php';
    } else {
        throw new InvalidArgumentException('Acción no válida.');
    }
    $flash = ['success','Listo. El reto quedó actualizado.'];
} catch (LogicException|OutOfBoundsException|OverflowException|UnexpectedValueException $e) {
    $flash = ['error',$e->getMessage()];
} catch (Throwable $e) {
    error_log('Challenge action: ' . get_class($e));
    $flash = ['error','No se pudo completar. Vuelve a intentarlo.'];
}
if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'MusicMad-PJAX') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(['ok' => $flash[0] === 'success','flash' => $flash,'redirect' => rutaAplicacion($target)]);
    exit;
}
$_SESSION['flash'] = $flash;
header('Location: ' . $target, true, 303);
