<?php

use PHPUnit\Framework\TestCase;

final class AccountControlsIntegrationTest extends TestCase
{
    private int $uid = 0;
    private array $other = [];
    private string $REPLACE_WITH_LOCAL_VALUE = 'MusicMad_Data_QA_42!';
    private string $email = '';
    private array $files = [];
    private array $sessionBefore = [];
    protected function setUp(): void
    {
        if (getenv('MM_RUN_QA') !== '1') {
            $this->markTestSkipped('Solo base QA aislada.');
        }
        self::assertSame('musicmad_qa', DB_NAME);
        $this->sessionBefore = $_SESSION;
        $this->email = 'data-' . bin2hex(random_bytes(8)) . '@musicmad.invalid';
        $this->uid = $this->account($this->email);
    }
    private function account(string $email, string $role = 'cliente'): int
    {
        getDB()->prepare('INSERT INTO usuarios(nombre,apellido,email,REPLACE_WITH_LOCAL_VALUE,rol) VALUES(?,?,?,?,?)')->execute(['Datos','QA',$email,REPLACE_WITH_LOCAL_VALUE_hash($this->REPLACE_WITH_LOCAL_VALUE, PASSWORD_BCRYPT),$role]);
        return (int)getDB()->lastInsertId();
    }
    private function scalar(string $sql, array $params = []): mixed
    {
        $st = getDB()->prepare($sql);
        $st->execute($params);
        return $st->fetchColumn();
    }
    protected function tearDown(): void
    {
        if (getDB()->inTransaction()) {
            getDB()->rollBack();
        }
        foreach (array_merge([$this->uid], $this->other) as $uid) {
            if ($uid) {
                getDB()->prepare('DELETE FROM usuarios WHERE id=?')->execute([$uid]);
            }
        }
        foreach (huellasLimite('reauth:ip:' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown')) as $fp) {
            getDB()->prepare('DELETE FROM login_attempts WHERE fingerprint=?')->execute([$fp]);
        }
        $_SESSION = $this->sessionBefore;
        unset($GLOBALS['musicmad_auth_checked']);
        foreach ($this->files as [$dir,$file]) {
            getDB()->prepare('DELETE FROM musicmad_media_gc WHERE archivo=?')->execute([$file]);
            $path = $dir . '/' . $file;
            if (is_file($path) && !is_link($path)) {
                unlink($path);
            }
        }
    }
    public function testCredentialFailureIsPersistedAndNeverRunsOperation(): void
    {
        $ran = false;
        for ($i = 0;$i < 8;$i++) {
            try {
                operacionCuentaConfirmada($this->uid, 'wrong', '', function () use (&$ran) {
                    $ran = true;
                });
                self::fail('Debe rechazar.');
            } catch (DomainException|OverflowException $expected) {
            }
        }
        self::assertFalse($ran);
        self::assertTrue(limiteBloqueado(getDB(), huellasLimite('reauth:user:' . $this->uid)));
        self::assertFalse(getDB()->inTransaction());
    }
    public function testRolesOnlyAdministratorCanGrantAndRevocationIsImmediate(): void
    {
        try {
            asignarRolSitio(1, $this->uid, 'moderador', 'MusicMad_QA_Example_42!', '');
            self::fail('Un usuario no concede roles.');
        } catch (OutOfBoundsException $expected) {
        }
        self::assertSame('usuario', rolSitio($this->uid));
        $v = versionAccesoCuenta($this->uid);
        asignarRolSitio(3, $this->uid, 'moderador', 'MusicMad_QA_Example_42!', '');
        self::assertSame('moderador', rolSitio($this->uid));
        self::assertTrue(puedeModerar($this->uid));
        self::assertGreaterThan($v, versionAccesoCuenta($this->uid));
        asignarRolSitio(3, $this->uid, 'usuario', 'MusicMad_QA_Example_42!', '');
        self::assertFalse(puedeModerar($this->uid));
        self::assertSame(2, (int)$this->scalar("SELECT COUNT(*) FROM auditoria_acciones WHERE usuario_id=3 AND objeto_id=? AND accion IN ('rol_asignado','rol_retirado')", [$this->uid]));
    }
    public function testBooleanDoesNotGrantDeletionPermission(): void
    {
        getDB()->prepare("INSERT INTO publicaciones(usuario_id,tipo,titulo) VALUES(1,'texto','No borrar QA')")->execute();
        $pub = (int)getDB()->lastInsertId();
        eliminarPublicacion($pub, $this->uid, true);
        self::assertSame(1, (int)$this->scalar('SELECT COUNT(*) FROM publicaciones WHERE id=?', [$pub]));
        getDB()->prepare('DELETE FROM publicaciones WHERE id=?')->execute([$pub]);
    }
    public function testTwoFactorIsRequiredAndRecoveryCodeCannotReplay(): void
    {
        $codes = ['JBSWY3DPEHPK3PXPABCD'];
        getDB()->prepare('INSERT INTO musicmad_2fa_recovery(usuario_id,codigo_hash) VALUES(?,?)')->execute([$this->uid,hash_hmac('sha256', $codes[0], claveCifradoMusicMad('recovery'))]);
        getDB()->prepare('INSERT INTO autenticacion_2fa(usuario_id,secreto_cifrado,habilitada) VALUES(?,?,1)')->execute([$this->uid,cifrarSecretoMusicMad('JBSWY3DPEHPK3PXP', 'totp', (string)$this->uid)]);
        getDB()->prepare('INSERT IGNORE INTO musicmad_2fa_state(usuario_id) VALUES(?)')->execute([$this->uid]);
        try {
            operacionCuentaConfirmada($this->uid, $this->REPLACE_WITH_LOCAL_VALUE, '', fn () => true);
            self::fail('2FA requerida.');
        } catch (DomainException $expected) {
        }
        self::assertTrue(operacionCuentaConfirmada($this->uid, $this->REPLACE_WITH_LOCAL_VALUE, $codes[0], fn () => true));
        $this->expectException(DomainException::class);
        operacionCuentaConfirmada($this->uid, $this->REPLACE_WITH_LOCAL_VALUE, $codes[0], fn () => true);
    }
    public function testDeletionTransfersBandAndRestorationPreservesMedia(): void
    {
        $band = crearBanda($this->uid, ['nombre' => 'Transferencia ' . bin2hex(random_bytes(4)),'genero' => 'Rock','ciudad' => 'QA']);
        self::assertTrue($band['ok']);
        $id = (int)$band['id'];
        unirseBandaWorkspace(2, $id, 'Batería');
        getDB()->prepare("INSERT INTO publicaciones(usuario_id,tipo,titulo) VALUES(?,'texto','Conservar hasta plazo QA')")->execute([$this->uid]);
        $pub = (int)getDB()->lastInsertId();
        $v = versionAccesoCuenta($this->uid);
        solicitarEliminacionCuenta($this->uid, $this->REPLACE_WITH_LOCAL_VALUE, '');
        self::assertSame(0, (int)$this->scalar('SELECT activo FROM usuarios WHERE id=?', [$this->uid]));
        self::assertGreaterThan($v, versionAccesoCuenta($this->uid));
        self::assertSame(2, (int)$this->scalar('SELECT creador_id FROM bandas WHERE id=?', [$id]));
        self::assertSame('admin', rolEnBanda(2, $id));
        self::assertNull(rolEnBanda($this->uid, $id));
        self::assertSame(1, (int)$this->scalar('SELECT COUNT(*) FROM publicaciones WHERE id=?', [$pub]));
        self::assertSame(0, purgarCuentasSolicitadas());
        self::assertTrue(cancelarEliminacionCuenta($this->email, $this->REPLACE_WITH_LOCAL_VALUE, ''));
        self::assertSame(1, (int)$this->scalar('SELECT activo FROM usuarios WHERE id=?', [$this->uid]));
        self::assertNull(rolEnBanda($this->uid, $id));
        getDB()->prepare('DELETE FROM bandas WHERE id=?')->execute([$id]);
    }
    public function testExpiredDeletionPurgesOnlyRequestedAccountAndKeepsReferencedFile(): void
    {
        $file = bin2hex(random_bytes(12)) . '.wav';
        $dir = musicmadUploadDirectory('audio');
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        file_put_contents($dir . '/' . $file, 'QA-only media');
        $this->files[] = [$dir,$file];
        getDB()->prepare("INSERT INTO publicaciones(usuario_id,tipo,titulo) VALUES(?,'cancion','Archivo QA para borrar')")->execute([$this->uid]);
        $own = (int)getDB()->lastInsertId();
        getDB()->prepare('INSERT INTO publicacion_audio(publicacion_id,archivo,bytes) VALUES(?,?,13)')->execute([$own,$file]);
        getDB()->prepare("INSERT INTO publicaciones(usuario_id,tipo,titulo) VALUES(1,'cancion','Referencia compartida QA')")->execute();
        $shared = (int)getDB()->lastInsertId();
        getDB()->prepare('INSERT INTO publicacion_audio(publicacion_id,archivo,bytes) VALUES(?,?,13)')->execute([$shared,$file]);
        solicitarEliminacionCuenta($this->uid, $this->REPLACE_WITH_LOCAL_VALUE, '');
        getDB()->prepare('UPDATE musicmad_account_deletions SET ejecutar_en=UTC_TIMESTAMP()-INTERVAL 1 SECOND WHERE usuario_id=?')->execute([$this->uid]);
        self::assertSame(1, purgarCuentasSolicitadas());
        self::assertFalse($this->scalar('SELECT id FROM usuarios WHERE id=?', [$this->uid]));
        self::assertSame(1, (int)$this->scalar('SELECT activo FROM usuarios WHERE id=1'));
        recogerMediosEliminados();
        self::assertFileExists($dir . '/' . $file);
        getDB()->prepare('DELETE FROM publicaciones WHERE id=?')->execute([$shared]);
        getDB()->prepare('UPDATE musicmad_media_gc SET created_at=UTC_TIMESTAMP() WHERE archivo=?')->execute([$file]);
        recogerMediosEliminados();
        self::assertFileDoesNotExist($dir . '/' . $file);
        $this->uid = 0;
    }
    public function testInactiveAccountWithoutDeletionCannotBeRecovered(): void
    {
        getDB()->prepare('UPDATE usuarios SET activo=0 WHERE id=?')->execute([$this->uid]);
        $this->expectException(DomainException::class);
        cancelarEliminacionCuenta($this->email, $this->REPLACE_WITH_LOCAL_VALUE, '');
    }
    public function testExportContainsOwnedRecordsAndNoAuthenticationMaterial(): void
    {
        getDB()->prepare("INSERT INTO publicaciones(usuario_id,tipo,titulo,texto) VALUES(?,'texto','Export propio QA','Contenido privado propio')")->execute([$this->uid]);
        $json = '';
        escribirExportacionCuenta($this->uid, function ($chunk) use (&$json) {
            $json .= $chunk;
        });
        $result = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('MusicMad-datos-v1', $result['formato']);
        self::assertSame($this->email, $result['colecciones']['usuarios'][0]['email']);
        self::assertSame('Export propio QA', $result['colecciones']['publicaciones'][0]['titulo']);
        self::assertCount(1, $result['colecciones']['usuarios']);
        foreach (['REPLACE_WITH_LOCAL_VALUE','secreto_cifrado','codigo_hash','token_hash','jti_hash','validador_hash','selector','dedupe','payload','endpoint','p256dh','auth'] as $secret) {
            self::assertStringNotContainsString('"' . $secret . '":', $json);
        }
        self::assertStringNotContainsString('qa@musicmad.invalid', $json);
        self::assertFalse(getDB()->inTransaction());
        self::assertTrue((bool)getDB()->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY));
    }
    public function testPasswordChangeRequiresFreshCheckAndIsAudited(): void
    {
        $new = 'MusicMad_New_Data_QA_43!';
        $before = versionAccesoCuenta($this->uid);
        cambiarPasswordCuentaConfirmada($this->uid, $this->REPLACE_WITH_LOCAL_VALUE, $new, $new, '');
        self::assertTrue(REPLACE_WITH_LOCAL_VALUE_verify($new, (string)$this->scalar('SELECT REPLACE_WITH_LOCAL_VALUE FROM usuarios WHERE id=?', [$this->uid])));
        self::assertGreaterThan($before, versionAccesoCuenta($this->uid));
        self::assertSame(1, (int)$this->scalar("SELECT COUNT(*) FROM auditoria_acciones WHERE usuario_id=? AND accion='REPLACE_WITH_LOCAL_VALUE_cambiado'", [$this->uid]));
        unset($_SESSION['device_selector'],$_SESSION['auth_version'],$_SESSION['remember_selector'],$GLOBALS['musicmad_auth_checked']);
        $this->expectException(DomainException::class);
        operacionCuentaConfirmada($this->uid, $this->REPLACE_WITH_LOCAL_VALUE, '', fn () => true);
    }
    public function testLastAdministratorCannotBeRemovedOrDeleteAccount(): void
    {
        if ((int)$this->scalar("SELECT COUNT(*) FROM usuarios WHERE activo=1 AND rol='administrador'") !== 1) {
            $this->markTestSkipped('La fixture tiene más de un administrador.');
        }
        try {
            asignarRolSitio(3, 3, 'usuario', 'MusicMad_QA_Example_42!', '');
            self::fail('No puede quitar al último administrador.');
        } catch (LogicException $expected) {
        }
        try {
            solicitarEliminacionCuenta(3, 'MusicMad_QA_Example_42!', '');
            self::fail('No puede eliminar al último administrador.');
        } catch (LogicException $expected) {
        }
        self::assertSame('administrador',rolSitio(3));
        self::assertSame(1,(int)$this->scalar('SELECT activo FROM usuarios WHERE id=3'));
    }
    public function testGarbageCollectorRejectsTraversalAndUnknownExtensions(): void
    {
        self::assertNull(rutaMedioRecogida('audio','../local.php'));
        self::assertNull(rutaMedioRecogida('avatars','config.php'));
        self::assertNull(rutaMedioRecogida('audio','secret.php'));
        self::assertNull(rutaMedioRecogida('other','a.wav'));
    }
}
