<?php

use PHPUnit\Framework\TestCase;

final class CollaborativePlaylistsIntegrationTest extends TestCase
{
    private array $users = [];
    protected function setUp(): void
    {
        if (getenv('MM_RUN_QA') !== '1') {
            $this->markTestSkipped('Solo QA.');
        }
        self::assertSame('musicmad_qa', DB_NAME);
        foreach (['Creador','Invitado','Otro'] as $name) {
            getDB()->prepare('INSERT INTO usuarios(nombre,apellido,email,REPLACE_WITH_LOCAL_VALUE) VALUES(?,?,?,?)')->execute([$name,'QA','playlist-' . bin2hex(random_bytes(8)) . '@musicmad.invalid',REPLACE_WITH_LOCAL_VALUE_hash('MusicMad_List_QA_42!', PASSWORD_BCRYPT)]);
            $this->users[] = (int)getDB()->lastInsertId();
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
    private function list(): int
    {
        $r = crearPlaylistSocial3($this->users[0], 'Ensayo QA', 'Colección ficticia', false);
        self::assertTrue($r['ok']);
        return (int)$r['id'];
    }
    private function track(int $uid): int
    {
        $r = crearPublicacion($uid, ['tipo' => 'demo','titulo' => 'Pista QA']);
        $id = (int)$r['id'];
        getDB()->prepare('INSERT INTO publicacion_audio(publicacion_id,archivo,bytes) VALUES(?,?,?)')->execute([$id,'playlist-qa.wav',1024]);
        return $id;
    }
    public function testPrivateInvitationNeedsAcceptanceAndRevocationRemovesAccess(): void
    {
        [$owner,$member,$other] = $this->users;
        $id = $this->list();
        $pub = $this->track($owner);
        agregarPistaPlaylistSocial3($owner, $id, $pub);
        accionEquipoPlaylist($owner, $id, 'invitar', $member);
        accionEquipoPlaylist($owner, $id, 'invitar', $member);
        self::assertNull(obtenerPlaylistSocial3($id, $member));
        self::assertSame([], pistasPlaylistSocial3($id, $member));
        self::assertCount(1, listasEquipoUsuario($member, true));
        accionEquipoPlaylist($member, $id, 'aceptar');
        accionEquipoPlaylist($member, $id, 'aceptar');
        self::assertNotNull(obtenerPlaylistSocial3($id, $member));
        self::assertCount(1, pistasPlaylistSocial3($id, $member));
        self::assertNull(obtenerPlaylistSocial3($id, $other));
        accionEquipoPlaylist($owner, $id, 'revocar', $member);
        self::assertNull(obtenerPlaylistSocial3($id, $member));
        self::assertSame([], pistasPlaylistSocial3($id, $member));
        self::assertCount(1, pistasPlaylistSocial3($id, $owner));
    }
    public function testContributorOnlyRemovesOwnAdditionAndCannotAdministerOrDeleteList(): void
    {
        [$owner,$member,$other] = $this->users;
        $id = $this->list();
        accionEquipoPlaylist($owner, $id, 'invitar', $member);
        accionEquipoPlaylist($member, $id, 'aceptar');
        $a = $this->track($owner);
        $b = $this->track($member);
        self::assertTrue(agregarPistaPlaylistSocial3($owner, $id, $a));
        self::assertTrue(agregarPistaPlaylistSocial3($member, $id, $b));
        self::assertTrue(agregarPistaPlaylistSocial3($member, $id, $b));
        self::assertCount(2, pistasPlaylistSocial3($id, $owner));
        try {
            quitarPistaPlaylistSocial3($member, $id, $a);
            self::fail('Contributor removed another addition');
        } catch (OutOfBoundsException $e) {
        }
        try {
            accionEquipoPlaylist($member, $id, 'invitar', $other);
            self::fail('Contributor invited');
        } catch (OutOfBoundsException $e) {
        }
        self::assertFalse(eliminarPlaylistSocial3($member, $id));
        self::assertTrue(quitarPistaPlaylistSocial3($member, $id, $b));
        self::assertCount(1, pistasPlaylistSocial3($id, $owner));
        self::assertNotNull(duenoPublicacion($b));
    }
    public function testBlockingRevokesReadAndWriteAndRejectingInvitationIsIdempotent(): void
    {
        [$owner,$member] = $this->users;
        $id = $this->list();
        accionEquipoPlaylist($owner, $id, 'invitar', $member);
        accionEquipoPlaylist($member, $id, 'rechazar');
        accionEquipoPlaylist($member, $id, 'rechazar');
        self::assertSame([], listasEquipoUsuario($member, true));
        accionEquipoPlaylist($owner, $id, 'invitar', $member);
        accionEquipoPlaylist($member, $id, 'aceptar');
        $pub = $this->track($member);
        getDB()->prepare("INSERT INTO usuarios_bloqueados(usuario_id,objetivo_id,modo) VALUES(?,?,'bloquear')")->execute([$owner,$member]);
        self::assertNull(obtenerPlaylistSocial3($id,$member));
        try {
            agregarPistaPlaylistSocial3($member,$id,$pub);
            self::fail('Blocked write accepted');
        } catch (OutOfBoundsException $e) {
        }
        accionEquipoPlaylist($member,$id,'salir');
        self::assertFalse(miembroPlaylist($member,$id));
    }
}
