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
try {
    actualizarOnboarding($uid, $_POST);
    $flash = ['success',''];
} catch (LogicException|OutOfBoundsException|UnexpectedValueException|OverflowException $e) {
    $flash = ['error',mmTexto($e->getMessage())];
} catch (Throwable $e) {
    error_log('Onboarding action: ' . get_class($e));
    $flash = ['error',mmTexto('No se pudo guardar. Tus datos anteriores se conservan.')];
}
$state = estadoOnboarding($uid);
$target = destinoAccesoMusicMad($uid, (string)($_SESSION['destino_post_login'] ?? 'feed.php'));
if (onboardingCompleto($uid)) {
    unset($_SESSION['destino_post_login']);
}
if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'MusicMad-PJAX') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(['ok' => $flash[0] === 'success','flash' => $flash[0] === 'error' ? $flash : null,'redirect' => $target]);
    exit;
}
if ($flash[0] === 'error') {
    $_SESSION['flash'] = $flash;
} else {
    unset($_SESSION['flash']);
}
header('Location: ' . $target, true, 303);
