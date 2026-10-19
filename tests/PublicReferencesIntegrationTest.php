<?php

declare(strict_types=1);

use MusicMad\Routing\PublicReferences;
use MusicMad\Routing\RouteInvitations;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/includes/routing/references.php';
require_once dirname(__DIR__) . '/includes/routing/invitations.php';

final class PublicReferencesIntegrationTest extends TestCase
{
    private PDO $db;
    private PublicReferences $references;
    private RouteInvitations $invitations;
    private array $created = [];

    protected function setUp(): void
    {
        if (getenv('MM_RUN_QA') !== '1') {
            $this->markTestSkipped('Solo QA aislada.');
        }
        self::assertSame('musicmad_qa', DB_NAME);
        $this->db = getDB();
        self::assertSame('musicmad_qa', $this->db->query('SELECT DATABASE()')->fetchColumn());
        $sql = file_get_contents(dirname(__DIR__) . '/config/migrations/036_public_routes.sql');
        $this->db->exec($sql);
        $this->db->exec($sql);
        $this->references = new PublicReferences($this->db);
        $this->invitations = new RouteInvitations($this->db);
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) {
            return;
        }
        if ($this->db->inTransaction()) {
            $this->db->rollBack();
        }
        $delete = $this->db->prepare('DELETE FROM musicmad_public_references WHERE public_id=?');
        foreach ($this->created as $reference) {
            $delete->execute([$reference]);
        }
    }

    private function reference(string $type = 'room'): string
    {
        $reference = $this->references->forResource($type, random_int(1000000000, 2000000000), static fn (): bool => true);
        self::assertIsString($reference);
        $this->created[] = $reference;
        return $reference;
    }

    public function testStableRandomReferencesRequirePermissionOnEveryResolution(): void
    {
        $id = random_int(1000000000, 2000000000);
        $reference = $this->references->forResource('post', $id, static fn (): bool => true);
        self::assertIsString($reference);
        $this->created[] = $reference;
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/D', $reference);
        self::assertSame($reference, $this->references->forResource('post', $id, static fn (): bool => true));
        self::assertNull($this->references->forResource('post', $id, static fn (): bool => false));
        self::assertSame($id, $this->references->resolve('post', $reference, static fn (): bool => true));
        self::assertNull($this->references->resolve('post', $reference, static fn (): bool => false));
        self::assertNull($this->references->resolve('user', $reference, static fn (): bool => true));
        $calls = [];
        self::assertNull($this->references->resolve('post', str_repeat('0', 32), static function (string $type, int $found) use (&$calls): bool {
            $calls[] = [$type, $found];
            return true;
        }));
        self::assertSame([['post', 0]], $calls);
    }

    public function testInvalidTypesAndIdsCannotBeAllocatedOrLookedUp(): void
    {
        $denyUnexpected = static function (): bool {
            self::fail('Invalid syntax must never invoke a resource policy.');
        };
        foreach (['users', '../user', 'user.php', 'room; DROP TABLE usuarios'] as $type) {
            self::assertNull($this->references->forResource($type, 1, $denyUnexpected));
            self::assertNull($this->references->resolve($type, str_repeat('a', 32), $denyUnexpected));
        }
        foreach (['1', str_repeat('a', 31), str_repeat('a', 33), str_repeat('A', 32), str_repeat('g', 32), str_repeat('a', 32) . "\n"] as $value) {
            self::assertNull($this->references->resolve('room', $value, $denyUnexpected));
        }
        self::assertNull($this->references->forResource('room', 0, $denyUnexpected));
        self::assertNull($this->references->forResource('room', -1, $denyUnexpected));
    }

    public function testInvitationIsHashedPersonalSingleUseAndRechecksGrantPermission(): void
    {
        $reference = $this->reference();
        $token = $this->invitations->issue($reference, 1, 2, 3600, static fn (): bool => true);
        self::assertIsString($token);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $token);
        $find = $this->db->prepare('SELECT token_hash FROM musicmad_route_invites WHERE reference_id=?');
        $find->execute([$reference]);
        self::assertSame(hash('sha256', $token), $find->fetchColumn());
        self::assertNull($this->invitations->redeem($token, 3, static fn (): bool => true));
        self::assertNull($this->invitations->redeem($token, 2, static fn (): bool => false));
        self::assertSame($reference, $this->invitations->redeem($token, 2, static function (string $found, int $issuer, int $recipient, string $type, int $id) use ($reference): bool {
            self::assertSame([$reference, 1, 2, 'room'], [$found, $issuer, $recipient, $type]);
            self::assertGreaterThan(0, $id);
            return true;
        }));
        self::assertNull($this->invitations->redeem($token, 2, static fn (): bool => true));
    }

    public function testExpiredRevokedUnknownAndDeniedTokensHaveSameResult(): void
    {
        $reference = $this->reference();
        $issue = fn (): ?string => $this->invitations->issue($reference, 1, 2, 3600, static fn (): bool => true);
        $expired = $issue();
        $this->db->prepare('UPDATE musicmad_route_invites SET expires_at=UTC_TIMESTAMP()-INTERVAL 1 SECOND WHERE reference_id=?')->execute([$reference]);
        self::assertNull($this->invitations->redeem($expired, 2, static fn (): bool => true));
        $revoked = $issue();
        self::assertFalse($this->invitations->revoke($reference, 1, static fn (): bool => false));
        self::assertTrue($this->invitations->revoke($reference, 1, static fn (): bool => true));
        self::assertNull($this->invitations->redeem($revoked, 2, static fn (): bool => true));
        self::assertNull($this->invitations->redeem(str_repeat('0', 64), 2, static fn (): bool => true));
        self::assertNull($this->invitations->issue($reference, 1, 2, 3600, static fn (): bool => false));
        self::assertNull($this->invitations->issue($reference, 1, 1, 3600, static fn (): bool => true));
        self::assertNull($this->invitations->issue($reference, 1, 2, 604801, static fn (): bool => true));
    }

    public function testFailedGrantRollsBackBothMembershipWorkAndTokenConsumption(): void
    {
        $reference = $this->reference();
        $token = $this->invitations->issue($reference, 1, 2, 3600, static fn (): bool => true);
        self::assertIsString($token);
        $sideEffect = bin2hex(random_bytes(16));
        $this->created[] = $sideEffect;
        try {
            $this->invitations->redeem($token, 2, function () use ($sideEffect): bool {
                $this->db->prepare('INSERT INTO musicmad_public_references(resource_type,resource_id,public_id) VALUES(?,?,?)')->execute(['band', random_int(1000000000, 2000000000), $sideEffect]);
                throw new RuntimeException('QA simulated grant failure');
            });
            self::fail('Expected grant exception.');
        } catch (RuntimeException $error) {
            self::assertSame('QA simulated grant failure', $error->getMessage());
        }
        self::assertFalse($this->db->inTransaction());
        $find = $this->db->prepare('SELECT COUNT(*) FROM musicmad_public_references WHERE public_id=?');
        $find->execute([$sideEffect]);
        self::assertSame(0, (int) $find->fetchColumn());
        self::assertSame($reference, $this->invitations->redeem($token, 2, static fn (): bool => true));
    }
}
