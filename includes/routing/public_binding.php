<?php

declare(strict_types=1);

namespace MusicMad\Routing;

require_once __DIR__ . '/paths.php';
require_once __DIR__ . '/references.php';
require_once dirname(__DIR__) . '/social.php';

/** Eligibility is always checked against the anonymous projection, never an owner's preview. */
final class PublicRouteBinding
{
    private const KINDS = ['banda' => 'band', 'evento' => 'event', 'sala' => 'room'];

    public function __construct(private readonly \PDO $db)
    {
    }

    public function bind(string $uri, string $method, string $base = '/'): array
    {
        $match = resolve($uri, $method, $base);
        if ($match['status'] !== 200) {
            return $match;
        }
        $route = $match['route'];
        $query = $match['query'];
        if (in_array($route['name'], ['portafolio', 'embed'], true)) {
            $alias = $match['params']['alias'] ?? $query['usuario'] ?? '';
            return $this->alias($alias, $route['name'], $query, $match, $base);
        }
        if ($route['name'] === 'pista-publica') {
            return $this->publicTrack($match, $base);
        }
        $kind = $route['name'] === 'vista-publica' ? ($query['tipo'] ?? '') : $route['name'];
        if ($kind === 'perfil' && $route['name'] === 'vista-publica') {
            $id = $this->legacyId($query['id'] ?? '');
            $profile = $id === null ? null : \recursoCompartiblePublico('perfil', $id);
            if (!$profile) {
                return ['status' => 404];
            }
            unset($query['id'], $query['tipo']);
            $match['legacy'] = true;
            return $this->alias($profile['slug'], 'portafolio', $query, $match, $base);
        }
        if (!isset(self::KINDS[$kind])) {
            return ['status' => 404];
        }
        $type = self::KINDS[$kind];
        $references = new PublicReferences($this->db);
        $canRead = static fn (string $type, int $id): bool => \recursoCompartiblePublico($kind, $id) !== null;
        $reference = $match['params']['public_id'] ?? null;
        if ($reference === null) {
            $id = $this->legacyId($query['id'] ?? '');
            $reference = $id === null ? null : $references->forResource($type, $id, $canRead);
            if ($reference === null) {
                return ['status' => 404];
            }
        } else {
            $id = $references->resolve($type, $reference, $canRead);
            if ($id === null || (isset($query['id']) && $this->legacyId($query['id']) !== $id)) {
                return ['status' => 404];
            }
        }
        unset($query['id'], $query['tipo']);
        $canonical = pathFor($kind, ['public_id' => $reference], $base);
        return $this->result('vista-publica', ['tipo' => $kind, 'id' => (string) $id], $canonical, $match, $query);
    }

    private function alias(string $alias, string $name, array $query, array $match, string $base): array
    {
        if (!preg_match('/^[a-z][a-z0-9_]{2,29}$/D', $alias) || (isset($query['usuario']) && $query['usuario'] !== $alias)) {
            return ['status' => 404];
        }
        $profile = \portafolioPorAlias($alias, 0, false);
        if (!$profile || ($name === 'embed' && !$profile['incrustable'])) {
            return ['status' => 404];
        }
        unset($query['usuario']);
        return $this->result($name, ['usuario' => $alias], pathFor($name, ['alias' => $alias], $base), $match, $query);
    }

    private function publicTrack(array $match, string $base): array
    {
        $query = $match['query'];
        $references = new PublicReferences($this->db);
        $policy = static fn (string $type, int $id): bool => \pistaPortafolioPublica($id, 0);
        $reference = $match['params']['public_id'] ?? null;
        if ($reference === null) {
            $id = $this->legacyId($query['id'] ?? '');
            $reference = $id === null ? null : $references->forResource('post', $id, $policy);
            if ($reference === null) {
                return ['status' => 404];
            }
        } else {
            $id = $references->resolve('post', $reference, $policy);
            if ($id === null || (isset($query['id']) && $this->legacyId($query['id']) !== $id)) {
                return ['status' => 404];
            }
        }
        unset($query['id']);
        return $this->result('pista-publica', ['id' => (string) $id], pathFor('pista-publica', ['public_id' => $reference], $base), $match, $query);
    }

    private function result(string $handler, array $input, string $canonical, array $match, array $query): array
    {
        if ($query !== []) {
            $canonical .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }
        return ['status' => 200, 'handler' => $handler, 'query' => $input + $query, 'canonical' => $canonical,
            'redirect' => $match['legacy'] || $match['canonical_slash'] ? 301 : null];
    }

    private function legacyId(string $value): ?int
    {
        return preg_match('/^[1-9][0-9]{0,9}$/D', $value) && (float) $value <= 4294967295 ? (int) $value : null;
    }
}
