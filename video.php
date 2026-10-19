<?php

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/social.php';
requerirSesion();
$scopeMedia = textoCampo($_GET, '_scope');
if ($scopeMedia !== '' && !hash_equals((string) ($_SESSION['offline_scope'] ?? ''), $scopeMedia)) {
    http_response_code(403);
    exit('Sesión de reproducción no válida.');
}
if (!in_array($_SERVER['REQUEST_METHOD'] ?? '', ['GET','HEAD'], true)) {
    header('Allow: GET, HEAD');
    http_response_code(405);
    exit('Método no permitido.');
}
$id = idCampo($_GET, 'id');
$st = getDB()->prepare('SELECT pv.archivo,pv.formato,p.usuario_id FROM publicacion_video pv JOIN publicaciones p ON p.id=pv.publicacion_id JOIN usuarios u ON u.id=p.usuario_id AND u.activo=1 WHERE p.id=:id LIMIT 1');
$st->execute([':id' => $id]);
$video = $st->fetch();
if ($video && social3Disponible() && !puedeVerPublicacionSocial3((int) sesion('usuario_id'), $id)) {
    http_response_code(404);
    exit('Video no encontrado.');
}
if (!$video || !is_string($video['archivo']) || basename($video['archivo']) !== $video['archivo'] || str_contains($video['archivo'], '..') || !preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,70}\.(mp4|webm)$/i', $video['archivo']) || !in_array($video['formato'], ['mp4','webm'], true)) {
    http_response_code(404);
    exit('Video no encontrado.');
}
$videoDirectory = musicmadMediaDirectoryForFile('videos', $video['archivo']);
if (!is_file($videoDirectory . '/' . $video['archivo'])) {
    recuperarMedioDesdeRespaldo('videos', $video['archivo']);
}
$base = realpath($videoDirectory);
$path = realpath($videoDirectory . '/' . $video['archivo']);
if (is_link($videoDirectory) || $base === false || $path === false || dirname($path) !== $base || is_link($videoDirectory . '/' . $video['archivo']) || !is_file($path)) {
    http_response_code(404);
    exit('Video no encontrado.');
}
$size = filesize($path);
if ($size === false || $size < 1) {
    http_response_code(404);
    exit('Video no encontrado.');
}
$start = 0;
$end = $size - 1;
$status = 200;
$range = (string)($_SERVER['HTTP_RANGE'] ?? '');
if ($range !== '') {
    if (!preg_match('/^bytes=(\d*)-(\d*)$/', trim($range), $m) || ($m[1] === '' && $m[2] === '')) {
        http_response_code(416);
        header('Content-Range: bytes */' . $size);
        exit;
    }
    if ($m[1] === '') {
        $suffix = (int)$m[2];
        if ($suffix < 1) {
            http_response_code(416);
            header('Content-Range: bytes */' . $size);
            exit;
        }
        $start = max(0, $size - $suffix);
    } else {
        $start = (int)$m[1];
        $end = $m[2] === '' ? $size - 1 : min($size - 1, (int)$m[2]);
    }
    if ($start >= $size || $end < $start) {
        http_response_code(416);
        header('Content-Range: bytes */' . $size);
        exit;
    }
    $status = 206;
}
$length = $end - $start + 1;
http_response_code($status);
header('Content-Type: ' . ($video['formato'] === 'webm' ? 'video/webm' : 'video/mp4'));
header('Content-Disposition: inline; filename="musicmad-video-' . $id . '.' . $video['formato'] . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
header('Accept-Ranges: bytes');
header('Content-Length: ' . $length);
if ($status === 206) {
    header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') {
    exit;
}
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}
$handle = fopen($path, 'rb');
if (!$handle || fseek($handle, $start) !== 0) {
    if ($handle) {
        fclose($handle);
    }
    http_response_code(500);
    exit;
}
// Bloques más grandes reducen el número de lecturas para videos largos.
while ($length > 0 && !feof($handle)) {
    $chunk = fread($handle, min(262144, $length));
    if ($chunk === false || $chunk === '') {
        break;
    }
    echo $chunk;
    $length -= strlen($chunk);
}
fclose($handle);
