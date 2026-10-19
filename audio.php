<?php

// Audio de publicaciones: acceso autenticado y lectura por rangos para móviles.
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/social.php';
$publicAudio = defined('MUSICMAD_PUBLIC_AUDIO') && MUSICMAD_PUBLIC_AUDIO === true && pistaPortafolioPublica(idCampo($_GET, 'id'), estaAutenticado() ? (int)sesion('usuario_id') : 0);
if (!$publicAudio) {
    requerirSesion();
}
$scopeMedia = textoCampo($_GET, '_scope');
if ($scopeMedia !== '' && !hash_equals((string) ($_SESSION['offline_scope'] ?? ''), $scopeMedia)) {
    http_response_code(403);
    exit('Sesión de reproducción no válida.');
}
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method, ['GET','HEAD'], true)) {
    header('Allow: GET, HEAD');
    http_response_code(405);
    exit('Método no permitido.');
}
$id = idCampo($_GET, 'id');
$st = getDB()->prepare('SELECT pa.archivo,p.usuario_id FROM publicacion_audio pa JOIN publicaciones p ON p.id=pa.publicacion_id JOIN usuarios u ON u.id=p.usuario_id AND u.activo=1 WHERE p.id=:id LIMIT 1');
$st->execute([':id' => $id]);
$audio = $st->fetch();
$archivo = $audio['archivo'] ?? false;
if ($audio && !$publicAudio && social3Disponible() && !puedeVerPublicacionSocial3((int) sesion('usuario_id'), $id)) {
    http_response_code(404);
    exit('Audio no encontrado.');
}
$audioPropio = $audio && !$publicAudio && (int)$audio['usuario_id'] === (int)sesion('usuario_id');
if (!is_string($archivo) || basename($archivo) !== $archivo || str_contains($archivo, '..') || !preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,70}\.(mp3|m4a|ogg|wav)$/i', $archivo)) {
    if ($audioPropio) {
        header('X-MusicMad-Audio-Status: invalid-record');
    }
    http_response_code(404);
    exit('Audio no encontrado.');
}
$audioDirectory = musicmadMediaDirectoryForFile('audio', $archivo);
if (!is_file($audioDirectory . '/' . $archivo)) {
    recuperarMedioDesdeRespaldo('audio', $archivo);
}
$base = realpath($audioDirectory);
$path = realpath($audioDirectory . '/' . $archivo);
if (is_link($audioDirectory) || $base === false || $path === false || dirname($path) !== $base || !is_file($path) || is_link($audioDirectory . '/' . $archivo)) {
    if ($audioPropio && $path === false && !is_file($audioDirectory . '/' . $archivo)) {
        header('X-MusicMad-Audio-Status: missing-file');
    }
    http_response_code(404);
    exit('Audio no encontrado.');
}
$ext = strtolower(pathinfo($archivo, PATHINFO_EXTENSION));
$mime = ['mp3' => 'audio/mpeg','m4a' => 'audio/mp4','ogg' => 'audio/ogg','wav' => 'audio/wav'][$ext];
$size = filesize($path);
if ($size === false || $size < 1) {
    http_response_code(404);
    exit('Audio no encontrado.');
}
$start = 0;
$end = $size - 1;
$status = 200;
$range = trim((string)($_SERVER['HTTP_RANGE'] ?? ''));
if ($range !== '') {
    if (!preg_match('/^bytes=(\d*)-(\d*)$/', $range, $m) || ($m[1] === '' && $m[2] === '')) {
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
header('Content-Type: ' . $mime);
header('Content-Disposition: inline; filename="musicmad-audio-' . $id . '.' . $ext . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
header('Accept-Ranges: bytes');
header('Content-Length: ' . $length);
if ($status === 206) {
    header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
}
if ($method === 'HEAD') {
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
while ($length > 0 && !feof($handle)) {
    $chunk = fread($handle, min(262144, $length));
    if ($chunk === false || $chunk === '') {
        break;
    }
    echo $chunk;
    $length -= strlen($chunk);
}
fclose($handle);
