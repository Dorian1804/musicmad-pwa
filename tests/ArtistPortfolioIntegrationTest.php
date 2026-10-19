<?php

use PHPUnit\Framework\TestCase;

final class ArtistPortfolioIntegrationTest extends TestCase
{
    private int $uid = 0;
    private string $slug = '';
    private int $collisionUser = 0;
    protected function setUp(): void
    {
        if (getenv('MM_RUN_QA') !== '1') {
            $this->markTestSkipped('Solo QA aislada.');
        }
        self::assertSame('musicmad_qa', DB_NAME);
        $this->slug = 'qa_' . bin2hex(random_bytes(6));
        getDB()->prepare('INSERT INTO usuarios(nombre,apellido,email,REPLACE_WITH_LOCAL_VALUE) VALUES(?,?,?,?)')->execute(['Artista','QA',$this->slug . '@musicmad.invalid',REPLACE_WITH_LOCAL_VALUE_hash('MusicMad_Portfolio_QA_42!', PASSWORD_BCRYPT)]);
        $this->uid = (int)getDB()->lastInsertId();
    }
    protected function tearDown(): void
    {
        if (getDB()->inTransaction()) {
            getDB()->rollBack();
        }
        if ($this->uid) {
            getDB()->prepare('DELETE FROM usuarios WHERE id=?')->execute([$this->uid]);
        }
        if ($this->collisionUser) {
            getDB()->prepare('DELETE FROM usuarios WHERE id=?')->execute([$this->collisionUser]);
        }
    }
    private function input(array $extra = []): array
    {
        return $extra + ['slug' => $this->slug,'color' => 'azul','habilitado' => '1','destacadas' => []];
    }
    public function testPublicationIsOptInAndPrivacyAlwaysWins(): void
    {
        self::assertNull(portafolioPorAlias($this->slug));
        guardarPortafolio($this->uid, $this->input(['habilitado' => '']));
        self::assertNull(portafolioPorAlias($this->slug));
        self::assertNotNull(portafolioPorAlias($this->slug, $this->uid, true));
        guardarPortafolio($this->uid, $this->input());
        self::assertNotNull(portafolioPorAlias($this->slug));
        getDB()->prepare("INSERT INTO perfiles_privacidad(usuario_id,visibilidad) VALUES(?,'privado')")->execute([$this->uid]);
        self::assertNull(portafolioPorAlias($this->slug, 0));
        self::assertNull(portafolioPorAlias($this->slug, 2, true));
        self::assertFalse(portafolioPorAlias($this->slug, $this->uid, true)['publico']);
    }
    public function testCollisionCannotChangeAnotherUsersProfile(): void
    {
        getDB()->prepare('INSERT INTO usuarios(nombre,apellido,email,REPLACE_WITH_LOCAL_VALUE) VALUES(?,?,?,?)')->execute(['Otra','QA','other_' . $this->slug . '@musicmad.invalid',REPLACE_WITH_LOCAL_VALUE_hash('MusicMad_Other_QA_42!', PASSWORD_BCRYPT)]);
        $this->collisionUser = (int)getDB()->lastInsertId();
        getDB()->prepare('INSERT INTO perfiles_publicos(usuario_id,slug,redes) VALUES(?,?,?)')->execute([$this->collisionUser,'ana_qa_owned','{"instagram":"https://www.instagram.com/ana_qa"}']);
        try {
            guardarPortafolio($this->uid, $this->input(['slug' => 'ana_qa_owned','instagram' => 'https://www.instagram.com/attacker_qa']));
            self::fail('Debe rechazar nombre ocupado.');
        } catch (InvalidArgumentException $expected) {
        }
        $st = getDB()->prepare('SELECT redes FROM perfiles_publicos WHERE usuario_id=?');
        $st->execute([$this->collisionUser]);
        self::assertStringContainsString('ana_qa', $st->fetchColumn());
        self::assertSame('', opcionesPortafolio($this->uid)['slug']);
    }
    public function testFeaturedTracksMustBeOwnedAndPublicPlaybackStopsWhenHidden(): void
    {
        try {
            guardarPortafolio($this->uid, $this->input(['destacadas' => ['1']]));
            self::fail('No destacar pista ajena.');
        } catch (OutOfBoundsException $expected) {
        }
        getDB()->prepare("INSERT INTO publicaciones(usuario_id,tipo,titulo) VALUES(?,'cancion','Pista EPK QA')")->execute([$this->uid]);
        $id = (int)getDB()->lastInsertId();
        getDB()->prepare('INSERT INTO publicacion_audio(publicacion_id,archivo,bytes) VALUES(?,?,1)')->execute([$id,'epk_qa.wav']);
        guardarPortafolio($this->uid, $this->input(['destacadas' => [$id]]));
        self::assertTrue(pistaPortafolioPublica($id));
        self::assertSame($id, (int)portafolioPorAlias($this->slug)['pistas'][0]['id']);
        getDB()->prepare("INSERT INTO publicaciones(usuario_id,tipo,titulo) VALUES(?,'cancion','EPK no destacada QA')")->execute([$this->uid]);
        $notFeatured = (int)getDB()->lastInsertId();
        getDB()->prepare('INSERT INTO publicacion_audio(publicacion_id,archivo,bytes) VALUES(?,?,1)')->execute([$notFeatured,'epk_other_qa.wav']);
        self::assertFalse(pistaPortafolioPublica($notFeatured));
        getDB()->prepare("INSERT INTO usuarios_bloqueados(usuario_id,objetivo_id,modo) VALUES(? ,2,'bloquear')")->execute([$this->uid]);
        self::assertNull(portafolioPorAlias($this->slug, 2));
        self::assertFalse(pistaPortafolioPublica($id, 2));
        guardarPortafolio($this->uid, $this->input(['habilitado' => '','destacadas' => [$id]]));
        self::assertFalse(pistaPortafolioPublica($id));
    }
    public function testOnlyOwnerGetsExplicitFileDownloads(): void
    {
        require_once dirname(__DIR__) . '/includes/publicacion.php';
        getDB()->prepare("INSERT INTO publicaciones(usuario_id,tipo,titulo) VALUES(?,'cancion','Descarga propia QA')")->execute([$this->uid]);
        $id = (int)getDB()->lastInsertId();
        getDB()->prepare('INSERT INTO publicacion_audio(publicacion_id,archivo,bytes) VALUES(?,?,1)')->execute([$id,'download_qa.wav']);
        $row = listarFeed($this->uid, 'todo', ['autor' => $this->uid], 1)[0];
        ob_start();
        renderPublicacion($row, [], $this->uid, 'feed.php');
        $own = ob_get_clean();
        self::assertStringContainsString('Descargar mis archivos', $own);
        self::assertStringContainsString('href="audio.php?id=' . $id . '" download', $own);
        ob_start();
        renderPublicacion($row, [], 2, 'feed.php');
        $other = ob_get_clean();
        self::assertStringNotContainsString('Descargar mis archivos', $other);
    }
    public function testLinksAndAliasesRejectActiveProtocolsCredentialsAndReservedNames(): void
    {
        foreach (['javascript:alert(1)','http://example.com','https://user:pass@example.com','https://127.0.0.1','https://localhost'] as $url) {
            try {
                enlacesPortafolio(['sitio' => $url]);
                self::fail('Debe rechazar el enlace.');
            } catch (InvalidArgumentException $expected) {
            }
        }
        self::assertSame(['youtube' => 'https://youtu.be/qa'], enlacesPortafolio(['youtube' => 'https://youtu.be/qa']));
        try {
            enlacesPortafolio(['spotify' => 'https://evil.example/x']);
            self::fail('Debe validar proveedor.');
        } catch (InvalidArgumentException $expected) {
        }
        foreach (['../config','admin','x','123nombre','ab<cd'] as $slug) {
            try {
                validarAliasPortafolio($slug);
                self::fail('Debe rechazar alias.');
            } catch (InvalidArgumentException $expected) {
            }
        }
        self::assertSame('nombre_qa', validarAliasPortafolio('NOMBRE_QA'));
    }
    public function testPrivateProfileCannotBePublishedAndNoSecretsLeaveDTO(): void
    {
        getDB()->prepare("INSERT INTO perfiles_privacidad(usuario_id,visibilidad) VALUES(?,'privado')")->execute([$this->uid]);
        try {
            guardarPortafolio($this->uid, $this->input());
            self::fail('Requiere público.');
        } catch (LogicException $expected) {
        }
        guardarPortafolio($this->uid, $this->input(['habilitado' => '']));
        $dto = portafolioPorAlias($this->slug, $this->uid, true);
        self::assertNotNull($dto);
        foreach (['REPLACE_WITH_LOCAL_VALUE','email','rol','activo'] as $key) {
            self::assertArrayNotHasKey($key,$dto);
        }
    }
}
