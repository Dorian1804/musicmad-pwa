<?php

use PHPUnit\Framework\TestCase;

final class CreativeStudioIntegrationTest extends TestCase
{
    private array $users = [];
    private array $pubs = [];
    protected function setUp(): void
    {
        if (getenv('MM_RUN_QA') !== '1') {
            $this->markTestSkipped('Solo QA aislada.');
        }
        self::assertSame('musicmad_qa', DB_NAME);
        foreach (['Creador','Colaborador'] as $name) {
            getDB()->prepare('INSERT INTO usuarios(nombre,apellido,email,REPLACE_WITH_LOCAL_VALUE) VALUES(?,?,?,?)')->execute([$name,'QA','creative_' . bin2hex(random_bytes(8)) . '@musicmad.invalid',REPLACE_WITH_LOCAL_VALUE_hash('MusicMad_Creative_QA_42!', PASSWORD_BCRYPT)]);
            $uid = (int)getDB()->lastInsertId();
            $this->users[] = $uid;
            getDB()->prepare("INSERT INTO publicaciones(usuario_id,tipo,titulo) VALUES(?,'demo','Estudio QA')")->execute([$uid]);
            $pub = (int)getDB()->lastInsertId();
            $this->pubs[] = $pub;
            getDB()->prepare('INSERT INTO publicacion_audio(publicacion_id,archivo,bytes) SELECT ?,archivo,bytes FROM publicacion_audio WHERE publicacion_id=9')->execute([$pub]);
            getDB()->prepare('INSERT INTO publicacion_medios(publicacion_id,sha256,duracion,mime,ancho,alto,codec) SELECT ?,sha256,duracion,mime,ancho,alto,codec FROM publicacion_medios WHERE publicacion_id=9')->execute([$pub]);
        }
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
    private function project(): int
    {
        return crearColaboracion($this->users[0], ['titulo' => 'Proyecto QA','descripcion' => 'Probando versiones','pista' => $this->pubs[0]]);
    }
    private function denied(callable $operation): void
    {
        try {
            $operation();
            self::fail('Debe denegar.');
        } catch (OutOfBoundsException $e) {
            self::assertNotEmpty($e->getMessage());
        }
    }
    public function testBaseMustBeOwnedAndContributionsHaveVersionsAndIdempotency(): void
    {
        [$owner,$guest] = $this->users;
        $this->denied(fn () => crearColaboracion($guest, ['titulo' => 'Base ajena','pista' => $this->pubs[0]]));
        $id = $this->project();
        $nonce = bin2hex(random_bytes(16));
        $input = ['pista' => $this->pubs[1],'titulo' => 'Batería','offset_ms' => 250,'volumen' => 65];
        $stem = aportarStemColab($guest, $id, $input, $nonce);
        self::assertSame($stem, aportarStemColab($guest, $id, $input, $nonce));
        $v2 = aportarStemColab($guest, $id, $input + ['version_de' => $stem], bin2hex(random_bytes(16)));
        $stems = stemsColabPara($owner, $id);
        self::assertCount(2, $stems);
        self::assertSame($stem, (int)$stems[0]['version_de']);
        self::assertSame(250, (int)$stems[0]['offset_ms']);
        try {
            aportarStemColab($guest, $id, array_replace($input, ['volumen' => 10]), $nonce);
            self::fail('Recibo debe corresponder.');
        } catch (UnexpectedValueException $e) {
        }
        $this->denied(fn () => aportarStemColab($owner, $id, ['pista' => $this->pubs[1],'titulo' => 'No es mía'], bin2hex(random_bytes(16))));
    }
    public function testClosedProjectRejectsNewTakesAndOnlyCreatorCanManage(): void
    {
        [$owner,$guest] = $this->users;
        $id = $this->project();
        $this->denied(fn () => cambiarEstadoColab($guest, $id, 'cerrada'));
        cambiarEstadoColab($owner, $id, 'cerrada');
        try {
            aportarStemColab($guest, $id, ['pista' => $this->pubs[1],'titulo' => 'Aporte tarde'], bin2hex(random_bytes(16)));
            self::fail('No admitir cerrado.');
        } catch (LogicException $e) {
        }
        cambiarEstadoColab($owner, $id, 'abierta');
        self::assertSame('abierta', colabDisponiblePara($guest, $id)['estado']);
        cambiarEstadoColab($owner, $id, 'publicada', $this->pubs[0]);
        self::assertSame($this->pubs[0], (int)colabDisponiblePara($guest, $id)['final_publicacion_id']);
    }
    public function testBothBaseAndTakePrivacyAreRechecked(): void
    {
        [$owner,$guest] = $this->users;
        $id = $this->project();
        aportarStemColab($guest, $id, ['pista' => $this->pubs[1],'titulo' => 'Mi voz'], bin2hex(random_bytes(16)));
        getDB()->prepare("INSERT INTO perfiles_privacidad(usuario_id,visibilidad) VALUES(?,'privado')")->execute([$guest]);
        self::assertSame([], stemsColabPara($owner, $id));
        self::assertCount(1, stemsColabPara($guest, $id));
        getDB()->prepare("INSERT INTO perfiles_privacidad(usuario_id,visibilidad) VALUES(?,'privado')")->execute([$owner]);
        self::assertNull(colabDisponiblePara($guest, $id));
        self::assertNotContains($id, array_map(fn ($row) => (int)$row['id'], listarColaboraciones($guest)));
    }
    public function testClipsAreBoundToOwnedSourceAndAtMostThirtySeconds(): void
    {
        [$owner,$guest] = $this->users;
        $this->denied(fn () => crearClipMusical($guest, ['pista' => $this->pubs[0],'inicio' => 0,'duracion' => 10], bin2hex(random_bytes(16))));
        foreach ([[0,31],[50,11],[INF,10],[1e30,10],[-1,10]] as [$start,$duration]) {
            try {
                crearClipMusical($owner, ['pista' => $this->pubs[0],'inicio' => $start,'duracion' => $duration], bin2hex(random_bytes(16)));
                self::fail('Límites reales.');
            } catch (InvalidArgumentException $e) {
            }
        }
        $data = ['pista' => $this->pubs[0],'inicio' => '2.345','duracion' => 20,'texto' => 'Mi ensayo'];
        $nonce = bin2hex(random_bytes(16));
        $id = crearClipMusical($owner, $data, $nonce);
        self::assertSame($id, crearClipMusical($owner, $data, $nonce));
        $clip = clipDisponiblePara($guest, $id);
        self::assertSame(2345, (int)$clip['inicio_ms']);
        self::assertSame(20000, (int)$clip['duracion_ms']);
        $expiry = new DateTimeImmutable($clip['expira_en'], new DateTimeZone('UTC'));
        self::assertEqualsWithDelta(86400, $expiry->getTimestamp() - time(), 3);
        $this->denied(fn () => retirarClipMusical($guest, $id));
    }
    public function testClipExpiryAndCleanupPreserveOriginalAudio(): void
    {
        [$owner,$guest] = $this->users;
        $id = crearClipMusical($owner, ['pista' => $this->pubs[0],'inicio' => 0,'duracion' => 5], bin2hex(random_bytes(16)));
        $st = getDB()->prepare('SELECT archivo FROM publicacion_audio WHERE publicacion_id=?');
        $st->execute([$this->pubs[0]]);
        $file = $st->fetchColumn();
        $before = hash_file('sha256', AUDIO_DIR . '/' . $file);
        retirarClipMusical($owner, $id);
        self::assertNull(clipDisponiblePara($guest, $id));
        limpiarClipsMusicales();
        recogerMediosEliminados(30, 2);
        self::assertSame($before, hash_file('sha256', AUDIO_DIR . '/' . $file));
        self::assertSame(1, (int)getDB()->query('SELECT COUNT(*) FROM publicaciones WHERE id=' . $this->pubs[0])->fetchColumn());
    }
    public function testDeletedPublicationRemovesReferencesWithoutDeletingSharedBytes(): void
    {
        [$owner,$guest] = $this->users;
        $id = $this->project();
        aportarStemColab($guest, $id, ['pista' => $this->pubs[1],'titulo' => 'Mi versión'], bin2hex(random_bytes(16)));
        $clip = crearClipMusical($guest, ['pista' => $this->pubs[1],'inicio' => 0,'duracion' => 5], bin2hex(random_bytes(16)));
        eliminarPublicacion($this->pubs[1],$guest,false);
        self::assertSame([],stemsColabPara($owner,$id));
        self::assertNull(clipDisponiblePara($owner,$clip));
        self::assertSame(0,(int)getDB()->query('SELECT COUNT(*) FROM colaboracion_stems WHERE colaboracion_id=' . $id)->fetchColumn());
    }
}
