<?php

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/google_provider.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('Método no permitido.');
}
exigirCsrf();
$recordarOAuth = textoCampo($_POST, 'recordar') === '1';
$_SESSION['recordar_sesion_oauth'] = $recordarOAuth;

if (!googleOAuthConfigurado()) {
    $_SESSION['google_error'] = 'El inicio con Google no está configurado en este sitio.';
    header('Location: login.php');
    exit;
}

use League\OAuth2\Client\Provider\Google;

$provider = new MusicMadGoogleProvider([
    'clientId' => musicmadConfigValue('GOOGLE_CLIENT_ID'),
    'clientSecret' => musicmadConfigValue('GOOGLE_CLIENT_SECRET'),
    'redirectUri' => musicmadConfigValue('GOOGLE_REDIRECT_URI'),
]);

$authorizationUrl = $provider->getAuthorizationUrl([
    'scope' => [
        'openid',
        'email',
        'profile'
    ]
]);

$_SESSION['oauth2state'] = $provider->getState();
$_SESSION['oauth_pkce'] = $provider->getPkceCode();
$_SESSION['oauth_state_expires'] = time() + 600;

header('Location: ' . $authorizationUrl);
exit;
