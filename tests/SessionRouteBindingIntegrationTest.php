<?php

declare(strict_types=1);

use MusicMad\Routing\SessionRouteBinding;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/includes/routing/session_binding.php';

final class SessionRouteBindingIntegrationTest extends TestCase
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

    public function testManagementRoutesCheckActualRoleBeforeAnyLegacyRedirect(): void
    {
        require_once dirname(__DIR__) . '/includes/routing/request_plan.php';
        $this->db->exec("UPDATE usuarios SET rol='cliente' WHERE id=1");
        $this->db->exec('DELETE FROM musicmad_site_roles WHERE usuario_id=1');
        $plan = new MusicMad\Routing\RequestPlan($this->db, 1);
        foreach (['permisos','verificaciones','moderacion','mercado-reportes'] as $name) {
            self::assertSame(404, $plan->resolve(MusicMad\Routing\pathFor($name), 'GET')['status']);
            self::assertSame(404, $plan->resolve('/'.$name.'.php', 'GET')['status']);
        }
        $this->db->exec("UPDATE usuarios SET rol='administrador' WHERE id=1");
        foreach (['permisos','verificaciones','moderacion','mercado-reportes'] as $name) {
            self::assertSame(200, $plan->resolve(MusicMad\Routing\pathFor($name), 'GET')['status']);
        }
    }

    public function testLegacyRedirectAndCanonicalHandlerReceiveOnlyAuthorizedIdentity(): void
    {
        foreach (['/', '/MusicMad_PWA/'] as $base) {
            $binding = new SessionRouteBinding($this->db, 1);
            $old = $binding->bind($base . 'musico.php?id=1&tab=pistas', 'GET', [], $base);
            self::assertSame(200, $old['status']);
            self::assertSame(301, $old['redirect']);
            self::assertMatchesRegularExpression('~/musico/[a-f0-9]{32}\?tab=pistas$~D', $old['canonical']);
            self::assertStringNotContainsString('id=1', $old['canonical']);
            $clean = $binding->bind($old['canonical'], 'GET', [], $base);
            self::assertSame(200, $clean['status']);
            self::assertNull($clean['redirect']);
            self::assertSame('1', $clean['query']['id']);
            self::assertSame('pistas', $clean['query']['tab']);
            self::assertSame(['status' => 404], $binding->bind($old['canonical'] . '&id=2', 'GET', [], $base));
        }
    }

    public function testForbiddenAndInventedOldResourcesProduceIdenticalResultBeforeRedirect(): void
    {
        $binding = new SessionRouteBinding($this->db, 2);
        $this->db->exec("INSERT INTO perfiles_privacidad(usuario_id,visibilidad) VALUES(1,'privado') ON DUPLICATE KEY UPDATE visibilidad='privado'");
        $this->db->exec('DELETE FROM seguidores WHERE seguidor_id=2 AND seguido_id=1');
        self::assertSame(['status' => 404], $binding->bind('/musico.php?id=1', 'GET'));
        self::assertSame(['status' => 404], $binding->bind('/musico.php?id=2147483647', 'GET'));
        foreach (['0', '-1', '01', '1e0', '4294967296', '1.0'] as $id) {
            self::assertSame(['status' => 404], $binding->bind('/musico.php?id=' . $id, 'GET'));
        }
    }

    public function testOldAndCleanConversationAddressesKeepPrefilledTextAndProtectMembership(): void
    {
        $binding = new SessionRouteBinding($this->db, 1);
        $old = $binding->bind('/mensajes.php?con=2&txt=Hola%20m%C3%BAsico', 'GET');
        self::assertSame(301, $old['redirect']);
        self::assertMatchesRegularExpression('~^/mensajes/[a-f0-9]{32}\?txt=Hola%20m%C3%BAsico$~D', $old['canonical']);
        $clean = $binding->bind($old['canonical'], 'GET');
        self::assertSame('2', $clean['query']['con']);
        self::assertSame('Hola músico', $clean['query']['txt']);
        self::assertSame(['status' => 404], (new SessionRouteBinding($this->db, 3))->bind($old['canonical'], 'GET'));
        $post = $binding->bind($old['canonical'], 'POST', ['con' => '2', 'accion' => 'leer_hilo']);
        self::assertSame(200, $post['status']);
        self::assertNull($post['redirect']);
        self::assertSame('leer_hilo', $post['body']['accion']);
        self::assertSame(['status' => 404], $binding->bind($old['canonical'], 'POST', ['con' => '3']));
        self::assertSame(['status' => 404], (new SessionRouteBinding($this->db, 0))->bind($old['canonical'], 'GET'));
    }

    public function testExistingPrivateRoomCodeSurvivesRedirectButNeverGrantsMembership(): void
    {
        $this->db->prepare("INSERT INTO publicaciones(usuario_id,tipo,titulo) VALUES(1,'cancion','Routing private room QA')")->execute();
        $post = (int) $this->db->lastInsertId();
        $this->db->prepare('INSERT INTO publicacion_audio(publicacion_id,archivo,bytes) VALUES(?,?,1)')->execute([$post, 'routing_room_qa.wav']);
        $this->db->prepare('INSERT INTO publicacion_medios(publicacion_id,sha256,duracion,mime) VALUES(?,?,60,?)')->execute([$post, hash('sha256', 'routing room fixture'), 'audio/wav']);
        $code = bin2hex(random_bytes(16));
        $this->db->prepare("INSERT INTO salas_escucha(anfitrion_id,publicacion_id,nombre,codigo) VALUES(1,?,'Routing room QA',?)")->execute([$post, $code]);
        $room = (int) $this->db->lastInsertId();
        $this->db->prepare("INSERT INTO musicmad_room_state(sala_id,modo,actualizada_utc,expira_en) VALUES(?,'enlace',UTC_TIMESTAMP(3),UTC_TIMESTAMP()+INTERVAL 1 DAY)")->execute([$room]);
        $this->db->prepare('INSERT INTO salas_integrantes(sala_id,usuario_id,visto_en) VALUES(?,1,UTC_TIMESTAMP())')->execute([$room]);
        $binding = new SessionRouteBinding($this->db, 2);
        self::assertSame(['status' => 404], $binding->bind('/sala.php?id=' . $room, 'GET'));
        $old = $binding->bind('/sala.php?id=' . $room . '&codigo=' . $code, 'GET');
        self::assertSame(301, $old['redirect']);
        self::assertMatchesRegularExpression('~^/sala/[a-f0-9]{32}\?codigo=[a-f0-9]{32}$~D', $old['canonical']);
        self::assertSame(200, $binding->bind($old['canonical'], 'GET')['status']);
        $withoutCode = strtok($old['canonical'], '?');
        self::assertSame(['status' => 404], $binding->bind($withoutCode, 'GET'));
        self::assertSame(['status' => 404], $binding->bind($withoutCode . '?codigo=' . str_repeat('0', 32), 'GET'));
        self::assertFalse(integranteSala(2, $room));
        $policy = new \MusicMad\Routing\ResourceAccess($this->db, 2, $code);
        self::assertFalse($policy->allows('room', $room, 'room-member'));
        $this->db->prepare('UPDATE musicmad_room_state SET expira_en=UTC_TIMESTAMP()-INTERVAL 1 SECOND WHERE sala_id=?')->execute([$room]);
        self::assertSame(['status' => 404], $binding->bind($old['canonical'], 'GET'));
    }

    public function testPlaylistTeamDashboardAndOneListHaveSeparateRoutesAndPermissions(): void
    {
        $dashboard = \MusicMad\Routing\resolve('/playlist-equipo.php', 'GET');
        self::assertSame('/listas/equipo', $dashboard['route']['path']);
        self::assertArrayNotHasKey('resource', $dashboard['route']);
        $created = crearPlaylistSocial3(1, 'Routing playlist QA', '', true);
        self::assertTrue($created['ok']);
        $id = (int) $created['id'];
        $binding = new SessionRouteBinding($this->db, 1);
        $old = $binding->bind('/playlist-equipo.php?id=' . $id, 'GET');
        self::assertSame(301, $old['redirect']);
        self::assertMatchesRegularExpression('~^/lista/[a-f0-9]{32}/equipo$~D', $old['canonical']);
        self::assertSame(200, $binding->bind($old['canonical'], 'GET')['status']);
        self::assertSame(['status' => 404], (new SessionRouteBinding($this->db, 2))->bind($old['canonical'], 'GET'));
        self::assertSame(['status' => 404], (new SessionRouteBinding($this->db, 2))->bind('/playlist-equipo.php?id=' . $id, 'GET'));
    }
}
