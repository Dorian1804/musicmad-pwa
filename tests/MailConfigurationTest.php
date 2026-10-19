<?php
use PHPUnit\Framework\TestCase;
require_once dirname(__DIR__).'/includes/mailer.php';
final class MailConfigurationTest extends TestCase
{
    public function testImmediateMailClaimsOnlyItsOwnJobOnceAndKeepsFailuresForRetry():void{
        if(getenv('MM_RUN_QA')!=='1')$this->markTestSkipped('Solo QA aislada.');
        self::assertSame('musicmad_qa',DB_NAME);
        $ids=[bin2hex(random_bytes(32)),bin2hex(random_bytes(32))];$calls=0;$db=getDB();
        try{
            foreach($ids as $id)self::assertTrue(encolarTrabajo('correo',['to'=>'qa@musicmad.invalid','subject'=>'QA','body'=>'Synthetic local test'],$id,1));
            self::assertTrue(despacharCorreoInmediato($ids[0],function($payload)use(&$calls){$calls++;self::assertSame('QA',$payload['subject']);}));
            self::assertFalse(despacharCorreoInmediato($ids[0],function()use(&$calls){$calls++;}));self::assertSame(1,$calls);
            $query=$db->prepare('SELECT estado,payload,intentos FROM musicmad_jobs WHERE dedupe=?');$query->execute([$ids[0]]);$row=$query->fetch();
            self::assertSame('completo',$row['estado']);self::assertSame('',$row['payload']);self::assertSame(1,(int)$row['intentos']);
            self::assertFalse(despacharCorreoInmediato($ids[1],function(){throw new RuntimeException('synthetic failure');}));
            $query->execute([$ids[1]]);$row=$query->fetch();self::assertSame('pendiente',$row['estado']);self::assertNotSame('',$row['payload']);
            self::assertFalse(despacharCorreoInmediato($ids[1],function(){self::fail('A retry must not run twice from HTTP');}));
        }finally{$query=$db->prepare('DELETE FROM musicmad_jobs WHERE dedupe=?');foreach($ids as $id)$query->execute([$id]);}
    }
    public function testLocalPhpMailerDoesNotRequireSmtpCredentialsAndDoesNotUseShellArguments():void{
        if(getenv('MM_RUN_QA')!=='1')$this->markTestSkipped('Solo QA aislada.');
        $old=[];foreach(['TRANSPORT','HOST','USER','PASS','FROM','ENCRYPTION'] as $key){$old[$key]=getenv('MM_MAIL_'.$key);putenv('MM_MAIL_'.$key);}
        try{
            putenv('MM_MAIL_TRANSPORT=mail');putenv('MM_MAIL_FROM=qa@musicmad.invalid');
            self::assertTrue(correoConfigurado());
            $client=crearClienteCorreoMusicMad();self::assertSame('mail',$client->Mailer);self::assertFalse($client->UseSendmailOptions);
            self::assertSame('', $client->Username);self::assertSame('', $client->Password);
            $client->CharSet='UTF-8';$client->setFrom('qa@musicmad.invalid','MusicMad');$client->addAddress('recipient@musicmad.invalid');
            $client->Subject='Verification QA';$client->Body='Synthetic message. No delivery attempted.';
            self::assertTrue($client->preSend());self::assertStringContainsString('Synthetic message',$client->getSentMIMEMessage());
            putenv('MM_MAIL_TRANSPORT=invalid');self::assertFalse(correoConfigurado());
            $this->expectException(RuntimeException::class);crearClienteCorreoMusicMad();
        }finally{foreach($old as $key=>$value)putenv('MM_MAIL_'.$key.($value===false?'':'='.$value));}
    }
    #[PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function testPrivateConstantConfigurationEnablesRecoveryWithExplicitCanonicalUrl():void{
        if(getenv('MM_RUN_QA')!=='1')$this->markTestSkipped('Solo QA aislada.');
        foreach(['HOST','PORT','USER','PASS','FROM','NAME','ENCRYPTION'] as $name)putenv('MM_MAIL_'.$name);
        putenv('MM_APP_URL');
        define('MAIL_HOST','smtp.legacy.invalid');define('MAIL_PORT',465);define('MAIL_USER','qa@musicmad.invalid');define('MAIL_PASS','  fictitious REPLACE_WITH_LOCAL_VALUE  ');
        define('MAIL_FROM','qa@musicmad.invalid');define('MAIL_NAME','MusicMad QA');define('MAIL_ENCRYPTION','ssl');define('MAIL_APP_URL','https://musicmad.invalid/MusicMad_PWA');
        self::assertTrue(correoConfigurado());self::assertSame('smtp.legacy.invalid',parametroCorreoMusicMad('HOST'));
        self::assertSame('  fictitious REPLACE_WITH_LOCAL_VALUE  ',parametroCorreoMusicMad('PASS'));self::assertSame('https://musicmad.invalid/MusicMad_PWA',direccionCanonicaCorreo());
    }
    public function testEnvironmentTakesPriorityAndPrivateConstantsHaveFallback():void{
        self::assertSame('smtp.env.invalid',resolverParametroCorreo('HOST','smtp.env.invalid','smtp.legacy.invalid'));
        self::assertSame('smtp.legacy.invalid',resolverParametroCorreo('HOST',false,'smtp.legacy.invalid'));
        self::assertSame('465',resolverParametroCorreo('PORT','',465));
        self::assertSame('  fictitious REPLACE_WITH_LOCAL_VALUE  ',resolverParametroCorreo('PASS',false,'  fictitious REPLACE_WITH_LOCAL_VALUE  '));
        self::assertSame('',resolverParametroCorreo('PASS',false));
        self::assertSame('587',resolverParametroCorreo('PORT',false));
    }
    public function testCanonicalMailUrlNeverFallsBackToRequestHost():void{
        $old=getenv('MM_APP_URL');putenv('MM_APP_URL');$previous=$_SERVER['HTTP_HOST']??null;$_SERVER['HTTP_HOST']='attacker.invalid';
        try{direccionCanonicaCorreo();self::fail('Unconfigured canonical URL accepted');}catch(RuntimeException $e){self::assertNotEmpty($e->getMessage());}
        finally{if($old===false)putenv('MM_APP_URL');else putenv('MM_APP_URL='.$old);if($previous===null)unset($_SERVER['HTTP_HOST']);else $_SERVER['HTTP_HOST']=$previous;}
    }
}
