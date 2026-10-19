<?php

// ============================================================
//  MusicMad – Regreso de Google (OAuth2)
//  google-regreso.php
//  Google redirige aquí después de que el usuario acepta.
// ============================================================

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/google_provider.php';
require_once __DIR__ . '/includes/google_oauth_state.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    exit('Método no permitido.');
}

use League\OAuth2\Client\Provider\Google;

function volverAlLogin(string $mensaje): never
{
    unset($_SESSION['recordar_sesion_oauth'],$_SESSION['oauth2state'],$_SESSION['oauth_pkce'],$_SESSION['oauth_state_expires']);
    $_SESSION['google_error'] = $mensaje;
    header('Location: login.php');
    exit;
}

if (!googleOAuthConfigurado()) {
    volverAlLogin('El inicio con Google no está configurado en este sitio.');
}
if (!verificacionObligatoriaDisponible()) {
    volverAlLogin('Falta instalar la actualización de verificación de correo.');
}

// El usuario canceló o Google devolvió un error
if (isset($_GET['error'])) {
    volverAlLogin('Se canceló el inicio de sesión con Google.');
}

try {
    $verifier = consumirRetornoGoogle($_SESSION, $_GET, time());
} catch (DomainException $error) {
    volverAlLogin($error->getMessage());
}

$provider = new MusicMadGoogleProvider([
    'clientId' => musicmadConfigValue('GOOGLE_CLIENT_ID'),
    'clientSecret' => musicmadConfigValue('GOOGLE_CLIENT_SECRET'),
    'redirectUri' => musicmadConfigValue('GOOGLE_REDIRECT_URI'),
]);

$provider->setPkceCode($verifier);
try {
    $token = $provider->getAccessToken('authorization_code', [
        'code' => $_GET['code'],
    ]);
    if (!$token instanceof League\OAuth2\Client\Token\AccessToken) {
        throw new UnexpectedValueException('Respuesta OAuth no compatible.');
    }
    $google = $provider->getResourceOwner($token);
    if (!$google instanceof League\OAuth2\Client\Provider\GoogleUser) {
        throw new UnexpectedValueException('Perfil OAuth no compatible.');
    }
} catch (Throwable $e) {
    error_log('Error Google OAuth: ' . get_class($e));
    volverAlLogin('No se pudo iniciar sesión con Google. Revisa las credenciales.');
}

$email = trim((string) $google->getEmail());
if ($email === '') {
    volverAlLogin('Tu cuenta de Google no compartió el correo.');
}

$datos = $google->toArray();
if (($datos['email_verified'] ?? false) !== true) {
    volverAlLogin('El correo de tu cuenta de Google no está verificado.');
}

$nombre = trim((string) $google->getFirstName()) ?: 'Usuario';
$apellido = trim((string) $google->getLastName());

$pdo = getDB();
$stmt = $pdo->prepare(
    'SELECT id, nombre, apellido, avatar, bio, color_perfil, email, rol, activo
     FROM usuarios WHERE email = :email LIMIT 1'
);
$stmt->execute([':email' => $email]);
$u = $stmt->fetch();

if (!$u) {
    // Cuenta nueva: rol siempre "cliente". La contraseña es aleatoria
    // (la columna es NOT NULL) y nadie la conoce: entra solo con Google.
    $pdo->prepare(
        'INSERT INTO usuarios (nombre, apellido, avatar, bio, color_perfil, email, REPLACE_WITH_LOCAL_VALUE, rol)
         VALUES (:nombre, :apellido, :avatar, :bio, :color, :email, :REPLACE_WITH_LOCAL_VALUE, :rol)'
    )->execute([
        ':nombre' => $nombre,
        ':apellido' => $apellido,
        ':avatar' => AVATAR_DEFECTO,
        ':bio' => '',
        ':color' => COLOR_PERFIL_DEFECTO,
        ':email' => $email,
        ':REPLACE_WITH_LOCAL_VALUE' => REPLACE_WITH_LOCAL_VALUE_hash(bin2hex(random_bytes(32)), PASSWORD_BCRYPT, ['cost' => 12]),
        ':rol' => 'cliente',
    ]);

    $stmt->execute([':email' => $email]);
    $u = $stmt->fetch();
    if ($u) {
        iniciarOnboardingCuentaNueva((int)$u['id']);
    }
}

if (!$u['activo']) {
    volverAlLogin('Tu cuenta está desactivada. Contacta al administrador.');
}

$recordarOAuth = ($_SESSION['recordar_sesion_oauth'] ?? false) === true;
unset($_SESSION['recordar_sesion_oauth']);
$pdo->beginTransaction();
try {
    $lock = $pdo->prepare('SELECT * FROM usuarios WHERE id=? AND activo=1 FOR UPDATE');
    $lock->execute([$u['id']]);
    $current = $lock->fetch();
    if (!$current) {
        $pdo->rollBack();
        volverAlLogin('Cuenta no disponible.');
    }
    if (!verificacionObligatoriaDisponible()) {
        $pdo->rollBack();
        volverAlLogin('Falta instalar la actualización de verificación de correo.');
    }
    // Only the validated, server-to-server Google claim can confer this status.
    marcarCorreoGoogleVerificado((int) $current['id'], $email, ($datos['email_verified'] ?? false) === true);
    if (tieneSegundoFactor((int)$current['id'])) {
        prepararRetoAcceso((int)$current['id'], $recordarOAuth);
        $pdo->commit();
        header('Location: dos-pasos.php');
        exit;
    }
    completarAccesoUsuario($current, $recordarOAuth);
    $pdo->commit();
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Google access finalization: ' . get_class($error));
    volverAlLogin('No se pudo completar el acceso.');
}
$destino = destinoAccesoMusicMad((int)sesion('usuario_id'), (string)($_SESSION['destino_post_login'] ?? 'feed.php'));
if ($destino !== 'bienvenida.php' && $destino !== 'verificar-correo.php') {
    unset($_SESSION['destino_post_login']);
}
header('Location: ' . $destino);
exit;
