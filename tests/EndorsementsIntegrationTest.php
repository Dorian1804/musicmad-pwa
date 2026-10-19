<?php

use PHPUnit\Framework\TestCase;

final class EndorsementsIntegrationTest extends TestCase
{
    private array $users = [];
    protected function setUp(): void
    {
        if (getenv('MM_RUN_QA') !== '1') {
            $this->markTestSkipped('Solo QA aislada.');
        }
        self::assertSame('musicmad_qa', DB_NAME);
        foreach (['Autor','Músico','Visitante'] as $name) {
            getDB()->prepare('INSERT INTO usuarios(nombre,apellido,email,REPLACE_WITH_LOCAL_VALUE) VALUES(?,?,?,?)')->execute([$name,'QA','endorsement_' . bin2hex(random_bytes(8)) . '@musicmad.invalid',REPLACE_WITH_LOCAL_VALUE_hash('MusicMad_Endorsement_QA_42!', PASSWORD_BCRYPT)]);
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
    private function nonce(): string
    {
        return bin2hex(random_bytes(16));
    }
    private function save(string $text = 'Su forma de tocar y escuchar al grupo ayuda a crear música.'): void
    {
        accionRecomendacion($this->users[0], $this->users[1], 'guardar', $text, $this->nonce());
    }
    public function testSaveReplayUpdateConflictAndSelfRejection(): void
    {
        [$a,$b,$viewer] = $this->users;
        $nonce = $this->nonce();
        $text = 'Nos escuchamos al improvisar y construimos una buena pieza.';
        accionRecomendacion($a, $b, 'guardar', $text, $nonce);
        accionRecomendacion($a, $b, 'guardar', $text, $nonce);
        self::assertCount(1, recomendacionesMusico($viewer, $b));
        self::assertSame($text, miRecomendacion($a, $b));
        try {
            accionRecomendacion($a, $b, 'guardar', 'Una recomendación diferente con el mismo envío.', $nonce);
            self::fail('Conflict');
        } catch (UnexpectedValueException $e) {
        }
        $this->save('Otra experiencia musical para actualizar la recomendación.');
        self::assertCount(1, recomendacionesMusico($viewer, $b));
        try {
            accionRecomendacion($a, $a, 'guardar', $text, $this->nonce());
            self::fail('Self recommendation');
        } catch (InvalidArgumentException $e) {
        }
        try {
            $this->save('Corto');
            self::fail('Too short');
        } catch (InvalidArgumentException $e) {
        }
    }
    public function testOwnerCanHideAndRestoreAndAuthorCannotUndoOwnerChoice(): void
    {
        [$a,$b,$viewer] = $this->users;
        $this->save();
        accionRecomendacion($b, $a, 'ocultar', '', $this->nonce());
        self::assertSame([], recomendacionesMusico($viewer, $b));
        self::assertCount(1, recomendacionesMusico($b, $b, 1, true));
        self::assertSame([], recomendacionesMusico($a, $b, 1, true));
        $this->save('Una edición no vuelve a hacer pública una recomendación oculta.');
        self::assertSame([], recomendacionesMusico($viewer, $b));
        accionRecomendacion($a, $b, 'retirar', '', $this->nonce());
        $this->save('Una nueva recomendación también respeta la decisión del músico.');
        self::assertSame([], recomendacionesMusico($viewer, $b));
        accionRecomendacion($b, $a, 'mostrar', '', $this->nonce());
        self::assertCount(1, recomendacionesMusico($viewer, $b));
        self::assertArrayHasKey('musicmad_endorsement_hidden', coleccionesExportacionCuenta());
        self::assertArrayNotHasKey('musicmad_endorsement_receipts', coleccionesExportacionCuenta());
    }
    public function testBothProfilePoliciesActiveAccountsAndBlockChangesApply(): void
    {
        [$a,$b,$viewer] = $this->users;
        $this->save();
        getDB()->prepare("INSERT INTO perfiles_privacidad(usuario_id,visibilidad) VALUES(?,'privado')")->execute([$a]);
        self::assertSame([], recomendacionesMusico($viewer, $b));
        self::assertCount(1, recomendacionesMusico($a, $b));
        getDB()->prepare('INSERT INTO seguidores(seguidor_id,seguido_id) VALUES(?,?)')->execute([$viewer,$a]);
        self::assertCount(1, recomendacionesMusico($viewer, $b));
        getDB()->prepare("INSERT INTO usuarios_bloqueados(usuario_id,objetivo_id,modo) VALUES(?,?,'bloquear')")->execute([$b,$viewer]);
        self::assertSame([], recomendacionesMusico($viewer, $b));
        getDB()->prepare('UPDATE usuarios SET activo=0 WHERE id=?')->execute([$b]);
        self::assertSame([], recomendacionesMusico($a, $b));
        try {
            $this->save();
            self::fail('Inactive recipient');
        } catch (OutOfBoundsException $e) {
        }
    }
    public function testOnlyAuthorWithdrawalAndOutgoingControlAfterBlocking(): void
    {
        [$a,$b,$viewer] = $this->users;
        $this->save();
        accionRecomendacion($viewer, $b, 'retirar', '', $this->nonce());
        self::assertCount(1, recomendacionesMusico($viewer, $b));
        getDB()->prepare("INSERT INTO usuarios_bloqueados(usuario_id,objetivo_id,modo) VALUES(?,?,'bloquear')")->execute([$b,$a]);
        self::assertSame([], recomendacionesMusico($a, $b));
        $own = recomendacionesEscritas($a);
        self::assertCount(1,$own);
        self::assertSame('Músico no disponible',$own[0]['autor']);
        accionRecomendacion($a,$b,'retirar','',$this->nonce());
        self::assertSame([],recomendacionesEscritas($a));
        self::assertSame([],recomendacionesMusico($viewer,$b));
    }
}
