<?php

declare(strict_types=1);

namespace MusicMad\Routing;

require_once __DIR__ . '/paths.php';

/** Validate a return target; a trusted public HTTPS origin is handled separately. */
function destination(string $value, string $fallback = '/feed', string $base = '/'): string
{
    validateBase($base);
    $safeFallback = returnTarget($fallback, $base) ?? pathFor('feed', [], $base);
    return returnTarget($value, $base) ?? $safeFallback;
}

function returnTarget(string $value, string $base): ?string
{
    if ($value === '' || strlen($value) > 1800 || str_contains($value, '\\')
        || preg_match('/[\x00-\x1f\x7f]/', $value) || str_starts_with($value, '//')
        || preg_match('~^[a-z][a-z0-9+.-]*:~i', $value)) {
        return null;
    }
    [$target, $fragment] = array_pad(explode('#', $value, 2), 2, '');
    if ($fragment !== '' && !preg_match('/^[a-zA-Z0-9_-]{1,80}$/D', $fragment)) {
        return null;
    }
    $target = str_replace(' ', '%20', $target);
    $uri = str_starts_with($target, '/') ? $target : $base . $target;
    $result = resolve($uri, 'GET', $base);
    if ($result['status'] !== 200 || !$result['route']['returnable']) {
        return null;
    }
    $entry = $result['route'];
    $params = $result['params'];
    if ($result['legacy'] && isset($entry['resource'])) {
        if ($entry['resource'] !== 'alias' || !isset($result['query']['usuario'])) {
            // Conversion of an internal numeric ID requires an authorized server lookup.
            return null;
        }
        $params['alias'] = $result['query']['usuario'];
    }
    try {
        $path = pathFor($entry['name'], $params, $base);
    } catch (\InvalidArgumentException | \OutOfBoundsException $error) {
        return null;
    }
    $args = [];
    foreach ($result['query'] as $key => $content) {
        if (in_array($key, $entry['return_query'], true) && strlen($content) <= 500) {
            $args[$key] = $content;
        }
    }
    if (($entry['resource'] ?? null) === 'alias') {
        unset($args['usuario']);
    }
    return $path . ($args ? '?' . http_build_query($args, '', '&', PHP_QUERY_RFC3986) : '')
        . ($fragment !== '' ? '#' . $fragment : '');
}
