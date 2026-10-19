<?php
use PHPUnit\Framework\TestCase;
final class SessionIntegrationTest extends TestCase
{
    private array $session=[];
    protected function setUp(): void
    {
        if(getenv('MM_RUN_QA')!=='1')$this->markTestSkipped('Solo QA aislada.');
        self::assertSame('musicmad_qa',DB_NAME);$this->session=$_SESSION;
        getDB()->beginTransaction();$_SESSION=[];unset($GLOBALS['musicmad_auth_checked']);
    }
    protected function tearDown(): void
    {
        if(getenv('MM_RUN_QA')!=='1')return;
        if(getDB()->inTransaction())getDB()->rollBack();$_SESSION=$this->session;unset($GLOBALS['musicmad_auth_checked']);
    }
    public function testRememberRestoreReusesTheSameDeviceEntry(): void
    {
        $remember=bin2hex(random_bytes(16));registrarDispositivoSesion(1,$remember);$selector=$_SESSION['device_selector'];
        $_SESSION=[];registrarDispositivoSesion(1,$remember);self::assertSame($selector,$_SESSION['device_selector']);
        $st=getDB()->prepare('SELECT COUNT(*) FROM musicmad_session_bindings WHERE recordar_selector=?');$st->execute([$remember]);self::assertSame(1,(int)$st->fetchColumn());self::assertTrue(dispositivoSesionValido(1));
    }
    public function testIndividualRevocationAndOwnerIsolation(): void
    {
        registrarDispositivoSesion(1);$_SESSION['usuario_id']=1;
        self::assertTrue(dispositivoSesionValido(1));$devices=dispositivosCuenta(1);
        $device=array_values(array_filter($devices,fn($d)=>$d['selector']===$_SESSION['device_selector']))[0];
        self::assertFalse(revocarDispositivoCuenta(2,(int)$device['id']));self::assertTrue(dispositivoSesionValido(1));
        self::assertTrue(revocarDispositivoCuenta(1,(int)$device['id']));self::assertFalse(dispositivoSesionValido(1));
    }
    public function testGlobalRevocationInvalidatesKnownAndLegacySessions(): void
    {
        registrarDispositivoSesion(1);$_SESSION['usuario_id']=1;$old=$_SESSION;
        getDB()->prepare('INSERT INTO tokens_api(usuario_id,jti_hash,expira_en) VALUES(1,?,DATE_ADD(NOW(),INTERVAL 1 HOUR))')->execute([hash('sha256','QA session token')]);$tokenId=(int)getDB()->lastInsertId();
        getDB()->prepare('INSERT INTO sesiones_recordadas(selector,usuario_id,validador_hash,expira_en) VALUES(?,1,?,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 DAY))')->execute([str_repeat('d',32),str_repeat('e',64)]);
        revocarAccesosCuenta(1);self::assertFalse(dispositivoSesionValido(1));
        $_SESSION=['usuario_id'=>1];self::assertFalse(dispositivoSesionValido(1));
        $st=getDB()->prepare('SELECT revocado_en FROM tokens_api WHERE id=?');$st->execute([$tokenId]);self::assertNotEmpty($st->fetchColumn());
        self::assertSame(0,(int)getDB()->query('SELECT COUNT(*) FROM sesiones_recordadas WHERE usuario_id=1')->fetchColumn());
        registrarDispositivoSesion(1);self::assertTrue(dispositivoSesionValido(1));self::assertNotSame($old['device_selector'],$_SESSION['device_selector']);
    }
    public function testRoleChangesAreNotTakenFromOldSession(): void
    {
        $_SESSION=['usuario_id'=>1,'usuario_rol'=>'administrador'];registrarDispositivoSesion(1);
        self::assertTrue(validarSesionActual());self::assertFalse(esAdmin());
    }
    public function testSessionBindingCannotBeReplaced(): void
    {
        $_SESSION=['usuario_id'=>1];registrarDispositivoSesion(1);
        getDB()->prepare('UPDATE musicmad_session_bindings SET session_hash=? WHERE selector=?')->execute([str_repeat('0',64),$_SESSION['device_selector']]);
        self::assertFalse(dispositivoSesionValido(1));
    }
    public function testDeviceLabelsDoNotEchoRawUserAgent(): void
    {
        self::assertSame('Chrome · Android',etiquetaDispositivo('Android Chrome/123<script>'));
        self::assertSame('Navegador · Otro dispositivo',etiquetaDispositivo('<script>alert(1)</script>'));
    }
}
