<?php

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/social.php';
if (!in_array($_SERVER['REQUEST_METHOD'], ['GET','HEAD'], true)) {
    header('Allow: GET, HEAD');
    http_response_code(405);
    exit('Método no permitido.');
}
exigirLimiteLecturaPortafolio();
$viewer = estaAutenticado() ? (int)sesion('usuario_id') : 0;
if (!pistaPortafolioPublica(idCampo($_GET, 'id'), $viewer)) {
    http_response_code(404);
    exit('Pista no disponible.');
}
define('MUSICMAD_PUBLIC_AUDIO', true);
require __DIR__ . '/audio.php';
