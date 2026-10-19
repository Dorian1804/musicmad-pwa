<?php

// Legacy URLs remain valid while images are stored outside the application folder.
require_once __DIR__ . '/config/database.php';
header('Cache-Control: no-store');
if (!in_array($_SERVER['REQUEST_METHOD'] ?? '', ['GET','HEAD'], true)) {
    header('Allow: GET, HEAD');
    http_response_code(405);
    exit;
}
$kind = $_GET['kind'] ?? null;
$name = $_GET['name'] ?? null;
if (!is_string($kind) || !is_string($name) || !in_array($kind, ['avatars','covers','market'], true)
    || !musicmadMediaNameValid($kind, $name)) {
    http_response_code(404);
    exit;
}
try {
    if (!musicmadImageIsReferenced($kind, $name)) {
        http_response_code(404);
        exit;
    }
    $directory = musicmadMediaDirectoryForFile($kind, $name);
    header('X-MusicMad-Storage: ' . (defined('APP_UPLOAD_ROOT') ? 'configured' : 'persistent-v1'));
    $base = realpath($directory);
    $path = realpath($directory . '/' . $name);
    if ($base === false || $path === false || dirname($path) !== $base || is_link($directory) || is_link($directory . '/' . $name) || !is_file($path)) {
        http_response_code(404);
        exit;
    }
    // These are server re-encoded JPEGs, never arbitrary uploads or SVG/HTML.
    if ((new finfo(FILEINFO_MIME_TYPE))->file($path) !== 'image/jpeg') {
        http_response_code(404);
        exit;
    }
    // Protect existing photos too, without hiding a readable image if backup storage is down.
    try {
        $copy = carpetaRespaldoMedios($kind) . '/' . $name;
        if (!is_file($copy) || is_link($copy)) {
            respaldarMedioGuardado($kind, $name);
        }
    } catch (RespaldoMedioException $error) {
        error_log('Image backup pending: ' . get_class($error));
    }
    header('Content-Type: image/jpeg');
    header('X-Content-Type-Options: nosniff');
    header('Content-Length: ' . filesize($path));
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'HEAD') {
        readfile($path);
    }
} catch (Throwable $error) {
    error_log('Persistent image storage unavailable: ' . get_class($error));
    http_response_code(503);
}
