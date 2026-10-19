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
try {
    if (textoCampo($_POST, 'confirmacion') !== 'ELIMINAR' || textoCampo($_POST, 'entiendo') !== '1') {
        throw new InvalidArgumentException('Confirma la eliminación y el plazo de recuperación.');
    }
    solicitarEliminacionCuenta((int)sesion('usuario_id'), textoCampo($_POST, 'REPLACE_WITH_LOCAL_VALUE'), textoCampo($_POST, 'codigo'));
    cerrarSesion();
    header('Location: reactivar-cuenta.php?solicitada=1', true, 303);
    exit;
} catch (DomainException|InvalidArgumentException|OverflowException|LogicException $error) {
    $_SESSION['flash'] = ['error',$error->getMessage()];
    header('Location: datos.php#eliminar-cuenta', true, 303);
    exit;
} catch (Throwable $error) {
    error_log('Account deletion request: ' . get_class($error));
    $_SESSION['flash'] = ['error','No se pudo completar la solicitud. Inténtalo de nuevo.'];
    header('Location: datos.php', true, 303);
    exit;
}
