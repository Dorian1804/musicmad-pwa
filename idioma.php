<?php

require_once __DIR__ . '/includes/auth.php';
header('Cache-Control: no-store');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit;
}
exigirCsrf();
$origin = $_SERVER['HTTP_ORIGIN'] ?? null;
$base = parse_url(APP_URL);
$expected = ($base['scheme'] ?? 'https') . '://' . ($base['host'] ?? '') . (isset($base['port']) ? ':' . $base['port'] : '');
if ((is_string($origin) && $origin !== $expected) || ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '') === 'cross-site') {
    http_response_code(403);
    exit;
}
$context = textoCampo($_POST, 'contexto');
$destinations = ['login' => 'login.php','registro' => 'registro.php','configuracion' => 'configuracion.php','bienvenida' => 'bienvenida.php'];
$locale = textoCampo($_POST, 'idioma');
if (!isset($destinations[$context]) || !array_key_exists($locale, MUSICMAD_LOCALES)) {
    http_response_code(422);
    exit;
}
if (estaAutenticado()) {
    if (!idiomaCuentaDisponible()) {
        http_response_code(503);
        exit(mmTexto('Error general'));
    }
    guardarIdiomaCuenta((int)sesion('usuario_id'), $locale);
}
$_SESSION['musicmad_locale'] = $locale;
$params = session_get_cookie_params();
setcookie('MUSICMAD_LANG', $locale, ['expires' => time() + 31536000,'path' => $params['path'],'secure' => $params['secure'],'httponly' => true,'samesite' => 'Lax']);
header('Location: ' . $destinations[$context], true, 303);
exit;
