<?php
use PHPUnit\Framework\TestCase;
final class TrackFeedbackIntegrationTest extends TestCase
{
 private array $posts=[];
 protected function setUp():void{if(getenv('MM_RUN_QA')!=='1')$this->markTestSkipped('Solo QA.');self::assertSame('musicmad_qa',DB_NAME);}
 protected function tearDown():void{foreach($this->posts as $id)getDB()->prepare('DELETE FROM publicaciones WHERE id=?')->execute([$id]);}
 private function track():int{$result=crearPublicacion(1,['tipo'=>'demo','titulo'=>'Feedback ficticio']);$id=(int)$result['id'];$this->posts[]=$id;getDB()->prepare('INSERT INTO publicacion_audio(publicacion_id,archivo,bytes) VALUES(?,?,?)')->execute([$id,'feedback-qa.wav',1024]);return $id;}
 public function testOnlyOwnerCanSetExplicitIdempotentState():void{
  $id=$this->track();cambiarFeedbackPista(1,$id,true);cambiarFeedbackPista(1,$id,true);self::assertTrue(pistaPideFeedback($id));
  try{cambiarFeedbackPista(2,$id,false);self::fail('Non-owner changed tag');}catch(OutOfBoundsException $e){}self::assertTrue(pistaPideFeedback($id));
  cambiarFeedbackPista(1,$id,false);cambiarFeedbackPista(1,$id,false);self::assertFalse(pistaPideFeedback($id));
 }
 public function testFeedFilterAndSnapshotRevalidateRemovedTag():void{
  $a=$this->track();$b=$this->track();cambiarFeedbackPista(1,$a,true);cambiarFeedbackPista(1,$b,true);
  $first=paginaComunidad(1,'todo',['feedback'=>'1'],'',1);self::assertCount(1,$first['publicaciones']);self::assertTrue($first['hay_mas']);
  $pending=(int)$first['publicaciones'][0]['id']===$a?$b:$a;cambiarFeedbackPista(1,$pending,false);
  $next=paginaComunidad(1,'todo',['feedback'=>'1'],$first['cursor'],10);self::assertNotContains($pending,array_column($next['publicaciones'],'id'));
  $direct=listarFeed(1,'todo',['feedback'=>true],30);foreach($direct as $post)self::assertTrue(pistaPideFeedback((int)$post['id']));
 }
 public function testTextPostCannotRequestTrackFeedback():void{
  $result=crearPublicacion(1,['tipo'=>'texto','titulo'=>'Texto QA']);$id=(int)$result['id'];$this->posts[]=$id;
  $this->expectException(OutOfBoundsException::class);cambiarFeedbackPista(1,$id,true);
 }
}
