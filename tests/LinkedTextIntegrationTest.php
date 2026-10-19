<?php
use PHPUnit\Framework\TestCase;
require_once dirname(__DIR__).'/includes/hashtag_backfill.php';
final class LinkedTextIntegrationTest extends TestCase
{
 private int $uid=0;private string $alias='';private array $tags=[];
 protected function setUp(): void
 {
  if(getenv('MM_RUN_QA')!=='1')$this->markTestSkipped('Solo base QA aislada.');self::assertSame('musicmad_qa',DB_NAME);
  $this->alias='mentionqa'.bin2hex(random_bytes(6));$this->tags=['backfill'.bin2hex(random_bytes(6)),'changed'.bin2hex(random_bytes(6))];
  getDB()->prepare('INSERT INTO usuarios(nombre,apellido,email,REPLACE_WITH_LOCAL_VALUE) VALUES(?,?,?,?)')->execute(['Mención','QA',$this->alias.'@musicmad.invalid',REPLACE_WITH_LOCAL_VALUE_hash(bin2hex(random_bytes(24)),PASSWORD_BCRYPT)]);$this->uid=(int)getDB()->lastInsertId();
  getDB()->prepare('INSERT INTO perfiles_publicos(usuario_id,slug) VALUES(?,?)')->execute([$this->uid,$this->alias]);
 }
 protected function tearDown(): void
 {
  if(!$this->uid)return;if(getDB()->inTransaction())getDB()->rollBack();getDB()->prepare('DELETE FROM usuarios WHERE id=?')->execute([$this->uid]);
  foreach($this->tags as $tag)getDB()->prepare('DELETE FROM hashtags WHERE etiqueta=? AND NOT EXISTS(SELECT 1 FROM publicacion_hashtags ph WHERE ph.hashtag_id=hashtags.id)')->execute([$tag]);
 }
 public function testMentionsNeverResolvePrivateBlockedMutedOrInactiveAccounts(): void
 {
  self::assertContains($this->uid,array_column(buscarGlobal(1,'@'.$this->alias)['musicos'],'id'));

  $text='Escuchemos a @'.$this->alias;self::assertStringContainsString('musico.php?id='.$this->uid,textoSocialConEnlaces($text,1));
  getDB()->prepare("INSERT INTO perfiles_privacidad(usuario_id,visibilidad) VALUES(?,'privado')")->execute([$this->uid]);self::assertStringNotContainsString('<a ',textoSocialConEnlaces($text,1));self::assertNotContains($this->uid,array_column(buscarGlobal(1,'@'.$this->alias)['musicos'],'id'));self::assertStringContainsString('<a ',textoSocialConEnlaces($text,$this->uid));
  getDB()->prepare('INSERT INTO seguidores(seguidor_id,seguido_id) VALUES(1,?)')->execute([$this->uid]);self::assertStringContainsString('<a ',textoSocialConEnlaces($text,1));
  getDB()->prepare("INSERT INTO usuarios_bloqueados(usuario_id,objetivo_id,modo) VALUES(?,1,'bloquear')")->execute([$this->uid]);self::assertStringNotContainsString('<a ',textoSocialConEnlaces($text,1));
  getDB()->prepare('DELETE FROM usuarios_bloqueados WHERE usuario_id=?')->execute([$this->uid]);getDB()->prepare("INSERT INTO usuarios_bloqueados(usuario_id,objetivo_id,modo) VALUES(1,?,'silenciar')")->execute([$this->uid]);self::assertStringNotContainsString('<a ',textoSocialConEnlaces($text,1));
  getDB()->prepare('UPDATE usuarios SET activo=0 WHERE id=?')->execute([$this->uid]);self::assertSame([],mencionesVisiblesTexto(1,[$this->alias]));
 }
 public function testHistoricalBatchFindsOldTextIsRepeatableAndRemovesStaleTags(): void
 {
  $db=getDB();$db->prepare("INSERT INTO publicaciones(usuario_id,tipo,titulo,texto) VALUES(?,'texto',?,?)")->execute([$this->uid,'Texto antiguo sin índice','#'.$this->tags[0]]);$pub=(int)$db->lastInsertId();
  self::assertSame([],publicacionesEtiqueta($this->uid,$this->tags[0]));$result=reindexarEtiquetasHistoricas($pub-1,1);self::assertSame($pub,$result['ultimo_id']);self::assertSame(1,$result['procesadas']);self::assertSame($pub,(int)publicacionesEtiqueta($this->uid,$this->tags[0])[0]['id']);
  reindexarEtiquetasHistoricas($pub-1,1);$st=$db->prepare('SELECT COUNT(*) FROM publicacion_hashtags WHERE publicacion_id=?');$st->execute([$pub]);self::assertSame(1,(int)$st->fetchColumn());
  $db->prepare('UPDATE publicaciones SET texto=? WHERE id=?')->execute(['#'.$this->tags[1],$pub]);reindexarEtiquetasHistoricas($pub-1,1);self::assertSame([],publicacionesEtiqueta($this->uid,$this->tags[0]));self::assertSame($pub,(int)publicacionesEtiqueta($this->uid,$this->tags[1])[0]['id']);
  $db->prepare("INSERT INTO perfiles_privacidad(usuario_id,visibilidad) VALUES(?,'privado')")->execute([$this->uid]);self::assertSame([],publicacionesEtiqueta(1,$this->tags[1]));
 }
 public function testHistoryBatchesRejectUnboundedWork(): void
 {
  foreach([[-1,1],[0,0],[0,201]] as $args){try{reindexarEtiquetasHistoricas(...$args);self::fail('Unbounded batch accepted.');}catch(InvalidArgumentException $error){self::assertStringContainsString('1 a 200',$error->getMessage());}}
 }
}