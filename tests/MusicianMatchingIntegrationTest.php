<?php
use PHPUnit\Framework\TestCase;
final class MusicianMatchingIntegrationTest extends TestCase
{
 private int $uid=0;private mixed $oldSession=null;
 protected function setUp(): void {if(getenv('MM_RUN_QA')!=='1')$this->markTestSkipped('Solo QA aislada.');self::assertSame('musicmad_qa',DB_NAME);$this->oldSession=$_SESSION['usuario_id']??null;getDB()->beginTransaction();$db=getDB();$db->prepare('INSERT INTO usuarios(nombre,apellido,email,REPLACE_WITH_LOCAL_VALUE,rol) VALUES(?,?,?,?,?)')->execute(['Matching','QA','match-'.bin2hex(random_bytes(5)).'@musicmad.invalid','not-a-usable-REPLACE_WITH_LOCAL_VALUE','cliente']);$this->uid=(int)$db->lastInsertId();guardarPerfilMusical($this->uid,['instrumento'=>'Batería, percusión','ciudad'=>'Guadalajara','generos'=>['Rock','Jazz'],'nivel'=>'avanzado']);}
 protected function tearDown(): void {if(getenv('MM_RUN_QA')==='1' && getDB()->inTransaction())getDB()->rollBack();if($this->oldSession===null)unset($_SESSION['usuario_id']);else $_SESSION['usuario_id']=$this->oldSession;}
 private function wanted(array $changes=[]): array{return $changes+['instrumento'=>'baterista','ciudad'=>'Guadalajara','genero'=>'Rock','nivel'=>'intermedio'];}
 public function testInstrumentAliasesAndMultipleInstrumentsAreRecognized(): void{
  foreach([['baterista','Batería'],['vocalista','Guitarra, voz'],['guitarrista','voz / guitarra'],['Violinista','Violín, viola']] as [$a,$b])self::assertTrue(coincideInstrumento($a,$b));self::assertFalse(coincideInstrumento('bajo','contrabajo'));self::assertFalse(coincideInstrumento('viola','violín'));self::assertFalse(coincideInstrumento('%','guitarra'));self::assertTrue(coincideInstrumento('oud','Oud / guitarra'));
 }
 public function testSuggestionsExplainRealAffinityWithoutExposingSecrets(): void{
  $results=sugerirMusicosCompatibles(1,$this->wanted());$row=array_values(array_filter($results,fn($r)=>(int)$r['id']===$this->uid))[0];self::assertCount(4,$row['coincidencias']);self::assertSame('avanzado',$row['nivel']);foreach(['email','REPLACE_WITH_LOCAL_VALUE','rol'] as $key)self::assertArrayNotHasKey($key,$row);
 }
 public function testMinimumLevelDoesNotTreatUndefinedAsProfessional(): void{
  guardarNivelMusico($this->uid,'sin_definir');self::assertNotContains($this->uid,array_column(sugerirMusicosCompatibles(1,$this->wanted()),'id'));guardarNivelMusico($this->uid,'profesional');self::assertContains($this->uid,array_map('intval',array_column(sugerirMusicosCompatibles(1,$this->wanted()),'id')));self::assertLessThanOrEqual(1,count(sugerirMusicosCompatibles(1,$this->wanted(),1)));
 }
 public function testPrivateBlockedAndMutedCandidatesAreExcluded(): void{
  $db=getDB();$db->prepare("INSERT INTO perfiles_privacidad(usuario_id,visibilidad) VALUES(?,'privado')")->execute([$this->uid]);self::assertNotContains($this->uid,array_column(sugerirMusicosCompatibles(1,$this->wanted()),'id'));$db->prepare('INSERT INTO seguidores(seguidor_id,seguido_id) VALUES(1,?)')->execute([$this->uid]);self::assertContains($this->uid,array_map('intval',array_column(sugerirMusicosCompatibles(1,$this->wanted()),'id')));$db->prepare("INSERT INTO usuarios_bloqueados(usuario_id,objetivo_id,modo) VALUES(1,?,'silenciar')")->execute([$this->uid]);self::assertNotContains($this->uid,array_column(sugerirMusicosCompatibles(1,$this->wanted()),'id'));
 }
 public function testWantedLevelAndCityFiltersAreStoredAndBoundToOwner(): void{
  $r=crearBusqueda(1,$this->wanted()+['descripcion'=>'Proyecto QA']);self::assertTrue($r['ok']);$wanted=busquedaParaMatching(1,$r['id']);self::assertSame('intermedio',$wanted['nivel']);$_SESSION['usuario_id']=1;$rows=listarBusquedas(['autor'=>1,'ciudad'=>'Guadalajara','nivel'=>'intermedio']);self::assertContains($r['id'],array_map('intval',array_column($rows,'id')));self::assertNotContains($r['id'],array_column(listarBusquedas(['autor'=>1,'ciudad'=>'Tijuana']),'id'));$this->expectException(OutOfBoundsException::class);busquedaParaMatching(2,$r['id']);
 }
 public function testUnauthorizedOrArchivedBandCannotPublishRecruitment(): void{
  $band=crearBanda(1,['nombre'=>'Match band '.bin2hex(random_bytes(5))])['id'];try{crearBusqueda(2,$this->wanted()+['banda_id'=>$band]);self::fail();}catch(OutOfBoundsException $e){self::assertNotSame('',$e->getMessage());}archivarBandaWorkspace(1,$band);$this->expectException(OutOfBoundsException::class);crearBusqueda(1,$this->wanted()+['banda_id'=>$band]);
 }
 public function testInvalidLevelRejectedAndMusicalUpdateDoesNotResetLevel(): void{
  self::assertFalse(crearBusqueda(1,$this->wanted(['nivel'=>'admin']))['ok']);guardarPerfilMusical($this->uid,['instrumento'=>'Batería','ciudad'=>'Guadalajara','generos'=>['Rock']]);self::assertSame('avanzado',nivelMusico($this->uid));$this->expectException(InvalidArgumentException::class);guardarNivelMusico($this->uid,'admin');
 }
 public function testGenreAndCityAffinitySortAheadOfOnlyInstrument(): void{
  $st=getDB()->prepare('SELECT generos,ciudad FROM perfiles_musicales WHERE usuario_id=2');$st->execute();$other=$st->fetch();getDB()->prepare("UPDATE perfiles_musicales SET instrumento='Batería',generos='Pop',ciudad='Tijuana' WHERE usuario_id=2")->execute();guardarNivelMusico(2,'avanzado');$rows=sugerirMusicosCompatibles(1,$this->wanted());$ids=array_map('intval',array_column($rows,'id'));self::assertLessThan(array_search(2,$ids,true),array_search($this->uid,$ids,true));
 }
}
