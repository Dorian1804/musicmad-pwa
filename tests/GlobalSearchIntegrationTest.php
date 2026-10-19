<?php

use PHPUnit\Framework\TestCase;

final class GlobalSearchIntegrationTest extends TestCase
{
    private int $uid = 0;
    private int $pub = 0;
    protected function setUp(): void
    {
        if (getenv('MM_RUN_QA') !== '1') {
            $this->markTestSkipped('Solo QA aislada.');
        }
        self::assertSame('musicmad_qa', DB_NAME);
        getDB()->beginTransaction();
        $db = getDB();
        $db->prepare('INSERT INTO usuarios(nombre,apellido,email,REPLACE_WITH_LOCAL_VALUE) VALUES(?,?,?,?)')->execute(['Fuzzmusician','Qatest','search-' . bin2hex(random_bytes(8)) . '@musicmad.invalid',REPLACE_WITH_LOCAL_VALUE_hash('Search_QA_42!', PASSWORD_BCRYPT)]);
        $this->uid = (int)$db->lastInsertId();
        $db->prepare('INSERT INTO perfiles_musicales(usuario_id,instrumento,generos,ciudad) VALUES(?,?,?,?)')->execute([$this->uid,'Fuzzinstrument','Rock','Fuzzcity']);
        $this->pub = crearPublicacion($this->uid, ['tipo' => 'texto','titulo' => 'Fuzzsong','texto' => '#Fuzztag música #fuzzTAG'])['id'];
        $db->prepare('INSERT INTO bandas(nombre,creador_id,genero,ciudad) VALUES(?,?,?,?)')->execute(['Fuzzband ' . bin2hex(random_bytes(3)),$this->uid,'Rock','Fuzzcity']);
    }
    protected function tearDown(): void
    {
        if (getenv('MM_RUN_QA') === '1' && getDB()->inTransaction()) {
            getDB()->rollBack();
        }
    }
    public function testParserDoesNotAllowBooleanOperatorsFromUserInput(): void
    {
        [$query,$boolean] = consultaBusquedaGlobal('"Fuzz" +rock -* <script>');
        self::assertSame('+fuzz* +rock* +script*', $boolean);
        self::assertSame(['rock','música'], array_values(etiquetasEnTexto('#ROCK #rock #música bad#skip #a')));
    }
    public function testAvatarResultsCannotPointOutsideApprovedAssets(): void
    {
        self::assertSame(AVATAR_DEFECTO, avatarBusqueda(1, 'https://evil.invalid/track.png'));
        self::assertSame(AVATAR_DEFECTO, avatarBusqueda(1, 'uploads/avatars/../../config/local.php'));
        self::assertSame(AVATAR_DEFECTO, avatarBusqueda(2, 'uploads/avatars/1_' . str_repeat('a', 24) . '.jpg'));
        self::assertSame('img/avatares/musicos/m3.svg', avatarBusqueda(1, 'img/avatares/musicos/m3.svg'));
    }
    public function testActualFulltextIndexesReturnCommittedFixtures(): void
    {
        self::assertTrue(busquedaGlobalDisponible());
        self::assertContains(1, array_column(buscarGlobal(1, 'Emiliano')['musicos'], 'id'));
        self::assertContains(2, array_column(buscarGlobal(1, 'Baterista')['musicos'], 'id'));
        self::assertContains(1, array_column(buscarGlobal(1, 'riff')['publicaciones'], 'id'));
        self::assertSame('+emiliano*', consultaBusquedaGlobal('The Emiliano')[1]);
    }
    public function testSearchFindsIdentityInstrumentTrackBandAndTag(): void
    {
        // InnoDB FULLTEXT sees committed rows; uncommitted rows use the short LIKE path below.
        $results = buscarGlobal(1, 'Fu', 10);
        self::assertContains($this->uid, array_column($results['musicos'], 'id'));
        self::assertContains($this->pub, array_column($results['publicaciones'], 'id'));
        self::assertNotEmpty($results['bandas']);
        self::assertNotEmpty($results['hashtags']);
        $st = getDB()->prepare('SELECT COUNT(*) FROM publicacion_hashtags WHERE publicacion_id=?');
        $st->execute([$this->pub]);
        self::assertSame(1, (int)$st->fetchColumn());
        self::assertCount(1, publicacionesEtiqueta(1, 'fuzztag'));
    }
    public function testPrivateBlockedAndMutedContentDoesNotAppearInResultsOrTags(): void
    {
        getDB()->prepare("INSERT INTO perfiles_privacidad(usuario_id,visibilidad) VALUES(?,'privado')")->execute([$this->uid]);
        $result = buscarGlobal(1, 'Fu');
        self::assertNotContains($this->uid, array_column($result['musicos'], 'id'));
        self::assertNotContains($this->pub, array_column($result['publicaciones'], 'id'));
        self::assertEmpty(publicacionesEtiqueta(1, 'fuzztag'));
        getDB()->prepare('INSERT INTO seguidores(seguidor_id,seguido_id) VALUES(1,?)')->execute([$this->uid]);
        self::assertContains($this->uid, array_column(buscarGlobal(1, 'Fu')['musicos'], 'id'));
        getDB()->prepare("INSERT INTO usuarios_bloqueados(usuario_id,objetivo_id,modo) VALUES(1,?,'silenciar')")->execute([$this->uid]);
        self::assertNotContains($this->uid, array_column(buscarGlobal(1, 'Fu')['musicos'], 'id'));
        self::assertEmpty(publicacionesEtiqueta(1, 'fuzztag'));
    }
    public function testResultLimitsAndPublicResponseNeverExposeAccountSecrets(): void
    {
        $result = buscarGlobal(1, 'Fu', 1);
        foreach ($result as $rows) {
            self::assertLessThanOrEqual(1, count($rows));
        }
        $json = json_encode($result);
        foreach (['@musicmad.invalid','REPLACE_WITH_LOCAL_VALUE','email','rol'] as $secret) {
            self::assertStringNotContainsString($secret, $json);
        }
        self::assertSame(['musicos' => [],'publicaciones' => [],'bandas' => [],'hashtags' => [],'generos' => []], buscarGlobal(1, '*'));
    }
    public function testHashtagCursorDoesNotRepeatOrRevealOtherProfiles(): void
    {
        $new = crearPublicacion($this->uid, ['tipo' => 'texto','titulo' => 'Otra prueba','texto' => '#fuzztag'])['id'];
        $rows = publicacionesEtiqueta(1, 'fuzztag');
        self::assertSame($new, (int)$rows[0]['id']);
        $older = publicacionesEtiqueta(1,'fuzztag',$new);
        self::assertSame([$this->pub],array_map('intval',array_column($older,'id')));
        self::assertSame([],publicacionesEtiqueta(1,'../../config'));
    }
}
