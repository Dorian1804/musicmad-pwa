<?php

declare(strict_types=1);

namespace MusicMad\Routing;

/** Personal, single-use invitations. Membership is granted only by a transactional policy. */
final class RouteInvitations
{
    public function __construct(private readonly \PDO $db)
    {
    }

    /** @param callable(string, int, int): bool $canInvite */
    public function issue(string $reference, int $issuer, int $recipient, int $lifetime, callable $canInvite): ?string
    {
        if (!preg_match('/^[a-f0-9]{32}$/D', $reference) || $issuer < 1 || $recipient < 1 || $issuer === $recipient
            || $lifetime < 60 || $lifetime > 604800 || !$canInvite($reference, $issuer, $recipient)) {
            return null;
        }
        $token = bin2hex(random_bytes(32));
        $insert = $this->db->prepare('INSERT INTO musicmad_route_invites(reference_id,token_hash,issuer_id,recipient_id,expires_at) VALUES(?,?,?,?,?)');
        $insert->execute([$reference, hash('sha256', $token), $issuer, $recipient, gmdate('Y-m-d H:i:s', time() + $lifetime)]);
        return $token;
    }

    /** Caller must validate CSRF and verification/onboarding before entering this operation.
     * @param callable(string, int, int, string, int): bool $grant
     */
    public function redeem(string $token, int $recipient, callable $grant): ?string
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $token) || $recipient < 1) {
            return null;
        }
        // The grant and token consumption must share this transaction and connection.
        if ($this->db->inTransaction()) {
            throw new \LogicException('Invitation redemption requires its own transaction.');
        }
        $this->db->beginTransaction();
        try {
            $find = $this->db->prepare('SELECT i.*, r.resource_type, r.resource_id FROM musicmad_route_invites i JOIN musicmad_public_references r ON r.public_id=i.reference_id WHERE i.token_hash=? FOR UPDATE');
            $find->execute([hash('sha256', $token)]);
            $invite = $find->fetch(\PDO::FETCH_ASSOC);
            if (!$invite || (int) $invite['recipient_id'] !== $recipient || $invite['revoked_at'] !== null
                || $invite['consumed_at'] !== null || $invite['expires_at'] <= gmdate('Y-m-d H:i:s')) {
                $this->db->rollBack();
                return null;
            }
            if (!$grant((string) $invite['reference_id'], (int) $invite['issuer_id'], $recipient, (string) $invite['resource_type'], (int) $invite['resource_id'])) {
                $this->db->rollBack();
                return null;
            }
            $consume = $this->db->prepare('UPDATE musicmad_route_invites SET consumed_at=UTC_TIMESTAMP() WHERE id=?');
            $consume->execute([$invite['id']]);
            $this->db->commit();
            return (string) $invite['reference_id'];
        } catch (\Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }

    /** @param callable(string, int): bool $canRevoke */
    public function revoke(string $reference, int $issuer, callable $canRevoke): bool
    {
        if (!preg_match('/^[a-f0-9]{32}$/D', $reference) || $issuer < 1 || !$canRevoke($reference, $issuer)) {
            return false;
        }
        $update = $this->db->prepare('UPDATE musicmad_route_invites SET revoked_at=UTC_TIMESTAMP() WHERE reference_id=? AND issuer_id=? AND consumed_at IS NULL AND revoked_at IS NULL');
        $update->execute([$reference, $issuer]);
        return true;
    }
}
