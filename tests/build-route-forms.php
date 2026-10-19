<?php

if (PHP_SAPI !== 'cli') {
    exit(2);
}
require dirname(__DIR__) . '/includes/routing/catalogue.php';
$routes = [];
foreach (MusicMad\Routing\catalogue() as $route) {
    foreach ($route['legacy'] as $old) {
        if (!isset($route['resource']) && !str_contains($route['path'] ?? '', '{')) {
            $routes[$old] = $route;
        }
    }
}
$root = dirname(__DIR__);
$changed = [];
foreach (json_decode(file_get_contents(__DIR__ . '/quality-files.json'), true) as $name) {
    if (str_starts_with($name, 'config/') || str_starts_with($name, 'bin/') || str_starts_with($name, 'api/')) {
        continue;
    }
    $file = $root . '/' . $name;
    $text = file_get_contents($file);
    $out = '';
    foreach (token_get_all($text) as $token) {
        if (is_array($token) && $token[0] === T_INLINE_HTML) {
            $out .= preg_replace_callback('~<form\b[^>]*>~i', static function ($form) use ($routes) {
                $method = preg_match('~\bmethod="(GET|POST)"~i', $form[0], $m) ? strtoupper($m[1]) : 'GET';
                return preg_replace_callback('~\baction="([a-z][a-z-]*[.]php)"~', static function ($action) use ($method, $routes) {
                    if (!isset($routes[$action[1]]) || !in_array($method, $routes[$action[1]]['methods'], true)) {
                        return $action[0];
                    }
                    return 'action="<?= e(rutaAplicacion(' . var_export($action[1], true) . ', ' . var_export($method, true) . ')) ?>"';
                }, $form[0]);
            }, $token[1]);
        } else {
            $out .= is_array($token) ? $token[1] : $token;
        }
    }
    if ($out !== $text) {
        file_put_contents($file, str_replace("\r\n", "\n", $out));
        $changed[] = $name;
    }
}
echo json_encode($changed);
