<?php

declare(strict_types=1);

namespace MusicMad\Routing;

require_once __DIR__ . '/catalogue.php';

/** Resolve the deployment directory from server configuration, never from Host. */
function basePath(string $scriptName, string $configuredAppUrl = ''): string
{
    if ($configuredAppUrl !== '') {
        $parts = parse_url($configuredAppUrl);
        if ($parts === false || filter_var($configuredAppUrl, FILTER_VALIDATE_URL) === false || !isset($parts['scheme'], $parts['host'])
            || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])
            || !in_array($parts['scheme'], ['http', 'https'], true)
            || ($parts['scheme'] === 'http' && !in_array($parts['host'], ['localhost', '127.0.0.1', '[::1]'], true))) {
            throw new \InvalidArgumentException('Invalid application base.');
        }
        $path = $parts['path'] ?? '/';
    } else {
        if (!preg_match('~^/[A-Za-z0-9_./-]+[.]php$~D', $scriptName)) {
            throw new \InvalidArgumentException('Invalid application base.');
        }
        $path = str_ends_with($scriptName, '/api/v1/index.php')
            ? substr($scriptName, 0, -strlen('/api/v1/index.php')) : substr($scriptName, 0, (int) strrpos($scriptName, '/'));
    }
    if ($path === '' || $path === '/') {
        return '/';
    }
    if (str_contains($path, '//')) {
        throw new \InvalidArgumentException('Invalid application base.');
    }
    return validateBase(rtrim($path, '/') . '/');
}

function validateBase(string $base): string
{
    if (strlen($base) > 512 || !preg_match('~^/(?:[A-Za-z0-9_-]+/)*$~D', $base)) {
        throw new \InvalidArgumentException('Invalid application base.');
    }
    return $base;
}

/** Duplicates, arrays and control characters cannot acquire different meanings downstream. */
function query(string $raw): ?array
{
    if (strlen($raw) > 8192 || preg_match('/[\x00-\x20\x7f]/', $raw)) {
        return null;
    }
    if ($raw === '') {
        return [];
    }
    $pairs = explode('&', $raw);
    if (count($pairs) > 40) {
        return null;
    }
    $out = [];
    foreach ($pairs as $pair) {
        if ($pair === '') {
            return null;
        }
        [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
        if (preg_match('/%(?![0-9A-Fa-f]{2})/', $key . $value)) {
            return null;
        }
        $key = urldecode($key);
        $value = urldecode($value);
        if (!preg_match('/^[a-z][a-z0-9_]{0,39}$/D', $key)
            || array_key_exists($key, $out) || strlen($value) > 2048
            || preg_match('/[\x00-\x1f\x7f]/', $value) || preg_match('//u', $value) !== 1) {
            return null;
        }
        $out[$key] = $value;
    }
    return $out;
}

/** Encoded path bytes are unnecessary for the fixed ASCII route alphabet. */
function requestParts(string $requestUri, string $base = '/'): ?array
{
    validateBase($base);
    if (strlen($requestUri) > 10000 || !str_starts_with($requestUri, '/')
        || str_contains($requestUri, '#') || preg_match('/[\x00-\x20\x7f\\\\]/', $requestUri)) {
        return null;
    }
    [$rawPath, $rawQuery] = array_pad(explode('?', $requestUri, 2), 2, '');
    if (str_contains($rawPath, '//') || str_contains($rawPath, '%')
        || !preg_match('~^/[A-Za-z0-9_@./-]*$~D', $rawPath)) {
        return null;
    }
    foreach (explode('/', $rawPath) as $segment) {
        if ($segment === '.' || $segment === '..') {
            return null;
        }
    }
    $baseWithoutSlash = rtrim($base, '/');
    if ($base !== '/' && $rawPath === $baseWithoutSlash) {
        $path = '/';
    } elseif (!str_starts_with($rawPath, $base)) {
        return null;
    } else {
        $path = '/' . substr($rawPath, strlen($base));
    }
    $trailing = $path !== '/' && str_ends_with($path, '/');
    if ($trailing) {
        // No PATH_INFO aliases for PHP or static documents.
        if (str_contains(basename(rtrim($path, '/')), '.')) {
            return null;
        }
        $path = rtrim($path, '/');
    }
    $args = query($rawQuery);
    return $args === null ? null : ['path' => $path, 'query' => $args, 'trailing' => $trailing,
        'base_slash_missing' => $base !== '/' && $rawPath === $baseWithoutSlash];
}

function segmentPatterns(): array
{
    return ['public_id' => '[a-f0-9]{32}', 'alias' => '[a-z][a-z0-9_]{2,29}',
        'token' => '[a-f0-9]{64}'];
}

function routePattern(string $path): string
{
    $pattern = preg_quote($path, '~');
    foreach (segmentPatterns() as $name => $alphabet) {
        $pattern = str_replace(preg_quote('{' . $name . '}', '~'), '(?P<' . $name . '>' . $alphabet . ')', $pattern);
    }
    return '~^' . $pattern . '$~D';
}

/** Metadata only: database permissions and resource lookup are separate server gates. */
function resolve(string $requestUri, string $method, string $base = '/'): array
{
    $parts = requestParts($requestUri, $base);
    if ($parts === null) {
        return ['status' => 404];
    }
    $entries = catalogue();
    $matched = null;
    $params = [];
    $legacy = false;
    foreach ($entries as $entry) {
        if (in_array(ltrim($parts['path'], '/'), $entry['legacy'], true)) {
            $matched = $entry;
            $legacy = true;
            break;
        }
        if ($entry['path'] !== null && preg_match(routePattern($entry['path']), $parts['path'], $values)) {
            $matched = $entry;
            foreach (segmentPatterns() as $key => $_) {
                if (isset($values[$key])) {
                    $params[$key] = $values[$key];
                }
            }
            break;
        }
    }
    if ($matched === null) {
        return ['status' => 404];
    }
    if ($legacy && $matched['name'] === 'api-root') {
        $resource = strtolower(trim($parts['query']['resource'] ?? 'root', '/'));
        $resource = preg_replace('~^v1/?~', '', $resource) ?: 'root';
        $selected = array_values(array_filter($entries, static fn (array $row): bool => $row['name'] === 'api-' . $resource));
        if (count($selected) !== 1) {
            return ['status' => 404];
        }
        $matched = $selected[0];
        unset($parts['query']['resource']);
    }
    if (isset($matched['api_resource'], $parts['query']['resource'])
        && $parts['query']['resource'] !== $matched['api_resource']) {
        return ['status' => 404];
    }
    if (!in_array($method, $matched['methods'], true)) {
        return ['status' => 405, 'allow' => $matched['methods']];
    }
    return ['status' => 200, 'route' => $matched, 'params' => $params,
        'query' => $parts['query'], 'legacy' => $legacy,
        'canonical_slash' => $parts['trailing'] || $parts['base_slash_missing']];
}

/** Only validated placeholders from the server catalogue can enter a path. */
function pathFor(string $name, array $params = [], string $base = '/'): string
{
    validateBase($base);
    foreach (catalogue() as $entry) {
        if ($entry['name'] !== $name) {
            continue;
        }
        if ($entry['path'] === null) {
            throw new \InvalidArgumentException('Resource reference must be resolved.');
        }
        $path = $entry['path'];
        $used = [];
        foreach (segmentPatterns() as $key => $alphabet) {
            if (!str_contains($path, '{' . $key . '}')) {
                continue;
            }
            if (!isset($params[$key]) || !is_string($params[$key])
                || !preg_match('~^' . $alphabet . '$~D', $params[$key])) {
                throw new \InvalidArgumentException('Invalid resource reference.');
            }
            $path = str_replace('{' . $key . '}', $params[$key], $path);
            $used[] = $key;
        }
        if (array_diff(array_keys($params), $used) !== []) {
            throw new \InvalidArgumentException('Unexpected route parameter.');
        }
        return $base . ltrim($path, '/');
    }
    throw new \OutOfBoundsException('Route not found.');
}
