<?php

declare(strict_types=1);

namespace MusicMad\Routing;

require_once __DIR__ . '/session_binding.php';
require_once __DIR__ . '/public_binding.php';

/** The HTTP adapter must load authentication using the fixed legacy script identity first. */
final class RequestPlan
{
    public function __construct(private readonly \PDO $db, private readonly int $viewer)
    {
    }

    public function resolve(string $uri, string $method, array $body = [], string $base = '/'): array
    {
        $match = resolve($uri, $method, $base);
        if ($match['status'] !== 200) {
            return $match;
        }
        $route = $match['route'];
        $publicProjection = in_array($route['name'], ['portafolio', 'embed', 'pista-publica', 'vista-publica'], true)
            || ($this->viewer === 0 && in_array($route['name'], ['banda', 'evento', 'sala'], true));
        if ($publicProjection) {
            $result = (new PublicRouteBinding($this->db))->bind($uri, $method, $base);
            if ($result['status'] === 200) {
                $route['handler'] = $result['handler'];
                $result['route'] = $route;
                $result['body'] = $body;
            }
            return $result;
        }
        if ($route['access'] === 'session' && $this->viewer < 1) {
            return ['status' => 404];
        }
        $roles = match ($route['name']) {
            'permisos', 'verificaciones' => ['administrador'],
            'moderacion', 'mercado-reportes' => ['administrador', 'moderador'],
            default => [],
        };
        if ($roles !== [] && ($this->viewer < 1 || !in_array(\rolSitio($this->viewer), $roles, true))) {
            return ['status' => 404];
        }
        if (isset($route['resource']) || ($route['name'] === 'mensajes' && isset($match['query']['con']))
            || ($route['name'] === 'playlist-equipo' && isset($match['query']['id']))) {
            return (new SessionRouteBinding($this->db, $this->viewer))->bind($uri, $method, $body, $base);
        }
        $canonical = pathFor($route['name'], $match['params'], $base);
        if ($match['query'] !== []) {
            $canonical .= '?' . http_build_query($match['query'], '', '&', PHP_QUERY_RFC3986);
        }
        return ['status' => 200, 'route' => $route, 'canonical' => $canonical,
            'query' => $match['query'], 'body' => $body,
            'redirect' => ($match['legacy'] || $match['canonical_slash']) && in_array($method, ['GET', 'HEAD'], true) ? 301 : null];
    }
}
