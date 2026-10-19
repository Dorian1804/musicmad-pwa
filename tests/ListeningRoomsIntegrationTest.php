<?php
use PHPUnit\Framework\TestCase;
final class ListeningRoomsIntegrationTest extends TestCase
{
 private array $users=[];private int $pub=0;
 protected function setUp(): void
 {
  if(getenv('MM_RUN_QA')!=='1')$this->markTestSkipped('Solo QA aislada.');self::assertSame('musicmad_qa',DB_NAME);
  foreach(['Host','Oyente','Otro'] as $name){getDB()->prepare('INSERT INTO usuarios(nombre,apellido,email,REPLACE_WITH_LOCAL_VALUE) VALUES(?,?,?,?)')->execute([$name,'QA','room_'.bin2hex(random_bytes(8)).'@musicmad.invalid',REPLACE_WITH_LOCAL_VALUE_hash('MusicMad_Room_QA_42!',PASSWORD_BCRYPT)]);$this->users[]=(int)getDB()->lastInsertId();}
  getDB()->prepare("INSERT INTO publicaciones(usuario_id,tipo,titulo) VALUES(?,'cancion','Pista sala QA')")->execute([$this->users[0]]);$this->pub=(int)getDB()->lastInsertId();
  getDB()->prepare('INSERT INTO publicacion_audio(publicacion_id,archivo,bytes) SELECT ?,archivo,bytes FROM publicacion_audio WHERE publicacion_id=9')->execute([$this->pub]);
  getDB()->prepare('INSERT INTO publicacion_medios(publicacion_id,sha256,duracion,mime,ancho,alto,codec) SELECT ?,sha256,duracion,mime,ancho,alto,codec FROM publicacion_medios WHERE publicacion_id=9')->execute([$this->pub]);
 }
 protected function tearDown(): void{if(getDB()->inTransaction())getDB()->rollBack();foreach($this->users as $uid)getDB()->prepare('DELETE FROM usuarios WHERE id=?')->execute([$uid]);}
 private function room(string $mode='enlace'): int{return crearSalaEscucha($this->users[0],['nombre'=>'Sesión QA','modo'=>$mode,'publicacion'=>$this->pub]);}
 private function denied(callable $op): void{try{$op();self::fail('Debe denegar acceso.');}catch(OutOfBoundsException $expected){self::assertNotEmpty($expected->getMessage());}}
 public function testPrivateInvitationAndMembershipGateEveryRead(): void
 {
  [$host,$guest,$other]=$this->users;$id=$this->room();self::assertSame([],listarSalasEscucha($guest));$this->denied(fn()=>estadoSalaEscucha($guest,$id));$this->denied(fn()=>entrarSalaEscucha($guest,$id,'incorrecto'));$code=salaDisponiblePara($host,$id)['codigo'];self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/',$code);
  entrarSalaEscucha($guest,$id,$code);entrarSalaEscucha($guest,$id,$code);$dto=estadoSalaEscucha($guest,$id);self::assertSame($id,$dto['id']);self::assertCount(2,$dto['integrantes']);foreach(['codigo','email','REPLACE_WITH_LOCAL_VALUE'] as $key)self::assertArrayNotHasKey($key,$dto);
  self::assertCount(1,listarSalasEscucha($guest));$this->denied(fn()=>estadoSalaEscucha($other,$id));cerrarOSalirSala($guest,$id,false);$this->denied(fn()=>estadoSalaEscucha($guest,$id));
 }
 public function testHostControlsUseRevisionAndRejectReplayAndForeignActor(): void
 {
  [$host,$guest]=$this->users;$id=$this->room('publica');entrarSalaEscucha($guest,$id);$this->denied(fn()=>controlarSalaEscucha($guest,$id,1,'reproduciendo',4));
  self::assertSame(2,controlarSalaEscucha($host,$id,1,'reproduciendo',12));$dto=estadoSalaEscucha($guest,$id);self::assertSame('reproduciendo',$dto['reproduccion']);self::assertEqualsWithDelta(12,$dto['posicion'],1);
  try{controlarSalaEscucha($host,$id,1,'pausada',0);self::fail('No aceptar revisión vieja.');}catch(UnexpectedValueException $expected){}
  self::assertSame(3,controlarSalaEscucha($host,$id,2,'pausada',23.5));self::assertEqualsWithDelta(23.5,estadoSalaEscucha($guest,$id)['posicion'],.01);
  foreach([-1,61,INF,NAN] as $pos)try{controlarSalaEscucha($host,$id,3,'pausada',$pos);self::fail('Rechazar momento inválido.');}catch(InvalidArgumentException $expected){}
 }
 public function testClockUsesServerUTCAndStopsAtDuration(): void
 {
  $row=['actualizada_utc'=>'2026-10-12 20:00:00.125','posicion'=>10.5,'duracion'=>60,'reproduccion'=>'reproduciendo'];$now=(float)(new DateTimeImmutable('2026-10-12 20:00:05.125',new DateTimeZone('UTC')))->format('U.u');self::assertSame(15.5,posicionSalaActual($row,$now));self::assertSame(60.0,posicionSalaActual($row,$now+80));$row['reproduccion']='pausada';self::assertSame(10.5,posicionSalaActual($row,$now));
 }
 public function testMessagesAreIdempotentAndHistoryStartsWithMostRecentFifty(): void
 {
  [$host,$guest]=$this->users;$id=$this->room('publica');entrarSalaEscucha($guest,$id);$nonce=bin2hex(random_bytes(16));$msg=enviarMensajeSala($guest,$id,'Me gusta <script>esta pista</script>',$nonce);self::assertSame($msg,enviarMensajeSala($guest,$id,'Me gusta <script>esta pista</script>',$nonce));
  try{enviarMensajeSala($guest,$id,'Otro contenido',$nonce);self::fail('Recibo no reutilizable.');}catch(UnexpectedValueException $expected){}
  $insert=getDB()->prepare('INSERT INTO musicmad_room_messages(sala_id,usuario_id,texto,created_at) VALUES(?,?,?,UTC_TIMESTAMP())');for($i=0;$i<60;$i++)$insert->execute([$id,$host,'Mensaje QA '.$i]);
  $latest=estadoSalaEscucha($guest,$id);self::assertCount(50,$latest['mensajes']);self::assertSame('Mensaje QA 10',$latest['mensajes'][0]['texto']);self::assertSame('Mensaje QA 59',end($latest['mensajes'])['texto']);$older=estadoSalaEscucha($guest,$id,0,$latest['mensajes'][0]['id']);self::assertCount(11,$older['mensajes']);self::assertSame($msg,$older['mensajes'][0]['id']);self::assertSame([],estadoSalaEscucha($guest,$id,$latest['hasta'])['mensajes']);
 }
 public function testTrackPrivacyAndBlocksRevokeAnExistingMembership(): void
 {
  [$host,$guest]=$this->users;$id=$this->room('publica');entrarSalaEscucha($guest,$id);getDB()->prepare("INSERT INTO perfiles_privacidad(usuario_id,visibilidad) VALUES(?,'privado')")->execute([$host]);$this->denied(fn()=>estadoSalaEscucha($guest,$id));self::assertNotNull(salaDisponiblePara($host,$id));getDB()->prepare('DELETE FROM perfiles_privacidad WHERE usuario_id=?')->execute([$host]);
  getDB()->prepare("INSERT INTO usuarios_bloqueados(usuario_id,objetivo_id,modo) VALUES(?,?,'bloquear')")->execute([$guest,$host]);$this->denied(fn()=>estadoSalaEscucha($guest,$id));self::assertNull(salaDisponiblePara($guest,$id));
 }
 public function testCloseExpiryAndCleanupNeverDeleteTheTrack(): void
 {
  [$host,$guest]=$this->users;$id=$this->room('publica');entrarSalaEscucha($guest,$id);$this->denied(fn()=>cerrarOSalirSala($guest,$id,true));cerrarOSalirSala($host,$id,true);$this->denied(fn()=>estadoSalaEscucha($guest,$id));
  getDB()->prepare('UPDATE musicmad_room_state SET expira_en=UTC_TIMESTAMP()-INTERVAL 8 DAY WHERE sala_id=?')->execute([$id]);limpiarSalasEscucha();self::assertNull(salaDisponiblePara($host,$id));self::assertSame(0,(int)getDB()->query('SELECT COUNT(*) FROM salas_escucha WHERE id='.$id)->fetchColumn());self::assertSame(1,(int)getDB()->query('SELECT COUNT(*) FROM publicaciones WHERE id='.$this->pub)->fetchColumn());
 }
 public function testMissingAudioCannotBecomeASynchronizedRoom(): void
 {
  getDB()->prepare("UPDATE publicacion_audio SET archivo='no_such_room_qa.wav' WHERE publicacion_id=?")->execute([$this->pub]);$this->denied(fn()=>$this->room());
 }
}
