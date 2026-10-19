<?php

declare(strict_types=1);

namespace MusicMad\Routing;

require_once __DIR__ . '/references.php';
require_once dirname(__DIR__) . '/chat_live.php';

/** A conversation URL belongs to a pair; it never contains the other person's numeric ID. */
final class ConversationReferences
{
    private PublicReferences $references;

    public function __construct(private readonly \PDO $db)
    {
        $this->references = new PublicReferences($db);
    }

    private function canTalk(int $viewer, int $other): bool
    {
        try {
            \autorizarHiloChat($viewer, $other);
            return true;
        } catch (\OutOfBoundsException) {
            return false;
        }
    }

    public function forPair(int $viewer, int $other): ?string
    {
        if (!$this->canTalk($viewer, $other)) {
            return null;
        }
        $low = min($viewer, $other);
        $high = max($viewer, $other);
        $insert = $this->db->prepare('INSERT INTO musicmad_conversations(user_low,user_high) VALUES(?,?) ON DUPLICATE KEY UPDATE user_low=VALUES(user_low)');
        $insert->execute([$low, $high]);
        $find = $this->db->prepare('SELECT id FROM musicmad_conversations WHERE user_low=? AND user_high=?');
        $find->execute([$low, $high]);
        $id = (int) $find->fetchColumn();
        return $this->references->forResource('conversation', $id, fn (string $type, int $found): bool => $this->otherParticipant($viewer, $found) !== null);
    }

    /** Return the peer only after checking membership, active accounts and current blocks. */
    public function otherParticipant(int $viewer, int $conversation): ?int
    {
        $find = $this->db->prepare('SELECT user_low,user_high FROM musicmad_conversations WHERE id=? AND (user_low=? OR user_high=?)');
        $find->execute([$conversation, $viewer, $viewer]);
        $row = $find->fetch(\PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $other = (int) $row['user_low'] === $viewer ? (int) $row['user_high'] : (int) $row['user_low'];
        return $this->canTalk($viewer, $other) ? $other : null;
    }

    public function resolve(int $viewer, string $reference): ?int
    {
        $other = null;
        $this->references->resolve('conversation', $reference, function (string $type, int $found) use ($viewer, &$other): bool {
            $other = $this->otherParticipant($viewer, $found);
            return $other !== null;
        });
        return $other;
    }
}
