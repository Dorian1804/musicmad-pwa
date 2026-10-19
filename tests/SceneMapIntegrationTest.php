<?php
use PHPUnit\Framework\TestCase;
final class SceneMapIntegrationTest extends TestCase
{
 protected function setUp(): void{if(getenv('MM_RUN_QA')!=='1')$this->markTestSkipped('Solo QA aislada.');self::assertSame('musicmad_qa',DB_NAME);getDB()->beginTransaction();}
 protected function tearDown(): void{if(getenv('MM_RUN_QA')==='1' && getDB()->inTransaction())getDB()->rollBack();}
 private function bounds(): array{return ['oeste'=>-105,'este'=>-101,'sur'=>19,'norte'=>22];}
 private function location(): array{return ['latitud'=>'20.673541','longitud'=>'-103.348161','consentimiento'=>'1'];}
 private function points(int $viewer=1): array{return puntosMapaEscena($viewer,$this->bounds());}
 public function testLocationIsRoundedBeforeStorageAndOldVisibleRowsWithoutConsentStayHidden(): void{
  getDB()->exec('INSERT INTO ubicaciones_publicas(usuario_id,latitud,longitud,visible) VALUES(2,20.673541,-103.348161,1) ON DUPLICATE KEY UPDATE latitud=VALUES(latitud),longitud=VALUES(longitud),visible=1');getDB()->exec('DELETE FROM musicmad_location_consent WHERE usuario_id=2');self::assertNotContains(2,array_column($this->points(),'id'));guardarZonaMapa(2,'musico',2,$this->location());$zone=miZonaMapa(2,'musico',2);self::assertSame(20.7,(float)$zone['latitud']);self::assertSame(-103.3,(float)$zone['longitud']);$row=array_values(array_filter($this->points(),fn($p)=>$p['tipo']==='musico' && $p['id']===2))[0];self::assertSame(10,$row['radio_km']);self::assertStringNotContainsString('673541',json_encode($row));foreach(['email','REPLACE_WITH_LOCAL_VALUE','consentimiento_en'] as $key)self::assertArrayNotHasKey($key,$row);
 }
 public function testConsentRequiredAndAnotherAccountCannotSetLocation(): void{
  try{guardarZonaMapa(1,'musico',1,['latitud'=>20.7,'longitud'=>-103.3]);self::fail();}catch(InvalidArgumentException $e){self::assertNotSame('',$e->getMessage());}$this->expectException(OutOfBoundsException::class);guardarZonaMapa(1,'musico',2,$this->location());
 }
 public function testPrivacyBlockMuteAndRemovalRevokeMapAccess(): void{
  guardarZonaMapa(2,'musico',2,$this->location());getDB()->exec("INSERT INTO perfiles_privacidad(usuario_id,visibilidad) VALUES(2,'privado') ON DUPLICATE KEY UPDATE visibilidad='privado'");getDB()->exec('DELETE FROM seguidores WHERE seguidor_id=1 AND seguido_id=2');self::assertNotContains(2,array_column(puntosMapaEscena(1,$this->bounds(),'musico'),'id'));getDB()->exec('INSERT INTO seguidores(seguidor_id,seguido_id) VALUES(1,2)');self::assertContains(2,array_column(puntosMapaEscena(1,$this->bounds(),'musico'),'id'));getDB()->exec("INSERT INTO usuarios_bloqueados(usuario_id,objetivo_id,modo) VALUES(1,2,'bloquear')");self::assertNotContains(2,array_column(puntosMapaEscena(1,$this->bounds(),'musico'),'id'));guardarZonaMapa(2,'musico',2,[],true);self::assertNull(miZonaMapa(2,'musico',2));self::assertNotContains(2,array_column(puntosMapaEscena(2,$this->bounds(),'musico'),'id'));
 }
 public function testBandRequiresAdministratorAndArchiveHidesPlace(): void{
  $band=crearBanda(1,['nombre'=>'Map QA '.bin2hex(random_bytes(5)),'ciudad'=>'Guadalajara'])['id'];unirseBandaWorkspace(2,$band,'Voz');try{guardarZonaMapa(2,'banda',$band,$this->location());self::fail();}catch(OutOfBoundsException $e){self::assertNotSame('',$e->getMessage());}guardarZonaMapa(1,'banda',$band,$this->location());self::assertContains($band,array_column(puntosMapaEscena(2,$this->bounds(),'banda'),'id'));archivarBandaWorkspace(1,$band);self::assertNotContains($band,array_column(puntosMapaEscena(2,$this->bounds(),'banda'),'id'));
 }
 public function testEventRequiresOwnerAndCancelledEventsCannotAppear(): void{
  $db=getDB();$db->exec("INSERT INTO eventos_musicales(creador_id,titulo,ciudad,inicio) VALUES(1,'Evento QA','Guadalajara',NOW()+INTERVAL 1 DAY)");$event=(int)$db->lastInsertId();try{guardarZonaMapa(2,'evento',$event,$this->location());self::fail();}catch(OutOfBoundsException $e){self::assertNotSame('',$e->getMessage());}guardarZonaMapa(1,'evento',$event,$this->location());self::assertContains($event,array_column(puntosMapaEscena(2,$this->bounds(),'evento'),'id'));$db->prepare("UPDATE eventos_musicales SET estado='cancelado' WHERE id=?")->execute([$event]);self::assertNotContains($event,array_column(puntosMapaEscena(2,$this->bounds(),'evento'),'id'));
 }
 public function testMalformedBoundsAndPrecisionAreRejected(): void{
  foreach([['latitud'=>INF,'longitud'=>0],['latitud'=>'1e2','longitud'=>'0'],['latitud'=>[], 'longitud'=>0],['latitud'=>90,'longitud'=>0]] as $point){try{zonaAproximada($point['latitud'],$point['longitud']);self::fail();}catch(InvalidArgumentException $e){self::assertNotSame('',$e->getMessage());}}$this->expectException(InvalidArgumentException::class);puntosMapaEscena(1,['oeste'=>1,'este'=>0,'sur'=>1,'norte'=>0]);
 }
}
