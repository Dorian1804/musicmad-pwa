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
try {
    operacionCuentaConfirmada($uid, textoCampo($_POST, 'REPLACE_WITH_LOCAL_VALUE'), textoCampo($_POST, 'codigo'), function (PDO $db) use ($uid) {
        limiteChat($uid, 'exportacion', 2);
        auditarCuenta($uid, 'datos_exportados');
    });
    session_write_close();
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="MusicMad-mis-datos-' . gmdate('Y-m-d') . '.json"');
    header('Cache-Control: private, no-store, max-age=0');
    header('X-Content-Type-Options: nosniff');
    escribirExportacionCuenta($uid, static function (string $chunk) {
        echo $chunk;
    });
} catch (DomainException|OverflowException|LogicException $error) {
    if (headers_sent()) {
        error_log('Account export interrupted: ' . get_class($error));
        exit;
    }
    $_SESSION['flash'] = ['error',$error->getMessage()];
    header('Location: datos.php#exportar-datos', true, 303);
} catch (Throwable $error) {
    error_log('Account export unavailable: ' . get_class($error));
    if (!headers_sent()) {
        header_remove('Content-Disposition');
        http_response_code(503);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'No se pudo preparar la descarga. Inténtalo de nuevo.';
    }
}
