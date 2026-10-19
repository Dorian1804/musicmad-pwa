<?php

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/social.php';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    exit('Método no permitido.');
}
requerirSesion();
$titulo = mb_substr(trim(textoCampo($_GET, 'title')), 0, 120);
$texto = mb_substr(trim(textoCampo($_GET, 'text')), 0, 1000);
$url = trim(textoCampo($_GET, 'url'));
if ($url !== '' && (mb_strlen($url) > 300 || infoEnlace($url) === null)) {
    $url = '';
}
if ($titulo === '') {
    $titulo = $url !== '' ? 'Algo que quiero compartir' : 'Compartir desde otra app';
}
$_SESSION['share_draft'] = ['titulo' => $titulo, 'texto' => $texto, 'enlace' => $url];
header('Location: feed.php#compartir');
exit;
