<?php

declare(strict_types=1);

namespace MusicMad\Routing;

/** Opaque references are addresses only. Every lookup must run a current access policy. */
final class PublicReferences
{
    private const TYPES = ['user', 'post', 'band', 'event', 'room', 'collab', 'clip', 'challenge', 'playlist', 'conversation'];

    public function __construct(private readonly \PDO $db)
    {
    }

    private function validResource(string $type, int $id): bool
    {
        return in_array($type, self::TYPES, true) && $id > 0;
    }

    /** @param callable(string, int): bool $canRead */
    public function forResource(string $type, int $id, callable $canRead): ?string
    {
        if (!$this->validResource($type, $id) || !$canRead($type, $id)) {
            return null;
        }
        $find = $this->db->prepare('SELECT public_id FROM musicmad_public_references WHERE resource_type=? AND resource_id=?');
        $find->execute([$type, $id]);
        $existing = $find->fetchColumn();
        if (is_string($existing)) {
            return $existing;
        }
        $insert = $this->db->prepare('INSERT INTO musicmad_public_references(resource_type,resource_id,public_id) VALUES(?,?,?)');
        for ($attempt = 0; $attempt < 4; $attempt++) {
            $publicId = bin2hex(random_bytes(16));
            try {
                $insert->execute([$type, $id, $publicId]);
                return $publicId;
            } catch (\PDOException $error) {
                // Only a duplicate key is retryable. Never hide storage failures.
                if ((int) ($error->errorInfo[1] ?? 0) !== 1062) {
                    throw $error;
                }
                $find->execute([$type, $id]);
                $winner = $find->fetchColumn();
                if (is_string($winner)) {
                    return $winner;
                }
            }
        }
        throw new \RuntimeException('Unable to allocate public reference.');
    }

    /** @param callable(string, int): bool $canRead */
    public function resolve(string $type, string $publicId, callable $canRead): ?int
    {
        if (!in_array($type, self::TYPES, true) || !preg_match('/^[a-f0-9]{32}$/D', $publicId)) {
            return null;
        }
        $find = $this->db->prepare('SELECT resource_id FROM musicmad_public_references WHERE resource_type=? AND public_id=?');
        $find->execute([$type, $publicId]);
        $id = (int) $find->fetchColumn();
        // Also invoke the policy on a missing address, avoiding a policy-query shortcut.
        $allowed = $canRead($type, $id);
        return $id > 0 && $allowed ? $id : null;
    }
}
