<?php

declare(strict_types=1);

require_once __DIR__ . '/paths.php';

/** A base element must never send fragment links to the application landing page. */
function anclaAplicacion(string $fragment): string
{
    if (!preg_match('/^#[A-Za-z0-9_-]{1,80}$/D', $fragment)) {
        return '#';
    }
    if (!defined('MUSICMAD_ROUTE_CANONICAL')) {
        return $fragment;
    }
    $parts = parse_url(MUSICMAD_ROUTE_CANONICAL);
    $query = MusicMad\Routing\query($parts['query'] ?? '') ?? [];
    unset($query['fragmento'], $query['cursor']);
    return $parts['path'] . ($query ? '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986) : '') . $fragment;
}

/** Addresses are generated from the same allowlist; referenced resources are authorized first. */
function rutaAplicacion(string $value, string $method = 'GET'): string
{
    if (!defined('MUSICMAD_ROUTED') || MUSICMAD_ROUTED !== true || !defined('MUSICMAD_ROUTE_BASE')) {
        return $value;
    }
    $base = MUSICMAD_ROUTE_BASE;
    $missing = $base . 'error/404';
    if (strlen($value) > 9000 || preg_match('/[\x00-\x1f\x7f\\\\]/', $value)) {
        return $missing;
    }
    [$path, $fragment] = array_pad(explode('#', $value, 2), 2, '');
    if ($fragment !== '' && !preg_match('/^[A-Za-z0-9_-]{1,80}$/D', $fragment)) {
        return $missing;
    }
    if (str_starts_with($path, rtrim(APP_URL, '/') . '/')) {
        $path = substr($path, strlen(rtrim(APP_URL, '/') . '/'));
    }
    if (!str_starts_with($path, '/')) {
        $path = $base . $path;
    }
    try {
        $match = MusicMad\Routing\resolve($path, $method, $base);
        if ($match['status'] !== 200) {
            return $missing;
        }
        $route = $match['route'];
        if (isset($route['resource']) || in_array($route['name'], ['portafolio', 'embed', 'pista-publica', 'vista-publica'], true)
            || ($route['name'] === 'mensajes' && isset($match['query']['con']))
            || ($route['name'] === 'playlist-equipo' && isset($match['query']['id']))) {
            require_once __DIR__ . '/request_plan.php';
            $viewer = estaAutenticado() ? (int)sesion('usuario_id') : 0;
            $plan = (new MusicMad\Routing\RequestPlan(getDB(), $viewer))->resolve($path, $method, [], $base);
            $address = $plan['status'] === 200 ? $plan['canonical'] : $missing;
        } else {
            $address = MusicMad\Routing\pathFor($route['name'], $match['params'], $base);
            if ($match['query']) {
                $address .= '?' . http_build_query($match['query'], '', '&', PHP_QUERY_RFC3986);
            }
        }
        return $address . ($fragment !== '' ? '#' . $fragment : '');
    } catch (Throwable $error) {
        error_log('MusicMad route URL: ' . get_class($error));
        return $missing;
    }
}
