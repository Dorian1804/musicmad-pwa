<?php

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/includes/chat_live.php';
final class ChatLiveIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        if (getenv('MM_RUN_QA') !== '1') {
            $this->markTestSkipped('Solo QA aislada.');
        }
        self::assertSame('musicmad_qa', DB_NAME);
        getDB()->beginTransaction();
    }
    protected function tearDown(): void
    {
        if (getenv('MM_RUN_QA') === '1' && getDB()->inTransaction()) {
            getDB()->rollBack();
        }
    }
    private function send(string $text = 'Mensaje QA'): array
    {
        return enviarChatSeguro(1, 2, $text, bin2hex(random_bytes(16)));
    }
    public function testRetryDoesNotDuplicateAndPayloadCannotChange(): void
    {
        $request = bin2hex(random_bytes(16));
        $a = enviarChatSeguro(1, 2, 'Hola', $request);
        $b = enviarChatSeguro(1, 2, 'Hola', $request);
        self::assertSame($a['id'], $b['id']);
        $this->expectException(LogicException::class);
        enviarChatSeguro(1, 2, 'Cambió', $request);
    }
    public function testIncrementalAndOlderPagesAreBoundedAndOrdered(): void
    {
        $first = $this->send()['id'];
        for ($i = 0;$i < 55;$i++) {
            getDB()->prepare('INSERT INTO mensajes(de_id,para_id,texto) VALUES(1,2,?)')->execute(['QA' . $i]);
        }
        $new = leerChatIncremental(2, 1, $first);
        self::assertCount(50, $new['mensajes']);
        self::assertTrue($new['mas']);
        self::assertGreaterThan($first, $new['mensajes'][0]['id']);
        $last = leerChatIncremental(2, 1);
        self::assertCount(50, $last['mensajes']);
        self::assertTrue($last['mas']);
        $older = leerChatIncremental(2, 1, 0, $last['mensajes'][0]['id']);
        self::assertLessThan($last['mensajes'][0]['id'], end($older['mensajes'])['id']);
    }
    public function testReadingOnlyAcknowledgesReceivedMessagesUpToCursor(): void
    {
        $a = $this->send();
        $b = $this->send();
        marcarChatHasta(2, 1, $a['id']);
        $st = getDB()->prepare('SELECT id,leido FROM mensajes WHERE id IN (?,?) ORDER BY id');
        $st->execute([$a['id'],$b['id']]);
        self::assertSame([1,0], array_map('intval', array_column($st->fetchAll(), 'leido')));
        self::assertSame($a['id'], leerChatIncremental(1, 2)['leido']);
        getDB()->prepare('INSERT INTO musicmad_chat_preferences(usuario_id,confirmar_lectura) VALUES(2,0) ON DUPLICATE KEY UPDATE confirmar_lectura=0')->execute();
        self::assertSame(0, leerChatIncremental(1, 2)['leido']);
    }
    public function testBlockedHistoryAndSendAreUnavailable(): void
    {
        $this->send();
        getDB()->prepare("INSERT INTO usuarios_bloqueados(usuario_id,objetivo_id,modo) VALUES(2,1,'bloquear')")->execute();
        $this->expectException(OutOfBoundsException::class);
        leerChatIncremental(1, 2);
    }
    public function testPresenceRequiresOptInAndTypingExpires(): void
    {
        actividadChat(2, 1, true);
        $result = leerChatIncremental(1, 2);
        self::assertFalse($result['en_linea']);
        self::assertTrue($result['escribiendo']);
        getDB()->prepare('INSERT INTO musicmad_chat_preferences(usuario_id,mostrar_presencia) VALUES(2,1) ON DUPLICATE KEY UPDATE mostrar_presencia=1')->execute();
        self::assertTrue(leerChatIncremental(1, 2)['en_linea']);
        getDB()->prepare('UPDATE musicmad_chat_activity SET escribiendo_hasta=UTC_TIMESTAMP()-INTERVAL 1 SECOND WHERE usuario_id=2')->execute();
        self::assertFalse(leerChatIncremental(1, 2)['escribiendo']);
    }
    public function testTrackAttachmentIsRevalidatedAfterPrivacyChange(): void
    {
        $m = enviarChatSeguro(1, 2, 'Escucha', bin2hex(random_bytes(16)), 1);
        self::assertSame(1, $m['pista']['id']);
        getDB()->prepare("INSERT INTO perfiles_privacidad(usuario_id,visibilidad) VALUES(1,'privado') ON DUPLICATE KEY UPDATE visibilidad='privado'")->execute();
        getDB()->prepare('DELETE FROM seguidores WHERE seguidor_id=2 AND seguido_id=1')->execute();
        $result = leerChatIncremental(2, 1, $m['id'] - 1);
        self::assertNull($result['mensajes'][0]['pista']);
    }
    public function testTrackMustBeAccessibleToRecipientWhenSent(): void
    {
        getDB()->prepare("INSERT INTO perfiles_privacidad(usuario_id,visibilidad) VALUES(1,'privado') ON DUPLICATE KEY UPDATE visibilidad='privado'")->execute();
        getDB()->prepare('DELETE FROM seguidores WHERE seguidor_id=2 AND seguido_id=1')->execute();
        $this->expectException(OutOfBoundsException::class);
        enviarChatSeguro(1, 2, 'Escucha', bin2hex(random_bytes(16)), 1);
    }
    public function testStreamLeaseAllowsOnlyOneConnectionPerAccountAndExpires(): void
    {
        $a = reservarStreamChat(1);
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $a);
        self::assertSame('', reservarStreamChat(1));
        self::assertNotSame('', reservarStreamChat(2));
        getDB()->prepare('UPDATE musicmad_chat_stream_leases SET hasta=UTC_TIMESTAMP()-INTERVAL 1 SECOND WHERE usuario_id=1')->execute();
        self::assertNotSame($a, reservarStreamChat(1));
    }
    public function testStreamStopsAfterDeviceRevocation(): void
    {
        $db = getDB();
        $selector = bin2hex(random_bytes(16));
        $hash = hash('sha256', 'QA stream');
        $version = versionAccesoCuenta(1);
        $db->prepare("INSERT INTO sesiones_dispositivos(usuario_id,selector,etiqueta,ultimo_uso) VALUES(1,?,'QA stream',UTC_TIMESTAMP())")->execute([$selector]);
        $db->prepare('INSERT INTO musicmad_session_bindings(selector,session_hash,version_acceso,expira_en) VALUES(?,?,?,UTC_TIMESTAMP()+INTERVAL 1 HOUR)')->execute([$selector,$hash,$version]);
        self::assertTrue(sesionStreamChatValida(1, $selector, $hash, $version));
        $db->prepare('UPDATE sesiones_dispositivos SET revocada_en=UTC_TIMESTAMP() WHERE selector=?')->execute([$selector]);
        self::assertFalse(sesionStreamChatValida(1, $selector, $hash, $version));
    }
    public function testSendRateLimitCannotBeBypassedByNewRequestIDs(): void
    {
        getDB()->prepare("INSERT INTO musicmad_chat_limits(usuario_id,bucket,tipo,solicitudes) VALUES(1,DATE_FORMAT(UTC_TIMESTAMP(),'%Y-%m-%d %H:%i:00'),'enviar',30) ON DUPLICATE KEY UPDATE solicitudes=30")->execute();
        $this->expectException(OverflowException::class);
        $this->send();
    }
    public function testNoticeReadingIsBoundToOwnerAndShownCursor(): void
    {
        $db = getDB();
        $db->prepare("INSERT INTO notificaciones(usuario_id,actor_id,tipo) VALUES(2,1,'seguidor')")->execute();
        $first = (int)$db->lastInsertId();
        $db->prepare("INSERT INTO notificaciones(usuario_id,actor_id,tipo) VALUES(2,1,'seguidor')")->execute();
        $second = (int)$db->lastInsertId();
        marcarAvisosHasta(1, $second);
        marcarAvisosHasta(2,$first);
        $st = $db->prepare('SELECT leida FROM notificaciones WHERE id IN (?,?) ORDER BY id');
        $st->execute([$first,$second]);
        self::assertSame([1,0],array_map('intval',$st->fetchAll(PDO::FETCH_COLUMN)));
    }
}
