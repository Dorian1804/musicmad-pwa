<?php

declare(strict_types=1);

use MusicMad\Routing\ConversationReferences;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/includes/routing/conversations.php';

final class ConversationReferencesIntegrationTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        if (getenv('MM_RUN_QA') !== '1') {
            $this->markTestSkipped('Solo QA aislada.');
        }
        self::assertSame('musicmad_qa', DB_NAME);
        $this->db = getDB();
        self::assertSame('musicmad_qa', $this->db->query('SELECT DATABASE()')->fetchColumn());
        $this->db->exec(file_get_contents(dirname(__DIR__) . '/config/migrations/036_public_routes.sql'));
        $this->db->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->inTransaction()) {
            $this->db->rollBack();
        }
    }

    public function testBothMembersShareOneAddressAndAnOutsiderCannotResolveIt(): void
    {
        $service = new ConversationReferences($this->db);
        $reference = $service->forPair(1, 2);
        self::assertIsString($reference);
        self::assertSame($reference, $service->forPair(2, 1));
        self::assertSame(2, $service->resolve(1, $reference));
        self::assertSame(1, $service->resolve(2, $reference));
        self::assertNull($service->resolve(3, $reference));
        self::assertNull($service->resolve(0, $reference));
        self::assertNull($service->forPair(1, 1));
        self::assertNull($service->forPair(1, 0));
        self::assertNull($service->forPair(1, 2147483647));
    }

    public function testExistingAddressStopsWorkingAfterBlockingOrAccountDeactivation(): void
    {
        $service = new ConversationReferences($this->db);
        $reference = $service->forPair(1, 2);
        self::assertIsString($reference);
        $this->db->exec("INSERT INTO usuarios_bloqueados(usuario_id,objetivo_id,modo) VALUES(2,1,'bloquear')");
        self::assertNull($service->resolve(1, $reference));
        self::assertNull($service->resolve(2, $reference));
        self::assertNull($service->forPair(1, 2));
        $this->db->exec('DELETE FROM usuarios_bloqueados WHERE usuario_id=2 AND objetivo_id=1');
        self::assertSame(2, $service->resolve(1, $reference));
        $this->db->exec('UPDATE usuarios SET activo=0 WHERE id=2');
        self::assertNull($service->resolve(1, $reference));
        self::assertNull($service->resolve(2, $reference));
    }
}
