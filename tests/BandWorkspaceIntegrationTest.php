<?php

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/includes/calendar_export.php';
final class BandWorkspaceIntegrationTest extends TestCase
{
    private int $band = 0;
    protected function setUp(): void
    {
        if (getenv('MM_RUN_QA') !== '1') {
            $this->markTestSkipped('Solo QA aislada.');
        }
        self::assertSame('musicmad_qa', DB_NAME);
        getDB()->beginTransaction();
        getDB()->exec('UPDATE musicmad_band_rsvp SET aviso=0');
        $r = crearBanda(1, ['nombre' => 'Colectivo QA ' . bin2hex(random_bytes(5)),'genero' => 'Rock','ciudad' => 'Guadalajara','instrumento' => 'Guitarra']);
        self::assertTrue($r['ok']);
        $this->band = $r['id'];
    }
    protected function tearDown(): void
    {
        if (getenv('MM_RUN_QA') === '1' && getDB()->inTransaction()) {
            getDB()->rollBack();
        }
    }
    private function join(int $uid = 2): void
    {
        self::assertTrue(unirseBandaWorkspace($uid, $this->band, 'Batería'));
    }
    private function setting(string $entry = 'invitacion'): void
    {
        editarBandaWorkspace(1, $this->band, ['nombre' => 'QA ' . bin2hex(random_bytes(5)),'genero' => 'Rock','ciudad' => 'Guadalajara','descripcion' => 'Ensayo QA','ingreso' => $entry,'color' => 'azul']);
    }
    private function event(int $actor = 1, int $minutes = 45): int
    {
        return guardarEnsayoBanda($actor, $this->band, ['titulo' => 'Ensayo QA','lugar' => 'Lugar privado','notas' => 'Nota privada','zona' => 'America/Mexico_City','inicio' => (new DateTimeImmutable('+' . $minutes . ' minutes', new DateTimeZone('America/Mexico_City')))->format('Y-m-d\TH:i'),'duracion' => 120]);
    }
    public function testInvitationOnlyEntryAndAcceptAreBoundToReceiverAndCurrentAdministrator(): void
    {
        $this->setting();
        self::assertFalse(unirseBanda(2, $this->band, 'Voz'));
        invitarABanda(1, $this->band, 2);
        invitarABanda(1, $this->band, 2);
        self::assertCount(1, array_filter(invitacionesBandaPara(2), fn ($invite) => (int)$invite['banda_id'] === $this->band));
        self::assertCount(0, invitacionesBandaPara(3));
        try {
            responderInvitacionBanda(3, $this->band, true);
            self::fail('La invitación es personal.');
        } catch (OutOfBoundsException $error) {
            self::assertNull(rolEnBanda(3, $this->band));
        }
        responderInvitacionBanda(2, $this->band, true, 'Batería');
        self::assertSame('miembro', rolEnBanda(2, $this->band));
        self::assertCount(0, array_filter(invitacionesBandaPara(2), fn ($invite) => (int)$invite['banda_id'] === $this->band));
        $this->expectException(OutOfBoundsException::class);
        responderInvitacionBanda(2, $this->band, true);
    }
    public function testExpiredOrCancelledInviteCannotBeAccepted(): void
    {
        invitarABanda(1, $this->band, 2);
        cancelarInvitacionBanda(1, $this->band, 2);
        try {
            responderInvitacionBanda(2, $this->band, true);
            self::fail();
        } catch (OutOfBoundsException $e) {
            self::assertNull(rolEnBanda(2, $this->band));
        }
        invitarABanda(1, $this->band, 2);
        getDB()->prepare('UPDATE musicmad_band_invites SET expira_en=UTC_TIMESTAMP()-INTERVAL 1 SECOND WHERE banda_id=?')->execute([$this->band]);
        $this->expectException(OutOfBoundsException::class);
        responderInvitacionBanda(2, $this->band, true);
    }
    public function testBlockedInviterCannotAcceptOrShowInvite(): void
    {
        invitarABanda(1, $this->band, 2);
        getDB()->exec("INSERT INTO usuarios_bloqueados(usuario_id,objetivo_id,modo) VALUES(2,1,'bloquear')");
        self::assertCount(0, invitacionesBandaPara(2));
        $this->expectException(OutOfBoundsException::class);
        responderInvitacionBanda(2, $this->band, true);
    }
    public function testCoordinatorCanScheduleButCannotInviteOrChangeRoles(): void
    {
        $this->join();
        cambiarRolBanda(1, $this->band, 2, 'coordinador');
        self::assertSame('coordinador', rolEnBanda(2, $this->band));
        $event = $this->event(2);
        self::assertGreaterThan(0, $event);
        self::assertCount(1, ensayosBanda(1, $this->band));
        foreach (['invite','role'] as $action) {
            try {
                if ($action === 'invite') {
                    invitarABanda(2, $this->band, 3);
                } else {
                    cambiarRolBanda(2, $this->band, 2, 'admin');
                }
                self::fail('Coordinador no administra.');
            } catch (OutOfBoundsException $e) {
                self::assertSame('coordinador', rolEnBanda(2, $this->band));
            }
        }
    }
    public function testLastAdministratorCannotDemoteAndDepartureTransfersOwnership(): void
    {
        try {
            cambiarRolBanda(1, $this->band, 1, 'miembro');
            self::fail();
        } catch (LogicException $e) {
            self::assertSame('admin', rolEnBanda(1, $this->band));
        }
        $this->join();
        salirBanda(1, $this->band);
        self::assertSame('admin', rolEnBanda(2, $this->band));
        self::assertSame(2, (int)obtenerBanda($this->band)['creador_id']);
        self::assertNotNull(bandaDisponible(2, $this->band));
    }
    public function testLastDepartureArchivesAndRestorationPreservesContent(): void
    {
        $p = crearPublicacion(1, ['banda_id' => $this->band,'titulo' => 'Pista conservada','tipo' => 'texto']);
        self::assertTrue($p['ok']);
        salirBanda(1, $this->band);
        self::assertNull(bandaDisponible(1, $this->band));
        self::assertNotNull(obtenerBanda($this->band));
        self::assertNotEmpty(bandasArchivadasPara(1));
        archivarBandaWorkspace(1, $this->band, true);
        self::assertSame('admin', rolEnBanda(1, $this->band));
        self::assertSame($p['id'], publicacionesBanda(1, $this->band)[0]['id']);
    }
    public function testBandPostsAreDistinctFromMemberPersonalFeedAndPreservePrivacy(): void
    {
        $this->join();
        crearPublicacion(1, ['titulo' => 'Personal','tipo' => 'texto']);
        $p = crearPublicacion(2, ['banda_id' => $this->band,'titulo' => 'Banda','tipo' => 'texto']);
        self::assertTrue($p['ok']);
        self::assertCount(1, publicacionesBanda(1, $this->band));
        getDB()->exec("INSERT INTO perfiles_privacidad(usuario_id,visibilidad) VALUES(2,'privado') ON DUPLICATE KEY UPDATE visibilidad='privado'");
        getDB()->exec('DELETE FROM seguidores WHERE seguidor_id=1 AND seguido_id=2');
        self::assertCount(0, publicacionesBanda(1, $this->band));
        self::assertCount(1, publicacionesBanda(2, $this->band));
        expulsarMiembroWorkspace(1, $this->band, 2);
        self::assertFalse(crearPublicacion(2, ['banda_id' => $this->band,'titulo' => 'No autorizado','tipo' => 'texto'])['ok']);
    }
    public function testGroupChatRetriesAreIdempotentAndCursorCannotReadFutureMessages(): void
    {
        $this->join();
        $request = bin2hex(random_bytes(16));
        $a = enviarChatBanda(1, $this->band, 'Mensaje', $request, 1);
        $b = enviarChatBanda(1, $this->band, 'Mensaje', $request, 1);
        self::assertSame($a['id'], $b['id']);
        self::assertSame(1, $a['pista']['id']);
        $first = leerChatBanda(2, $this->band);
        self::assertCount(1, $first['mensajes']);
        self::assertSame(0, leerChatBanda(1, $this->band)['lecturas']->{$a['id']});
        marcarChatBanda(2, $this->band, PHP_INT_MAX);
        self::assertSame(1, leerChatBanda(1, $this->band)['lecturas']->{$a['id']});
        self::assertContains($a['id'], leerChatBanda(1, $this->band, $a['id'])['cursores_lectura']);
        $c = enviarChatBanda(1, $this->band, 'Nuevo', bin2hex(random_bytes(16)));
        self::assertSame(0, leerChatBanda(1, $this->band)['lecturas']->{$c['id']});
        $this->expectException(LogicException::class);
        enviarChatBanda(1, $this->band, 'Cambió', $request, 1);
    }
    public function testChatReadPreferencesAndTrackPrivacyAreRechecked(): void
    {
        $this->join();
        $a = enviarChatBanda(1, $this->band, 'Pista', bin2hex(random_bytes(16)), 1);
        marcarChatBanda(2, $this->band, $a['id']);
        guardarPreferenciasChat(2, ['mostrar_presencia' => false,'mostrar_escribiendo' => true,'confirmar_lectura' => false]);
        self::assertSame(0, leerChatBanda(1, $this->band)['lecturas']->{$a['id']});
        getDB()->exec("INSERT INTO perfiles_privacidad(usuario_id,visibilidad) VALUES(1,'privado') ON DUPLICATE KEY UPDATE visibilidad='privado'");
        getDB()->exec('DELETE FROM seguidores WHERE seguidor_id=2 AND seguido_id=1');
        self::assertNull(leerChatBanda(2, $this->band)['mensajes'][0]['pista']);
    }
    public function testNonmembersAndRemovedMembersCannotReadChatOrAgenda(): void
    {
        foreach (['chat','agenda'] as $type) {
            try {
                if ($type === 'chat') {
                    leerChatBanda(2, $this->band);
                } else {
                    ensayosBanda(2, $this->band);
                }
                self::fail();
            } catch (OutOfBoundsException $e) {
                self::assertNotSame('', $e->getMessage());
            }
        }
        $this->join();
        enviarChatBanda(1, $this->band, 'Privado', bin2hex(random_bytes(16)));
        expulsarMiembroWorkspace(1, $this->band, 2);
        $this->expectException(OutOfBoundsException::class);
        leerChatBanda(2, $this->band);
    }
    public function testAgendaResponseCannotTargetAnotherBandAndCancellationRejectsRSVP(): void
    {
        $this->join();
        $event = $this->event();
        responderEnsayoBanda(2, $this->band, $event, 'si', true);
        $e = ensayosBanda(2, $this->band)[0];
        self::assertSame('si', $e['respuesta']);
        self::assertSame(1, (int)$e['asistentes']);
        cancelarEnsayoBanda(1, $this->band, $event);
        self::assertSame('cancelado', ensayosBanda(2, $this->band)[0]['estado']);
        $this->expectException(OutOfBoundsException::class);
        responderEnsayoBanda(2, $this->band, $event, 'si', true);
    }
    public function testInvalidDateAndNonexistentDSTTimeAreRejected(): void
    {
        foreach (['2026-02-31T12:00','2026-03-08T02:30'] as $value) {
            try {
                fechaEnsayo($value, 'America/New_York');
                self::fail();
            } catch (InvalidArgumentException $e) {
                self::assertNotSame('', $e->getMessage());
            }
        }
        self::assertSame('2026-10-13T16:00', fechaEnsayo('2026-10-13T16:00', 'America/Mexico_City')->format('Y-m-d\TH:i'));
    }
    public function testRemindersAreOptInDeduplicatedAndRevokedAfterCancellation(): void
    {
        $this->join();
        $event = $this->event();
        responderEnsayoBanda(2, $this->band, $event, 'si', false);
        self::assertSame(0, generarRecordatoriosBandas());
        responderEnsayoBanda(2, $this->band, $event, 'si', true);
        self::assertSame(1, generarRecordatoriosBandas());
        self::assertSame(0, generarRecordatoriosBandas());
        $st = getDB()->prepare("SELECT n.*,bn.subtipo,bn.referencia_id FROM notificaciones n JOIN musicmad_band_notifications bn ON bn.notificacion_id=n.id WHERE bn.subtipo='recordatorio' AND n.banda_id=?");
        $st->execute([$this->band]);
        $n = $st->fetch();
        self::assertTrue(avisosBandaDisponibles(2, $n));
        cancelarEnsayoBanda(1, $this->band, $event);
        self::assertFalse(avisosBandaDisponibles(2, $n));
    }
    public function testArchiveHidesBandAndItsNotificationsWithoutDeletingPosts(): void
    {
        $this->join();
        $this->event();
        self::assertNotEmpty(array_filter(listarNotificaciones(2), fn ($n) => (int)$n['banda_id'] === $this->band));
        archivarBandaWorkspace(1, $this->band);
        self::assertNull(bandaDisponible(2, $this->band));
        self::assertEmpty(array_filter(listarNotificaciones(2), fn ($n) => (int)$n['banda_id'] === $this->band));
        self::assertNotContains($this->band, array_column(listarBandasWorkspace(1), 'id'));
        $this->expectException(OutOfBoundsException::class);
        enviarChatBanda(2, $this->band, 'No', bin2hex(random_bytes(16)));
    }
    public function testInvitationCandidatesIncludeFollowedFriendsAndRespectPrivacy(): void
    {
        getDB()->prepare('INSERT IGNORE INTO seguidores(seguidor_id,seguido_id) VALUES(1,2)')->execute();
        self::assertContains(2, array_map('intval', array_column(candidatosInvitacionBanda(1, $this->band, 'Ana'), 'id')));
        $this->join();
        self::assertNotContains(2, array_column(candidatosInvitacionBanda(1, $this->band, 'Ana'), 'id'));
    }
    public function testOwnRehearsalReminderAndNewRevisionRemainUseful(): void
    {
        $event = $this->event();
        responderEnsayoBanda(1, $this->band, $event, 'si', true);
        self::assertSame(1, generarRecordatoriosBandas());
        $st = getDB()->prepare("SELECT n.*,bn.subtipo,bn.referencia_id FROM notificaciones n JOIN musicmad_band_notifications bn ON bn.notificacion_id=n.id WHERE n.banda_id=? AND bn.subtipo='recordatorio' ORDER BY n.id");
        $st->execute([$this->band]);
        $old = $st->fetch();
        self::assertTrue(avisosBandaDisponibles(1,$old));
        $data = ['titulo' => 'Hora cambiada','zona' => 'America/Mexico_City','inicio' => (new DateTimeImmutable('+50 minutes',new DateTimeZone('America/Mexico_City')))->format('Y-m-d\\TH:i'),'duracion' => 120];
        guardarEnsayoBanda(1,$this->band,$data,$event);
        self::assertFalse(avisosBandaDisponibles(1,$old));
        self::assertSame(1,generarRecordatoriosBandas());
    }
    public function testICSFoldingEscapesAndNeverSplitsUnicode(): void
    {
        $value = textoCalendario("Ensayo, música;\n" . str_repeat('á',70));
        $ics = exportarCalendario(['BEGIN:VCALENDAR','SUMMARY:' . $value,'END:VCALENDAR']);
        foreach (explode("\r\n",trim($ics)) as $line) {
            self::assertLessThanOrEqual(75,strlen($line));
            self::assertTrue(mb_check_encoding($line,'UTF-8'));
        }
        self::assertStringContainsString('Ensayo\\, música\\;\\n',str_replace("\r\n ",'',$ics));
    }
}
