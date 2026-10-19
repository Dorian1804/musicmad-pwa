<?php

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/social.php';
header('Content-Type: text/css; charset=utf-8');
header('Cache-Control: private, no-store');
if (!in_array($_SERVER['REQUEST_METHOD'] ?? '', ['GET','HEAD'], true)) {
    header('Allow: GET, HEAD');
    http_response_code(405);
    exit;
}
if (!estaAutenticado()) {
    http_response_code(401);
    exit;
}
$raw = textoCampo($_GET, 'ids');
if (!preg_match('/^\\d+(,\\d+){0,99}$/', $raw)) {
    http_response_code(400);
    exit;
}
$ids = array_values(array_unique(array_filter(array_map('intval', explode(',', $raw)))));
if (!$ids) {
    http_response_code(400);
    exit;
}
$marks = implode(',', array_fill(0, count($ids), '?'));
$st = getDB()->prepare('SELECT id,color_perfil FROM usuarios WHERE activo=1 AND id IN (' . $marks . ')');
$st->execute($ids);
$basePath = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
foreach ($st->fetchAll() as $row) {
    $id = (int) $row['id'];
    if (!puedeVerPerfilSocial3((int) sesion('usuario_id'), $id)) {
        continue;
    }
    $color = preg_match('/^#[a-f0-9]{6}$/i', (string) $row['color_perfil']) ? $row['color_perfil'] : '#190d22';
    $cover = obtenerPortadaPerfil($id);
    if (!preg_match('#^(img/portadas/portada-0[1-6]\\.svg|uploads/covers/[0-9]+_[a-f0-9]{24}\\.jpg)$#', $cover)) {
        $cover = 'img/portadas/portada-01.svg';
    }
    $publicCover = str_starts_with($cover, 'uploads/covers/') ? musicmadPublicImageUrl($cover) : $basePath . '/' . $cover;
    if ($_SERVER['REQUEST_METHOD'] === 'HEAD') {
        continue;
    }
    echo '[data-profile-style="' . $id . '"]{--post-color:' . $color . ';--cover-color:' . $color . ';--banner-color:' . $color
        . ';--cover-image:url("' . $publicCover . '");--banner-image:url("' . $publicCover . '");}' . "\n";
}
