<?php

/** Standalone responses must work without a database or authenticated session. */
function normalizarBaseErrorMusicMad(string $base): string
{
    $base = preg_replace('~/api/v1/?$~', '', str_replace('\\\\', '/', $base));
    $base = rtrim($base, '/') . '/';
    if (!preg_match('~^/(?:[A-Za-z0-9._-]+/)*$~D', $base) || preg_match('~(?:^|/)[.]{1,2}(?:/|$)~', $base)) {
        return '/';
    }
    return $base;
}
function baseErrorMusicMad(): string
{
    $configured = defined('APP_URL') ? (string)APP_URL : (string)getenv('MM_APP_URL');
    $base = $configured !== '' ? (string)parse_url($configured, PHP_URL_PATH) : str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/')));
    return normalizarBaseErrorMusicMad($base);
}
function errorMusicMadEsJson(): bool
{
    if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'MusicMad-PJAX' || str_contains(strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? '')), 'application/json')) {
        return true;
    }
    foreach (headers_list() as $header) {
        if (preg_match('~^Content-Type:\s*application/(?:[a-z0-9.+-]*\+)?json~i', $header)) {
            return true;
        }
    }
    return false;
}
function responderErrorMusicMad(int $code = 500): never
{
    static $responding = false;
    if ($responding) {
        exit('MusicMad no está disponible por el momento.');
    }
    $responding = true;
    $code = $code === 404 ? 404 : 500;
    while (ob_get_level() > 0) {
        if (!@ob_end_clean()) {
            break;
        }
    }
    if (!headers_sent()) {
        http_response_code($code);
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: no-referrer');
        header('X-Frame-Options: DENY');
        header("Content-Security-Policy: default-src 'none'; base-uri 'none'; object-src 'none'; style-src 'self'; img-src 'self'; frame-ancestors 'none'; form-action 'none'");
    }
    if (errorMusicMadEsJson()) {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode(['ok' => false,'error' => $code === 404 ? 'not_found' : 'temporarily_unavailable','flash' => ['error',$code === 404 ? 'Esta página no está disponible.' : 'No se pudo completar. Conserva tu formulario y vuelve a intentar.'],'redirect' => null]);
        exit;
    }
    if (!headers_sent()) {
        header('Content-Type: text/html; charset=utf-8');
    }
    $base = baseErrorMusicMad();
    $errorTitle = $code === 404 ? 'Esta página cambió de ritmo.' : 'Hagamos una pausa.';
    $errorText = $code === 404 ? 'El enlace no está disponible o la página se movió. Puedes volver a tu escena.' : 'MusicMad no pudo responder en este momento. Intenta nuevamente en un momento. Puedes volver a tu escena o escuchar tu biblioteca guardada.';
    require __DIR__ . '/error_view.php';
    exit;
}
if (PHP_SAPI !== 'cli') {
    ini_set('display_errors', '0');
    set_exception_handler(function (Throwable $error): void {
        error_log('MusicMad uncaught error: ' . get_class($error));
        responderErrorMusicMad(500);
    });
    $musicmadEmergencyBuffer = str_repeat('x', 65536);
    register_shutdown_function(function () use (&$musicmadEmergencyBuffer): void {
        $last = error_get_last();
        if (!$last || !in_array($last['type'], [E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR,E_USER_ERROR], true)) {
            return;
        }
        $musicmadEmergencyBuffer = null;
        error_log('MusicMad fatal error type: ' . (int)$last['type']);
        responderErrorMusicMad(500);
    });
}
