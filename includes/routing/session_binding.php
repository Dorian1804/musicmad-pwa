<?php

declare(strict_types=1);

namespace MusicMad\Routing;

require_once __DIR__ . '/paths.php';
require_once __DIR__ . '/access.php';

/** Resolve protected resource addresses before any legacy redirect or page dispatch. */
final class SessionRouteBinding
{
    private ResourceAccess $access;
    private ConversationReferences $conversations;

    public function __construct(private readonly \PDO $db, private readonly int $viewer)
    {
        $this->access = new ResourceAccess($db, $viewer);
        $this->conversations = new ConversationReferences($db);
    }

    private function capability(string $name): string
    {
        return match ($name) {
            'banda-chat-vista', 'banda-ics' => 'band-member',
            'playlist-equipo-recurso' => 'playlist-edit',
            default => 'read',
        };
    }

    /** Return handler input separately from the canonical address; never mutate request globals.
     * The front controller must additionally enforce verified email, onboarding and CSRF.
     */
    public function bind(string $uri, string $method, array $body = [], string $base = '/'): array
    {
        $match = resolve($uri, $method, $base);
        if ($match['status'] !== 200) {
            return $match;
        }
        $route = $match['route'];
        if ($route['access'] !== 'session' || $this->viewer < 1) {
            return ['status' => 404];
        }
        $query = $match['query'];
        $this->access = new ResourceAccess($this->db, $this->viewer, $query['codigo'] ?? '');
        // The same legacy script also has a dashboard without a list identifier.
        if ($route['name'] === 'playlist-equipo' && isset($query['id'])) {
            foreach (catalogue() as $candidate) {
                if ($candidate['name'] === 'playlist-equipo-recurso') {
                    $route = $candidate;
                    $match['legacy'] = true;
                    break;
                }
            }
        }
        // Old conversations addressed the peer; canonical ones address the protected pair.
        if ($route['name'] === 'mensajes' && isset($query['con'])) {
            $peer = $this->legacyId($query['con']);
            $reference = $peer === null ? null : $this->conversations->forPair($this->viewer, $peer);
            if ($reference === null) {
                return ['status' => 404];
            }
            foreach (catalogue() as $candidate) {
                if ($candidate['name'] === 'conversation') {
                    $route = $candidate;
                    break;
                }
            }
            $match['params']['public_id'] = $reference;
            $match['legacy'] = true;
        }
        if (!isset($route['resource'])) {
            return ['status' => 404];
        }
        $key = $route['legacy_id'];
        $type = $route['resource'];
        $capability = $this->capability($route['name']);
        $reference = $match['params']['public_id'] ?? null;
        if ($reference === null) {
            $id = $this->legacyId($query[$key] ?? $body[$key] ?? null);
            $reference = $id === null ? null : $this->access->reference($type, $id, $capability);
            if ($reference === null) {
                return ['status' => 404];
            }
        } else {
            $id = $type === 'conversation'
                ? $this->conversations->resolve($this->viewer, $reference)
                : $this->access->resolve($type, $reference, $capability);
            if ($id === null) {
                return ['status' => 404];
            }
        }
        // A URL parameter must never be overridden by a query or form parameter.
        foreach ([$query, $body] as $input) {
            if (array_key_exists($key, $input) && $this->legacyId($input[$key]) !== $id) {
                return ['status' => 404];
            }
        }
        $canonicalQuery = $query;
        unset($canonicalQuery[$key]);
        $canonical = pathFor($route['name'], ['public_id' => $reference], $base);
        if ($canonicalQuery !== []) {
            $canonical .= '?' . http_build_query($canonicalQuery, '', '&', PHP_QUERY_RFC3986);
        }
        $handlerQuery = $query;
        $handlerQuery[$key] = (string) $id;
        // Old mutation bodies retain their parameters; validation above binds them to the URL.
        return ['status' => 200, 'route' => $route, 'canonical' => $canonical,
            'query' => $handlerQuery, 'body' => $body,
            'redirect' => ($match['legacy'] || $match['canonical_slash']) && in_array($method, ['GET', 'HEAD'], true) ? 301 : null];
    }

    private function legacyId(mixed $value): ?int
    {
        if (!is_string($value) && !is_int($value)) {
            return null;
        }
        $value = (string) $value;
        if (!preg_match('/^[1-9][0-9]{0,9}$/D', $value) || (float) $value > 4294967295) {
            return null;
        }
        return (int) $value;
    }
}
