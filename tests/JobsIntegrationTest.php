<?php
use PHPUnit\Framework\TestCase;
require_once dirname(__DIR__).'/includes/jobs.php';
final class JobsIntegrationTest extends TestCase
{
    private array $keys=[];
    protected function setUp(): void{if(getenv('MM_RUN_QA')!=='1')$this->markTestSkipped('Solo QA aislada.');self::assertSame('musicmad_qa',DB_NAME);}
    protected function tearDown(): void{foreach($this->keys as $key)getDB()->prepare('DELETE FROM musicmad_jobs WHERE dedupe=?')->execute([$key]);}
    private function enqueue(): string{$key=hash('sha256',random_bytes(32));$this->keys[]=$key;self::assertTrue(encolarTrabajo('correo',['body'=>'QA payload'], $key,1));return $key;}
    public function testDedupeAndLeaseProtectCompletion(): void
    {
        $key=$this->enqueue();self::assertFalse(encolarTrabajo('correo',['body'=>'QA payload'],$key,1));
        $job=reservarTrabajo();self::assertSame($key,$job['dedupe']);$wrong=$job;$wrong['lease_key']=str_repeat('0',32);terminarTrabajo($wrong);
        $st=getDB()->prepare('SELECT estado FROM musicmad_jobs WHERE dedupe=?');$st->execute([$key]);self::assertSame('procesando',$st->fetchColumn());
        terminarTrabajo($job);$st->execute([$key]);self::assertSame('completo',$st->fetchColumn());
        $st=getDB()->prepare('SELECT payload FROM musicmad_jobs WHERE dedupe=?');$st->execute([$key]);self::assertSame('',$st->fetchColumn());
    }
    public function testRetryIsDelayedAndDoesNotLeakExceptionText(): void
    {
        $key=$this->enqueue();$job=reservarTrabajo();terminarTrabajo($job,new RuntimeException('private-test-value'));
        $st=getDB()->prepare('SELECT estado,error_clase,disponible_en>UTC_TIMESTAMP() AS is_delayed FROM musicmad_jobs WHERE dedupe=?');$st->execute([$key]);$row=$st->fetch();self::assertSame('pendiente',$row['estado']);self::assertSame('RuntimeException',$row['error_clase']);self::assertSame(1,(int)$row['is_delayed']);
    }
}
