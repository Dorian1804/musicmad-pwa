<?php
use PHPUnit\Framework\TestCase;
final class PushControlsTest extends TestCase
{
    public function testQuietHoursAcrossMidnightAndTimezoneBoundaries(): void
    {
        $schedule=['activo'=>true,'desde'=>'22:00','hasta'=>'08:00','zona'=>'America/Mexico_City'];
        foreach (['2026-10-15T03:59:00Z'=>false,'2026-10-15T04:00:00Z'=>true,'2026-10-15T13:59:00Z'=>true,'2026-10-15T14:00:00Z'=>false] as $when=>$expected) self::assertSame($expected,dentroSilencioPush($schedule,new DateTimeImmutable($when)));
        $schedule=['activo'=>true,'desde'=>'09:00','hasta'=>'17:00','zona'=>'UTC'];
        self::assertTrue(dentroSilencioPush($schedule,new DateTimeImmutable('2026-10-15T09:00:00Z')));
        self::assertFalse(dentroSilencioPush($schedule,new DateTimeImmutable('2026-10-15T17:00:00Z')));
    }
    public function testInvalidQuietHoursAreRejected(): void
    {
        foreach ([['activo'=>'1'],['desde'=>'25:00'],['hasta'=>'8:00'],['zona'=>'Invalid/Zone'],['desde'=>'08:00','hasta'=>'08:00']] as $invalid) {
            try {validarSilencioPush($invalid+['activo'=>true,'desde'=>'22:00','hasta'=>'08:00','zona'=>'UTC']);self::fail('Invalid schedule accepted');} catch(InvalidArgumentException $error) {self::assertNotEmpty($error->getMessage());}
        }
    }
    public function testDeepLinkWhitelistRejectsExternalPathsAndArbitraryQueries(): void
    {
        foreach (['mensajes.php','mensajes.php?con=12','notificaciones.php','avisos-dispositivo.php'] as $path) self::assertSame($path,destinoPushSeguro($path));
        foreach (['https://evil.invalid','//evil.invalid','../mensajes.php','mensajes.php?con=0','mensajes.php?con=12&next=https://evil.invalid','mensajes.php?con=12345678901'] as $path) self::assertSame('notificaciones.php',destinoPushSeguro($path));
    }
    public function testVapidRequiresTheMatchingPrivateKey(): void
    {
        $a=\Minishlink\WebPush\VAPID::createVapidKeys();$b=\Minishlink\WebPush\VAPID::createVapidKeys();
        $public=clavePushDecodificada($a['publicKey'],65);
        self::assertTrue(parVapidValido($public,clavePushDecodificada($a['privateKey'],32)));
        self::assertFalse(parVapidValido($public,clavePushDecodificada($b['privateKey'],32)));
    }
    public function testPreferencesAndCronMarkerPersistWithoutPersonalDetails(): void
    {
        if(getenv('MM_RUN_QA')!=='1')$this->markTestSkipped('Solo QA aislada.');
        self::assertSame('musicmad_qa',DB_NAME);getDB()->beginTransaction();
        try {
            $schedule=['activo'=>true,'desde'=>'21:00','hasta'=>'07:30','zona'=>'Europe/Paris'];guardarSilencioPush(1,$schedule);self::assertSame($schedule,horarioSilenciosoPush(1));
            registrarSaludTrabajos(true,['completos'=>2,'reintentos'=>1]);self::assertTrue(cronPushReciente());
            getDB()->exec('UPDATE musicmad_job_health SET ejecutado_en=UTC_TIMESTAMP()-INTERVAL 6 MINUTE');self::assertFalse(cronPushReciente());
            registrarSaludTrabajos(false);self::assertFalse(cronPushReciente());
        } finally {getDB()->rollBack();}
    }
    public function testTransportLoadsItsOwnDependencyInFreshProcess(): void
    {
        if(getenv('MM_RUN_QA')!=='1')$this->markTestSkipped('Solo QA aislada.');
        $process=proc_open([PHP_BINARY,'-r',"require 'tests/bootstrap.php'; echo class_exists(\\Minishlink\\WebPush\\WebPush::class)?'ready':'missing';"],[1=>['pipe','w'],2=>['pipe','w']],$pipes,dirname(__DIR__));
        self::assertIsResource($process);$output=stream_get_contents($pipes[1]);fclose($pipes[1]);stream_get_contents($pipes[2]);fclose($pipes[2]);
        self::assertSame(0,proc_close($process));self::assertSame('ready',$output);
    }
}
