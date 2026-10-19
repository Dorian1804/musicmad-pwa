<?php

use PHPUnit\Framework\TestCase;

final class ArtistStatisticsIntegrationTest extends TestCase
{
    private array $users = [];
    private int $pub = 0;
    protected function setUp(): void
    {
        if (getenv('MM_RUN_QA') !== '1') {
            $this->markTestSkipped('Solo QA aislada.');
        }
        self::assertSame('musicmad_qa', DB_NAME);
        foreach (['Artista','Oyente','Otro'] as $name) {
            getDB()->prepare('INSERT INTO usuarios(nombre,apellido,email,REPLACE_WITH_LOCAL_VALUE) VALUES(?,?,?,?)')->execute([$name,'QA','stats_' . bin2hex(random_bytes(8)) . '@musicmad.invalid',REPLACE_WITH_LOCAL_VALUE_hash('MusicMad_Stats_QA_42!', PASSWORD_BCRYPT)]);
            $this->users[] = (int)getDB()->lastInsertId();
        }
        getDB()->prepare("INSERT INTO publicaciones(usuario_id,tipo,titulo) VALUES(?,'demo','Stats own QA')")->execute([$this->users[0]]);
        $this->pub = (int)getDB()->lastInsertId();
        getDB()->prepare('INSERT INTO publicacion_audio(publicacion_id,archivo,bytes) SELECT ?,archivo,bytes FROM publicacion_audio WHERE publicacion_id=9')->execute([$this->pub]);
        getDB()->prepare('INSERT INTO publicacion_medios(publicacion_id,sha256,duracion,mime,codec) SELECT ?,sha256,duracion,mime,codec FROM publicacion_medios WHERE publicacion_id=9')->execute([$this->pub]);
    }
    protected function tearDown(): void
    {
        if (getDB()->inTransaction()) {
            getDB()->rollBack();
        }
        foreach ($this->users as $uid) {
            getDB()->prepare('DELETE FROM usuarios WHERE id=?')->execute([$uid]);
        }
    }
    private function mature(int $uid, string $nonce): void
    {
        getDB()->prepare('UPDATE musicmad_listening_receipts SET emitida_en=DATE_SUB(UTC_TIMESTAMP(6),INTERVAL 8 SECOND) WHERE usuario_id=? AND solicitud=?')->execute([$uid,$nonce]);
    }
    public function testConfirmedOnlyAfterPlaybackAndServerClockAndDailyDeduplication(): void
    {
        [$owner,$listener] = $this->users;
        $nonce = bin2hex(random_bytes(16));
        $ticket = iniciarEscuchaEstadistica($listener, $this->pub, $nonce);
        self::assertSame(5000, $ticket['umbral_ms']);
        self::assertSame($ticket, iniciarEscuchaEstadistica($listener, $this->pub, $nonce));
        self::assertFalse(confirmarEscuchaEstadistica($listener, $this->pub, $nonce, 5000)['confirmada']);
        self::assertSame(0, datosEstadisticasArtista($owner)['resumen']['escuchas']);
        try {
            confirmarEscuchaEstadistica($listener, $this->pub, $nonce, 4999);
            self::fail('Playback time must meet threshold');
        } catch (InvalidArgumentException $e) {
        }
        $this->mature($listener, $nonce);
        $done = confirmarEscuchaEstadistica($listener, $this->pub, $nonce, 5000);
        self::assertTrue($done['nueva']);
        self::assertTrue($done['confirmada']);
        self::assertFalse(confirmarEscuchaEstadistica($listener, $this->pub, $nonce, 5000)['nueva']);
        $again = bin2hex(random_bytes(16));
        iniciarEscuchaEstadistica($listener, $this->pub, $again);
        $this->mature($listener, $again);
        self::assertFalse(confirmarEscuchaEstadistica($listener, $this->pub, $again, 9000)['nueva']);
        $data = datosEstadisticasArtista($owner);
        self::assertSame(1, $data['resumen']['escuchas']);
        self::assertSame(1, $data['resumen']['oyentes']);
        self::assertCount(30, $data['serie']);
        self::assertSame(gmdate('Y-m-d'), $data['serie'][29]['fecha']);
    }
    public function testOwnershipPrivacyBlocksRevocationAndSourceHashAreRechecked(): void
    {
        [$owner,$listener,$other] = $this->users;
        self::assertTrue(iniciarEscuchaEstadistica($owner, $this->pub, bin2hex(random_bytes(16)))['ignorada']);
        $nonce = bin2hex(random_bytes(16));
        iniciarEscuchaEstadistica($listener, $this->pub, $nonce);
        $this->mature($listener, $nonce);
        try {
            confirmarEscuchaEstadistica($other, $this->pub, $nonce, 5000);
            self::fail('Receipt owner must match');
        } catch (OutOfBoundsException $e) {
        }
        getDB()->prepare("INSERT INTO perfiles_privacidad(usuario_id,visibilidad) VALUES(?,'privado')")->execute([$owner]);
        try {
            confirmarEscuchaEstadistica($listener, $this->pub, $nonce, 5000);
            self::fail('Privacy changed');
        } catch (OutOfBoundsException $e) {
        }
        getDB()->prepare("UPDATE perfiles_privacidad SET visibilidad='publico' WHERE usuario_id=?")->execute([$owner]);
        getDB()->prepare("INSERT INTO usuarios_bloqueados(usuario_id,objetivo_id,modo) VALUES(?,?,'bloquear')")->execute([$owner,$listener]);
        try {
            confirmarEscuchaEstadistica($listener, $this->pub, $nonce, 5000);
            self::fail('Block changed');
        } catch (OutOfBoundsException $e) {
        }
        getDB()->prepare('DELETE FROM usuarios_bloqueados WHERE usuario_id=?')->execute([$owner]);
        getDB()->prepare('UPDATE publicacion_medios SET sha256=? WHERE publicacion_id=?')->execute([str_repeat('a', 64),$this->pub]);
        try {
            confirmarEscuchaEstadistica($listener, $this->pub, $nonce, 5000);
            self::fail('Source changed');
        } catch (OutOfBoundsException $e) {
        }
        getDB()->prepare('UPDATE usuarios SET activo=0 WHERE id=?')->execute([$listener]);
        try {
            iniciarEscuchaEstadistica($listener, $this->pub, bin2hex(random_bytes(16)));
            self::fail('Actor inactive');
        } catch (OutOfBoundsException $e) {
        }
    }
    public function testShortThresholdExpiryAndNoPublicationsFromOtherArtistsInDashboard(): void
    {
        [$owner,$listener] = $this->users;
        getDB()->prepare('UPDATE publicacion_medios SET duracion=1 WHERE publicacion_id=?')->execute([$this->pub]);
        $nonce = bin2hex(random_bytes(16));
        self::assertSame(800, iniciarEscuchaEstadistica($listener, $this->pub, $nonce)['umbral_ms']);
        getDB()->prepare('UPDATE musicmad_listening_receipts SET expira_en=DATE_SUB(UTC_TIMESTAMP(6),INTERVAL 1 SECOND) WHERE usuario_id=? AND solicitud=?')->execute([$listener,$nonce]);
        try {
            confirmarEscuchaEstadistica($listener, $this->pub, $nonce, 900);
            self::fail('Expired receipt');
        } catch (DomainException $e) {
        }
        self::assertSame([], datosEstadisticasArtista($listener)['pistas']);
        self::assertCount(7, datosEstadisticasArtista($owner, 7)['serie']);
        self::assertCount(30, datosEstadisticasArtista($owner, 999)['serie']);
    }
    public function testAwardsReadDoesNotWriteAndQueuePersistsImmutableAchievement(): void
    {
        $uid = $this->users[0];
        $first = logrosArtista($uid);
        self::assertTrue($first['insignias'][0]['alcanzada']);
        self::assertSame(100, $first['puntos']);
        self::assertSame(0, (int)getDB()->query('SELECT COUNT(*) FROM musicmad_artist_awards WHERE usuario_id=' . $uid)->fetchColumn());
        solicitarHitosArtista($uid);
        procesarHitosArtistas(50, 10);
        $earned = logrosArtista($uid);
        self::assertNotNull($earned['insignias'][0]['obtenida_en']);
        $date = $earned['insignias'][0]['obtenida_en'];
        logrosArtista($uid, true);
        self::assertSame($date, logrosArtista($uid)['insignias'][0]['obtenida_en']);
        getDB()->prepare('DELETE FROM publicaciones WHERE id=?')->execute([$this->pub]);
        self::assertTrue(logrosArtista($uid)['insignias'][0]['alcanzada']);
        self::assertSame(100, logrosArtista($uid)['puntos']);
    }
    public function testBadgeVisibilityAndExportDoNotExposeListeningReceipts(): void
    {
        [$owner,$listener] = $this->users;
        self::assertNotEmpty(insigniasVisiblesArtista($listener, $owner));
        getDB()->prepare("INSERT INTO perfiles_privacidad(usuario_id,visibilidad) VALUES(?,'privado')")->execute([$owner]);
        self::assertSame([],insigniasVisiblesArtista($listener,$owner));
        $collections = coleccionesExportacionCuenta();
        self::assertArrayHasKey('musicmad_confirmed_plays',$collections);
        self::assertArrayHasKey('musicmad_artist_awards',$collections);
        self::assertArrayNotHasKey('musicmad_listening_receipts',$collections);
        self::assertArrayNotHasKey('musicmad_artist_refresh',$collections);
    }
}
