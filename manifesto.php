<?php

require_once __DIR__ . '/includes/i18n.php';
require_once __DIR__ . '/includes/security_headers.php';
if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET','HEAD'], true)) {
    header('Allow: GET, HEAD');
    http_response_code(405);
    exit;
}
$locale = $_GET['lang'] ?? 'es';
if (!is_string($locale) || !array_key_exists($locale, MUSICMAD_LOCALES)) {
    http_response_code(404);
    exit;
}
$manifest = json_decode(file_get_contents(__DIR__ . '/manifest.json'), true, 32, JSON_THROW_ON_ERROR);
$manifest['lang'] = $locale;
$manifest['name'] = mmTexto('manifest.name', [], $locale);
$manifest['description'] = mmTexto('manifest.description', [], $locale);
if (defined('MUSICMAD_ROUTED') && MUSICMAD_ROUTED && defined('MUSICMAD_ROUTE_BASE')) {
    $manifest['id'] = MUSICMAD_ROUTE_BASE . 'musicmad';
    $manifest['start_url'] = MUSICMAD_ROUTE_BASE . 'feed?utm_source=pwa';
    $manifest['scope'] = MUSICMAD_ROUTE_BASE;
    $manifest['share_target']['action'] = MUSICMAD_ROUTE_BASE . 'compartir';
    foreach ($manifest['icons'] as &$icon) {
        $icon['src'] = MUSICMAD_ROUTE_BASE . ltrim($icon['src'], './');
    }
    unset($icon);
}
foreach ($manifest['shortcuts'] ?? [] as $index => $shortcut) {
    if (defined('MUSICMAD_ROUTED') && MUSICMAD_ROUTED && defined('MUSICMAD_ROUTE_BASE')) {
        $legacy = ltrim($shortcut['url'], './');
        $matched = MusicMad\Routing\resolve(MUSICMAD_ROUTE_BASE . $legacy, 'GET', MUSICMAD_ROUTE_BASE);
        if ($matched['status'] === 200) {
            $manifest['shortcuts'][$index]['url'] = MusicMad\Routing\pathFor($matched['route']['name'], [], MUSICMAD_ROUTE_BASE);
        }
        foreach ($manifest['shortcuts'][$index]['icons'] ?? [] as $i => $icon) {
            $manifest['shortcuts'][$index]['icons'][$i]['src'] = MUSICMAD_ROUTE_BASE . ltrim($icon['src'], './');
        }
    }
    foreach (['name','short_name'] as $key) {
        if (isset($shortcut[$key])) {
            $manifest['shortcuts'][$index][$key] = mmTexto($shortcut[$key], [], $locale);
        }
    }
    unset($manifest['shortcuts'][$index]['description']);
}
header('Content-Type: application/manifest+json; charset=utf-8');
header('Content-Language: ' . $locale);
header('Cache-Control: public, max-age=3600');
header('X-Content-Type-Options: nosniff');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') {
    echo json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}
