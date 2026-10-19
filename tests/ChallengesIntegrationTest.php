<?php

use PHPUnit\Framework\TestCase;

final class ChallengesIntegrationTest extends TestCase
{
    private array $users = [];
    private array $pubs = [];
    private int $id = 0;
    protected function setUp(): void
    {
        if (getenv('MM_RUN_QA') !== '1') {
            $this->markTestSkipped('Solo QA aislada.');
        }
        self::assertSame('musicmad_qa', DB_NAME);
        foreach (['Equipo','Uno','Dos','Oyente'] as $i => $name) {
            getDB()->prepare('INSERT INTO usuarios(nombre,apellido,email,REPLACE_WITH_LOCAL_VALUE,rol) VALUES(?,?,?,?,?)')->execute([$name,'QA','challenge_' . bin2hex(random_bytes(8)) . '@musicmad.invalid',REPLACE_WITH_LOCAL_VALUE_hash('MusicMad_Challenge_QA_42!', PASSWORD_BCRYPT),$i === 0 ? 'administrador' : 'cliente']);
            $this->users[] = (int)getDB()->lastInsertId();
        }
        foreach ([$this->users[1],$this->users[2]] as $uid) {
            getDB()->prepare("INSERT INTO publicaciones(usuario_id,tipo,titulo) VALUES(?,'demo','Idea challenge QA')")->execute([$uid]);
            $pub = (int)getDB()->lastInsertId();
            $this->pubs[] = $pub;
            getDB()->prepare('INSERT INTO publicacion_audio(publicacion_id,archivo,bytes) SELECT ?,archivo,bytes FROM publicacion_audio WHERE publicacion_id=9')->execute([$pub]);
            getDB()->prepare('INSERT INTO publicacion_medios(publicacion_id,sha256,duracion,mime,codec) SELECT ?,sha256,duracion,mime,codec FROM publicacion_medios WHERE publicacion_id=9')->execute([$pub]);
        }
        $this->id = crearReto($this->users[0], $this->data(), bin2hex(random_bytes(16)));
        getDB()->prepare('UPDATE retos_musicales SET inicio=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 HOUR) WHERE id=?')->execute([$this->id]);
    }
    private function data(): array
    {
        return ['titulo' => 'Tres notas QA','descripcion' => 'Una consigna original','inicio' => gmdate('Y-m-d\TH:i', time() + 3600),'votacion' => gmdate('Y-m-d\TH:i', time() + 86400),'fin' => gmdate('Y-m-d\TH:i', time() + 172800),'estado' => 'activo'];
    }
    private function voting(): void
    {
        getDB()->prepare('UPDATE musicmad_challenge_details SET votacion_desde=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 MINUTE) WHERE reto_id=?')->execute([$this->id]);
    }
    private function vote(): void
    {
        votarReto($this->users[3], $this->id, $this->users[1], bin2hex(random_bytes(16)));
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
    public function testEntryReplayOwnershipDurationPublicityAndDistinctVotingPhase(): void
    {
        [$staff,$one,$two,$listener] = $this->users;
        $nonce = bin2hex(random_bytes(16));
        participarReto($one, $this->id, $this->pubs[0], $nonce);
        participarReto($one, $this->id, $this->pubs[0], $nonce);
        self::assertCount(1, rankingReto($listener, $this->id));
        self::assertSame('participacion', retoPara($listener, $this->id)['fase']);
        try {
            votarReto($listener, $this->id, $one, bin2hex(random_bytes(16)));
            self::fail('Voting closed');
        } catch (LogicException $e) {
        }
        try {
            participarReto($two, $this->id, $this->pubs[0], bin2hex(random_bytes(16)));
            self::fail('Other owner');
        } catch (OutOfBoundsException $e) {
        }
        getDB()->prepare('UPDATE publicacion_medios SET duracion=181 WHERE publicacion_id=?')->execute([$this->pubs[1]]);
        try {
            participarReto($two, $this->id, $this->pubs[1], bin2hex(random_bytes(16)));
            self::fail('Too long');
        } catch (OutOfBoundsException $e) {
        }
        getDB()->prepare('UPDATE publicacion_medios SET duracion=60 WHERE publicacion_id=?')->execute([$this->pubs[1]]);
        getDB()->prepare("INSERT INTO perfiles_privacidad(usuario_id,visibilidad) VALUES(?,'privado')")->execute([$two]);
        try {
            participarReto($two, $this->id, $this->pubs[1], bin2hex(random_bytes(16)));
            self::fail('Private profile');
        } catch (OutOfBoundsException $e) {
        }
        $this->voting();
        try {
            participarReto($one, $this->id, $this->pubs[0], bin2hex(random_bytes(16)));
            self::fail('Entry closed');
        } catch (LogicException $e) {
        }
        participarReto($one, $this->id, $this->pubs[0], $nonce);
        self::assertCount(1, rankingReto($listener, $this->id));
    }
    public function testOneVoteChangeReplayRetractAndRejectOwnNonparticipantAndConflictingNonce(): void
    {
        [$staff,$one,$two,$listener] = $this->users;
        foreach ([0,1] as $i) {
            participarReto($this->users[$i + 1], $this->id, $this->pubs[$i], bin2hex(random_bytes(16)));
        }
        $this->voting();
        $nonce = bin2hex(random_bytes(16));
        votarReto($listener, $this->id, $one, $nonce);
        votarReto($listener, $this->id, $one, $nonce);
        self::assertSame($one, miVotoReto($listener, $this->id));
        self::assertSame(1, (int)rankingReto($listener, $this->id)[0]['votos']);
        try {
            votarReto($listener, $this->id, $two, $nonce);
            self::fail('Payload conflict');
        } catch (UnexpectedValueException $e) {
        }
        try {
            votarReto($one, $this->id, $one, bin2hex(random_bytes(16)));
            self::fail('Self vote');
        } catch (InvalidArgumentException $e) {
        }
        try {
            votarReto($one, $this->id, $listener, bin2hex(random_bytes(16)));
            self::fail('No entry');
        } catch (OutOfBoundsException $e) {
        }
        votarReto($listener, $this->id, $two, bin2hex(random_bytes(16)));
        self::assertSame($two, miVotoReto($listener, $this->id));
        self::assertSame($two, (int)rankingReto($listener, $this->id)[0]['usuario_id']);
        votarReto($listener, $this->id, 0, bin2hex(random_bytes(16)));
        self::assertSame(0, miVotoReto($listener, $this->id));
        self::assertSame(0, array_sum(array_column(rankingReto($listener, $this->id), 'votos')));
    }
    public function testRankingPrivacyBlocksHashAndInactiveVoterRevalidate(): void
    {
        [$staff,$one,$two,$listener] = $this->users;
        participarReto($one, $this->id, $this->pubs[0], bin2hex(random_bytes(16)));
        $this->voting();
        $this->vote();
        self::assertSame(1, (int)rankingReto($two, $this->id)[0]['votos']);
        getDB()->prepare('UPDATE usuarios SET activo=0 WHERE id=?')->execute([$listener]);
        self::assertSame(0, (int)rankingReto($two, $this->id)[0]['votos']);
        getDB()->prepare('UPDATE usuarios SET activo=1 WHERE id=?')->execute([$listener]);
        getDB()->prepare("INSERT INTO usuarios_bloqueados(usuario_id,objetivo_id,modo) VALUES(?,?,'bloquear')")->execute([$one,$listener]);
        self::assertSame([], rankingReto($listener, $this->id));
        self::assertSame(0, (int)rankingReto($two, $this->id)[0]['votos']);
        try {
            $this->vote();
            self::fail('Blocked');
        } catch (OutOfBoundsException $e) {
        }
        getDB()->prepare('DELETE FROM usuarios_bloqueados WHERE usuario_id=?')->execute([$one]);
        getDB()->prepare("INSERT INTO perfiles_privacidad(usuario_id,visibilidad) VALUES(?,'seguidores')")->execute([$one]);
        self::assertSame([], rankingReto($two, $this->id));
        try {
            $this->vote();
            self::fail('No longer public');
        } catch (OutOfBoundsException $e) {
        }
        getDB()->prepare("UPDATE perfiles_privacidad SET visibilidad='publico' WHERE usuario_id=?")->execute([$one]);
        getDB()->prepare('UPDATE publicacion_medios SET sha256=? WHERE publicacion_id=?')->execute([str_repeat('a', 64),$this->pubs[0]]);
        self::assertSame([], rankingReto($two, $this->id));
        try {
            $this->vote();
            self::fail('Source changed');
        } catch (OutOfBoundsException $e) {
        }
        getDB()->prepare('UPDATE publicacion_medios SET sha256=(SELECT source.sha256 FROM (SELECT sha256 FROM publicacion_medios WHERE publicacion_id=9) source) WHERE publicacion_id=?')->execute([$this->pubs[0]]);
        getDB()->prepare("UPDATE publicacion_audio SET archivo='qa-not-present.mp3' WHERE publicacion_id=?")->execute([$this->pubs[0]]);
        self::assertSame([], rankingReto($two, $this->id));
    }
    public function testWithdrawalKeepsMediaAndVotesDisappearEvenAfterClosing(): void
    {
        [$staff,$one,$two,$listener] = $this->users;
        participarReto($one, $this->id, $this->pubs[0], bin2hex(random_bytes(16)));
        $this->voting();
        $this->vote();
        getDB()->prepare('UPDATE retos_musicales SET fin=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 SECOND) WHERE id=?')->execute([$this->id]);
        self::assertSame('resultados', retoPara($listener, $this->id)['fase']);
        try {
            $this->vote();
            self::fail('Final phase');
        } catch (LogicException $e) {
        }
        $nonce = bin2hex(random_bytes(16));
        retirarParticipacionReto($one, $this->id, $nonce);
        retirarParticipacionReto($one, $this->id, $nonce);
        self::assertSame([], rankingReto($listener, $this->id));
        self::assertSame(0, miVotoReto($listener, $this->id));
        self::assertNotEmpty(medioParaEstadisticas($one, $this->pubs[0]));
    }
    public function testStaffDraftPublishingCancellationAndFreshRoleGate(): void
    {
        [$staff,$one] = $this->users;
        $nonce = bin2hex(random_bytes(16));
        $data = $this->data();
        $data['estado'] = 'borrador';
        $id = crearReto($staff, $data, $nonce);
        self::assertSame($id, crearReto($staff, $data, $nonce));
        self::assertNull(retoPara($one, $id));
        self::assertNotNull(retoPara($staff, $id));
        try {
            administrarReto($one, $id, 'publicar');
            self::fail('Not staff');
        } catch (OutOfBoundsException $e) {
        }
        administrarReto($staff, $id, 'publicar');
        self::assertNotNull(retoPara($one, $id));
        getDB()->prepare("UPDATE usuarios SET rol='cliente' WHERE id=?")->execute([$staff]);
        try {
            administrarReto($staff, $id, 'cancelar');
            self::fail('Role removed');
        } catch (OutOfBoundsException $e) {
        }
        getDB()->prepare("UPDATE usuarios SET rol='administrador' WHERE id=?")->execute([$staff]);
        administrarReto($staff, $id, 'cancelar');
        self::assertSame('cancelado', retoPara($one, $id)['fase']);
        self::assertNotContains($id, array_column(listarRetos($one), 'id'));
    }
    public function testAutomaticWeeklyScheduleOptInIdempotenceAndRevokedOrganizer(): void
    {
        $db = getDB();
        $original = $db->query('SELECT * FROM musicmad_challenge_schedule WHERE id=1')->fetch();
        $week = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('monday this week')->format('Y-m-d');
        $st = $db->prepare('SELECT reto_id FROM musicmad_challenge_details WHERE semana=?');
        $st->execute([$week]);
        if ($st->fetchColumn()) {
            $this->markTestSkipped('La semana QA ya tiene una consigna; no se reemplaza.');
        }
        try {
            configurarRetosSemanales($this->users[0], false);
            self::assertSame(0, generarRetoSemanal());
            configurarRetosSemanales($this->users[0],true);
            $id = generarRetoSemanal();
            self::assertGreaterThan(0,$id);
            self::assertSame(0,generarRetoSemanal());
            self::assertSame($week,retoPara($this->users[1],$id)['semana']);
            getDB()->prepare("UPDATE usuarios SET rol='cliente' WHERE id=?")->execute([$this->users[0]]);
            self::assertSame(0,generarRetoSemanal());
        } finally {
            $db->prepare('UPDATE musicmad_challenge_schedule SET habilitado=?,responsable_id=? WHERE id=1')->execute([$original['habilitado'],$original['responsable_id']]);
        }
    }
}
