<?php

use PHPUnit\Framework\TestCase;

final class EventWorkspaceIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        if (getenv('MM_RUN_QA') !== '1') {
            $this->markTestSkipped('Solo QA aislada.');
        }
        self::assertSame('musicmad_qa', DB_NAME);
        getDB()->beginTransaction();
        getDB()->exec('UPDATE musicmad_event_preferences SET aviso=0');
        getDB()->exec('UPDATE notificaciones_ext SET leida_en=UTC_TIMESTAMP()');
    }
    protected function tearDown(): void
    {
        if (getenv('MM_RUN_QA') === '1' && getDB()->inTransaction()) {
            getDB()->rollBack();
        }
    }
    private function data(array $changes = []): array
    {
        return $changes + ['titulo' => 'Jam QA','descripcion' => 'Descripción privada QA','ciudad' => 'Guadalajara','sede' => 'Foro ficticio','zona' => 'America/Mexico_City','inicio' => (new DateTimeImmutable('+45 minutes', new DateTimeZone('America/Mexico_City')))->format('Y-m-d\TH:i'),'duracion' => 180];
    }
    private function create(): int
    {
        return guardarEventoWorkspace(1, $this->data());
    }
    public function testUTCAndLocalCompatibilityDurationAndLegacyRecord(): void
    {
        $data = $this->data(['zona' => 'America/Tijuana','inicio' => (new DateTimeImmutable('+45 minutes', new DateTimeZone('America/Tijuana')))->format('Y-m-d\TH:i')]);
        $id = guardarEventoWorkspace(1, $data);
        $event = eventoDisponiblePara(2, $id);
        self::assertSame('America/Tijuana', $event['zona']);
        $start = fechaEnsayo($data['inicio'], 'America/Tijuana')->setTimezone(new DateTimeZone('UTC'));
        self::assertSame($start->format('Y-m-d H:i:s'), $event['inicio_utc']);
        self::assertSame(10800, strtotime($event['fin_utc'] . ' UTC') - strtotime($event['inicio_utc'] . ' UTC'));
        getDB()->exec("INSERT INTO eventos_musicales(creador_id,titulo,ciudad,inicio) VALUES(1,'Anterior QA','Guadalajara','2027-01-02 18:00:00')");
        $legacy = eventoDisponiblePara(2, (int)getDB()->lastInsertId());
        self::assertSame('2027-01-03 00:00:00', $legacy['inicio_utc']);
        self::assertSame('America/Mexico_City', $legacy['zona']);
    }
    public function testOnlyOrganizerCanEditOrCancel(): void
    {
        $id = $this->create();
        try {
            guardarEventoWorkspace(2, $this->data(), $id);
            self::fail();
        } catch (OutOfBoundsException $e) {
            self::assertNotSame('', $e->getMessage());
        }
        $this->expectException(OutOfBoundsException::class);
        cancelarEventoWorkspace(2, $id);
    }
    public function testRSVPAndReminderAreOptInAndDeduplicated(): void
    {
        $id = $this->create();
        responderEventoWorkspace(2, $id, 'asistire', false);
        self::assertSame(0, generarRecordatoriosEventos());
        responderEventoWorkspace(2, $id, 'asistire', true);
        self::assertSame(1, generarRecordatoriosEventos());
        self::assertSame(0, generarRecordatoriosEventos());
        $notice = array_values(array_filter(listarAvisosEventos(2), fn ($n) => (int)$n['referencia_id'] === $id))[0];
        self::assertSame('evento_recordatorio', $notice['tipo']);
        self::assertTrue(avisoEventoVigente(2, $notice));
        self::assertSame(1, contarAvisosEventos(2));
        marcarAvisosEventosHasta(1, (int)$notice['id']);
        self::assertSame(1, contarAvisosEventos(2));
        marcarAvisosEventosHasta(2, (int)$notice['id']);
        self::assertSame(0, contarAvisosEventos(2));
    }
    public function testRescheduleInvalidatesQueuedReminderAndCreatesChangedNotice(): void
    {
        $id = $this->create();
        responderEventoWorkspace(2, $id, 'interesado', true);
        generarRecordatoriosEventos();
        $old = listarAvisosEventos(2)[0];
        guardarEventoWorkspace(1, $this->data(['titulo' => 'Nueva hora','inicio' => (new DateTimeImmutable('+50 minutes', new DateTimeZone('America/Mexico_City')))->format('Y-m-d\TH:i')]), $id);
        self::assertFalse(avisoEventoVigente(2, $old));
        self::assertContains('evento_cambio', array_column(listarAvisosEventos(2), 'tipo'));
        self::assertSame(1, generarRecordatoriosEventos());
    }
    public function testCancellationStopsRemindersButRetainsCancelledEventForAttendees(): void
    {
        $id = $this->create();
        responderEventoWorkspace(2, $id, 'asistire', true);
        generarRecordatoriosEventos();
        $old = listarAvisosEventos(2)[0];
        cancelarEventoWorkspace(1, $id);
        self::assertFalse(avisoEventoVigente(2, $old));
        self::assertNull(eventoDisponiblePara(2, $id));
        self::assertSame('cancelado', eventoDisponiblePara(2, $id, true)['estado']);
        self::assertContains($id, array_map('intval', array_column(listarEventosWorkspace(2, ['ver' => 'agenda']), 'id')));
        self::assertSame(0, generarRecordatoriosEventos());
        $this->expectException(OutOfBoundsException::class);
        responderEventoWorkspace(2, $id, 'interesado', true);
    }
    public function testLeavingAndPrivateOrganizerRevokeQueuedNotices(): void
    {
        $id = $this->create();
        responderEventoWorkspace(2, $id, 'asistire', true);
        generarRecordatoriosEventos();
        $notice = listarAvisosEventos(2)[0];
        responderEventoWorkspace(2, $id, 'quitar', false);
        self::assertFalse(avisoEventoVigente(2, $notice));
        self::assertSame(0, contarAvisosEventos(2));
        responderEventoWorkspace(2, $id, 'asistire', true);
        getDB()->exec("INSERT INTO perfiles_privacidad(usuario_id,visibilidad) VALUES(1,'privado') ON DUPLICATE KEY UPDATE visibilidad='privado'");
        getDB()->exec('DELETE FROM seguidores WHERE seguidor_id=2 AND seguido_id=1');
        self::assertNull(eventoDisponiblePara(2, $id));
        self::assertSame(0, contarAvisosEventos(2));
    }
    public function testLegacyFunctionsUseSecureWorkspaceAndInvalidDurationFails(): void
    {
        $created = crearEventoSocial3(1, $this->data());
        self::assertTrue($created['ok']);
        self::assertTrue(actualizarRsvpEventoSocial3(2,$created['id'],'asistire'));
        self::assertTrue(cancelarEventoSocial3(1,$created['id']));
        self::assertFalse(actualizarRsvpEventoSocial3(2,$created['id'],'asistire'));
        $this->expectException(InvalidArgumentException::class);
        guardarEventoWorkspace(1,$this->data(['duracion' => -1]));
    }
}
