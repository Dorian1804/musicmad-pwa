<?php
require_once __DIR__.'/paths.php';
use PHPUnit\Framework\TestCase;
require_once dirname(__DIR__).'/includes/migration_runner.php';
final class MigrationRunnerIntegrationTest extends TestCase
{
 public function testTrustedBatchRunsMultipleStatementsAndRestoresNativePrepareMode():void{
  if(getenv('MM_RUN_QA')!=='1')$this->markTestSkipped('Solo QA.');self::assertSame('musicmad_qa',DB_NAME);$db=getDB();$before=$db->getAttribute(PDO::ATTR_EMULATE_PREPARES);
  ejecutarLoteSQLMusicMad($db,'SELECT 1; SELECT 2;');self::assertSame($before,$db->getAttribute(PDO::ATTR_EMULATE_PREPARES));self::assertSame(3,(int)$db->query('SELECT 3')->fetchColumn());
  try{ejecutarLoteSQLMusicMad($db,'SELECT 1; INVALID QA STATEMENT;');self::fail('Invalid SQL accepted');}catch(PDOException $e){}self::assertSame($before,$db->getAttribute(PDO::ATTR_EMULATE_PREPARES));self::assertSame(4,(int)$db->query('SELECT 4')->fetchColumn());
 }
 public function testConcurrentRunnerIsRejectedWithoutApplyingChanges(): void
 {
  if(getenv('MM_RUN_QA')!=='1')$this->markTestSkipped('Solo QA aislada.');self::assertSame('musicmad_qa',DB_NAME);
  $other=new PDO('mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4',DB_USER,DB_PASS,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
  $lock='musicmad_schema_'.substr(hash('sha256',DB_NAME),0,32);$st=$other->prepare('SELECT GET_LOCK(?,0)');$st->execute([$lock]);self::assertSame(1,(int)$st->fetchColumn());
  try{ejecutarMigracionesMusicMad(getDB(),dirname(__DIR__).'/config/migrations',true);self::fail('Concurrent runner accepted');}catch(RuntimeException $e){self::assertStringContainsString('en curso',$e->getMessage());}
  finally{$other->prepare('SELECT RELEASE_LOCK(?)')->execute([$lock]);}
 }
 public function testStatusApplyRepeatAndTamperPreflight(): void
 {
  if(getenv('MM_RUN_QA')!=='1')$this->markTestSkipped('Solo QA aislada.');self::assertSame('musicmad_qa',DB_NAME);
  $token=bin2hex(random_bytes(8));$dir=musicmadQaRoot().'/migrations-'.$token;mkdir($dir,0700);
  $a='001_qa_'.$token.'.sql';$b='002_qa_'.$token.'.sql';
  file_put_contents($dir.'/'.$a,'SELECT 1;');file_put_contents($dir.'/'.$b,'SELECT 2;');
  try{
   $plan=ejecutarMigracionesMusicMad(getDB(),$dir,true);self::assertSame(['pendiente','pendiente'],array_column($plan,'estado'));
   $plan=ejecutarMigracionesMusicMad(getDB(),$dir);self::assertSame(['aplicada','aplicada'],array_column($plan,'estado'));
   self::assertSame($plan,ejecutarMigracionesMusicMad(getDB(),$dir));
   file_put_contents($dir.'/'.$b,'SELECT 3;');
   try{ejecutarMigracionesMusicMad(getDB(),$dir);self::fail('Changed migration accepted');}catch(RuntimeException $e){self::assertStringContainsString('cambió',$e->getMessage());}
   file_put_contents($dir.'/'.$b,'SELECT 2;');self::assertSame($plan,ejecutarMigracionesMusicMad(getDB(),$dir,true));
  }finally{getDB()->prepare('DELETE FROM musicmad_migrations WHERE version IN (?,?)')->execute([$a,$b]);unlink($dir.'/'.$a);unlink($dir.'/'.$b);rmdir($dir);}
 }
}
