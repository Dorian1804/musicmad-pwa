<?php
use PHPUnit\Framework\TestCase;
final class RepostCommentsIntegrationTest extends TestCase
{
 private array $users=[];private int $pub=0;
 protected function setUp():void{
  if(getenv('MM_RUN_QA')!=='1')$this->markTestSkipped('Solo QA.');self::assertSame('musicmad_qa',DB_NAME);
  foreach(['Autor','Comparte','Visitante'] as $name){getDB()->prepare('INSERT INTO usuarios(nombre,apellido,email,REPLACE_WITH_LOCAL_VALUE) VALUES(?,?,?,?)')->execute([$name,'QA','repost-'.bin2hex(random_bytes(8)).'@musicmad.invalid',REPLACE_WITH_LOCAL_VALUE_hash('MusicMad_Repost_QA_42!',PASSWORD_BCRYPT)]);$this->users[]=(int)getDB()->lastInsertId();}
  $r=crearPublicacion($this->users[0],['tipo'=>'texto','titulo'=>'Idea musical QA']);$this->pub=(int)$r['id'];
 }
 protected function tearDown():void{if(getDB()->inTransaction())getDB()->rollBack();foreach($this->users as $uid)getDB()->prepare('DELETE FROM usuarios WHERE id=?')->execute([$uid]);}
 public function testReplayEditConflictAndRetirePreserveSource():void{
  [$owner,$actor,$viewer]=$this->users;$request=bin2hex(random_bytes(16));guardarRepostComentario($actor,$this->pub,'guardar','Me gustó el ritmo.',$request);guardarRepostComentario($actor,$this->pub,'guardar','Me gustó el ritmo.',$request);
  self::assertSame('Me gustó el ritmo.',notaRepostPara($actor,$this->pub,$viewer));$st=getDB()->prepare('SELECT COUNT(*) FROM reposts WHERE usuario_id=? AND publicacion_id=?');$st->execute([$actor,$this->pub]);self::assertSame(1,(int)$st->fetchColumn());
  try{guardarRepostComentario($actor,$this->pub,'guardar','Otro comentario',$request);self::fail('Receipt changed');}catch(UnexpectedValueException $e){}
  guardarRepostComentario($actor,$this->pub,'guardar','Nueva opinión',bin2hex(random_bytes(16)));self::assertSame('Nueva opinión',notaRepostPara($actor,$this->pub,$viewer));
  $remove=bin2hex(random_bytes(16));guardarRepostComentario($actor,$this->pub,'retirar','',$remove);guardarRepostComentario($actor,$this->pub,'retirar','',$remove);self::assertSame('',notaRepostPara($actor,$this->pub,$viewer));self::assertSame($owner,duenoPublicacion($this->pub));
 }
 public function testSourcePrivacyAndActorBlockRevalidateNoteAndAllowOwnWithdrawal():void{
  [$owner,$actor,$viewer]=$this->users;guardarRepostComentario($actor,$this->pub,'guardar','Opinión privada QA',bin2hex(random_bytes(16)));
  getDB()->prepare("INSERT INTO usuarios_bloqueados(usuario_id,objetivo_id,modo) VALUES(?,?,'bloquear')")->execute([$actor,$viewer]);self::assertSame('',notaRepostPara($actor,$this->pub,$viewer));getDB()->prepare('DELETE FROM usuarios_bloqueados WHERE usuario_id=? AND objetivo_id=?')->execute([$actor,$viewer]);
  getDB()->prepare("INSERT INTO perfiles_privacidad(usuario_id,visibilidad) VALUES(?,'privado')")->execute([$owner]);self::assertSame('',notaRepostPara($actor,$this->pub,$viewer));
  try{guardarRepostComentario($actor,$this->pub,'guardar','Nuevo',bin2hex(random_bytes(16)));self::fail('Hidden source updated');}catch(OutOfBoundsException $e){}
  guardarRepostComentario($actor,$this->pub,'retirar','',bin2hex(random_bytes(16)));self::assertSame('',notaRepostPara($actor,$this->pub,$viewer));
 }
 public function testSelfRepostAndOversizedNotesAreRejected():void{
  [$owner,$actor]=$this->users;try{guardarRepostComentario($owner,$this->pub,'guardar','Propia',bin2hex(random_bytes(16)));self::fail('Self repost');}catch(OutOfBoundsException $e){}
  $this->expectException(InvalidArgumentException::class);guardarRepostComentario($actor,$this->pub,'guardar',str_repeat('á',501),bin2hex(random_bytes(16)));
 }
}
