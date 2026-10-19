<?php

declare(strict_types=1);

use MusicMad\Routing\ResourceAccess;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/includes/routing/access.php';

final class ResourceRoutingAccessIntegrationTest extends TestCase
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

    public function testProfileAddressRechecksPrivacyBlocksAndDeactivation(): void
    {
        $owner = new ResourceAccess($this->db, 1);
        $viewer = new ResourceAccess($this->db, 2);
        $reference = $owner->reference('user', 1);
        self::assertIsString($reference);
        $this->db->exec('DELETE FROM seguidores WHERE seguidor_id=2 AND seguido_id=1');
        $this->db->exec("INSERT INTO perfiles_privacidad(usuario_id,visibilidad) VALUES(1,'publico') ON DUPLICATE KEY UPDATE visibilidad='publico'");
        self::assertSame(1, $viewer->resolve('user', $reference));
        $this->db->exec("UPDATE perfiles_privacidad SET visibilidad='privado' WHERE usuario_id=1");
        self::assertNull($viewer->resolve('user', $reference));
        self::assertNull($viewer->resolve('user', str_repeat('0', 32)));
        self::assertSame(1, $owner->resolve('user', $reference));
        $this->db->exec("INSERT INTO usuarios_bloqueados(usuario_id,objetivo_id,modo) VALUES(1,2,'bloquear')");
        self::assertNull($viewer->resolve('user', $reference));
        $this->db->exec('UPDATE usuarios SET activo=0 WHERE id=1');
        self::assertNull($owner->resolve('user', $reference));
        self::assertFalse((new ResourceAccess($this->db, 0))->canRead('user', 1));
    }

    public function testBandVisibilityDoesNotGrantMembershipOrAdministratorRights(): void
    {
        $result = crearBanda(1, ['nombre' => 'Routing QA ' . bin2hex(random_bytes(5)), 'genero' => 'Rock', 'ciudad' => 'Guadalajara', 'instrumento' => 'Guitarra']);
        self::assertTrue($result['ok']);
        $id = (int) $result['id'];
        $owner = new ResourceAccess($this->db, 1);
        $viewer = new ResourceAccess($this->db, 2);
        $reference = $owner->reference('band', $id);
        self::assertIsString($reference);
        self::assertSame($id, $viewer->resolve('band', $reference));
        self::assertNull($viewer->resolve('band', $reference, 'band-member'));
        self::assertSame($id, $owner->resolve('band', $reference, 'band-admin'));
        self::assertTrue(unirseBandaWorkspace(2, $id, 'Batería'));
        self::assertSame($id, $viewer->resolve('band', $reference, 'band-member'));
        self::assertNull($viewer->resolve('band', $reference, 'band-admin'));
        archivarBandaWorkspace(1, $id);
        self::assertNull($owner->resolve('band', $reference));
        self::assertNull($viewer->resolve('band', $reference));
    }

    public function testUnknownResourceKindsCapabilitiesAndIdsAreDenied(): void
    {
        $viewer = new ResourceAccess($this->db, 1);
        self::assertFalse($viewer->canRead('config', 1));
        self::assertFalse($viewer->allows('user', 1, 'administrator'));
        self::assertFalse($viewer->allows('user', 1, 'band-admin'));
        foreach (['user', 'post', 'band', 'event', 'room', 'collab', 'clip', 'challenge', 'playlist', 'conversation'] as $type) {
            self::assertFalse($viewer->canRead($type, 0));
            self::assertFalse($viewer->canRead($type, 2147483647));
        }
    }
}
