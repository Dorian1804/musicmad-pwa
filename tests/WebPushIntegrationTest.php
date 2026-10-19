<?php
use PHPUnit\Framework\TestCase;
final class WebPushIntegrationTest extends TestCase
{
 private string $device='';private array $subscription=[];
 protected function setUp(): void {
  if(getenv('MM_RUN_QA')!=='1')$this->markTestSkipped('Solo QA aislada.');self::assertSame('musicmad_qa',DB_NAME);getDB()->beginTransaction();
  $vapid=\Minishlink\WebPush\VAPID::createVapidKeys();putenv('MM_VAPID_PUBLIC_KEY='.$vapid['publicKey']);putenv('MM_VAPID_PRIVATE_KEY='.$vapid['privateKey']);putenv('MM_VAPID_SUBJECT=mailto:qa@musicmad.invalid');
  $this->device=bin2hex(random_bytes(16));$version=versionAccesoCuenta(2);$db=getDB();$db->prepare("INSERT INTO sesiones_dispositivos(usuario_id,selector,etiqueta,ultimo_uso) VALUES(2,?,'Push QA',UTC_TIMESTAMP())")->execute([$this->device]);$db->prepare('INSERT INTO musicmad_session_bindings(selector,session_hash,version_acceso,expira_en) VALUES(?,?,?,UTC_TIMESTAMP()+INTERVAL 1 HOUR)')->execute([$this->device,hash('sha256','QA push'),$version]);
  $this->subscription=['endpoint'=>'https://fcm.googleapis.com/fcm/send/qa-'.bin2hex(random_bytes(8)),'keys'=>['p256dh'=>$vapid['publicKey'],'auth'=>rtrim(strtr(base64_encode(random_bytes(16)),'+/','-_'),'=')]];
 }
 protected function tearDown(): void {foreach(['PUBLIC_KEY','PRIVATE_KEY','SUBJECT'] as $key)putenv('MM_VAPID_'.$key);if(getenv('MM_RUN_QA')==='1' && getDB()->inTransaction())getDB()->rollBack();}
 private function fixture(): array {
  $token=registrarPush(2,$this->device,$this->subscription);getDB()->prepare('INSERT INTO mensajes(de_id,para_id,texto) VALUES(1,2,?)')->execute(['Private text must never be sent']);$ref=(int)getDB()->lastInsertId();$st=getDB()->prepare('SELECT id FROM push_subscriptions WHERE endpoint_hash=?');$st->execute([hash('sha256',$this->subscription['endpoint'])]);return ['suscripcion'=>(int)$st->fetchColumn(),'categoria'=>'mensajes','actor'=>1,'referencia'=>$ref,'token'=>$token];
 }
 public function testSubscriptionValidationDeniesSSRFAndMalformedKeys(): void {
  foreach(['http://fcm.googleapis.com/x','https://127.0.0.1/x','https://fcm.googleapis.com.evil.invalid/x','https://user@fcm.googleapis.com/x','https://fcm.googleapis.com:444/x','https://fcm.googleapis.com/x#fragment','https://foo.push.apple.com.evil.invalid/x'] as $endpoint){try{validarEndpointPush($endpoint);self::fail($endpoint);}catch(InvalidArgumentException $e){self::assertNotSame('',$e->getMessage());}}
  validarEndpointPush('https://web.push.apple.com/qa');validarEndpointPush('https://updates.push.services.mozilla.com/wpush/v2/qa');self::assertTrue(pushConfigurado());
  $this->subscription['keys']['auth']='invalid';$this->expectException(InvalidArgumentException::class);registrarPush(2,$this->device,$this->subscription);
 }
 public function testInvalidCurvePointIsRejectedBeforeSaving(): void {
  $this->subscription['keys']['p256dh']=rtrim(strtr(base64_encode(chr(4).str_repeat(chr(0),64)),'+/','-_'),'=');$this->expectException(InvalidArgumentException::class);registrarPush(2,$this->device,$this->subscription);
 }
 public function testSubscriptionCannotBeHijackedWithoutItsKeys(): void {
  $this->fixture();$other=$this->subscription;$other['keys']['auth']=rtrim(strtr(base64_encode(random_bytes(16)),'+/','-_'),'=');
  $device=bin2hex(random_bytes(16));getDB()->prepare("INSERT INTO sesiones_dispositivos(usuario_id,selector,etiqueta,ultimo_uso) VALUES(1,?,'QA',UTC_TIMESTAMP())")->execute([$device]);$this->expectException(LogicException::class);registrarPush(1,$device,$other);
 }
 public function testDeliveryRechecksReadStatePreferencesAndRevocation(): void {
  $payload=$this->fixture();$prepared=prepararAvisoPush($payload);self::assertSame($payload['token'],$prepared['payload']['token']);self::assertStringNotContainsString('Private',json_encode($prepared['payload']));
  getDB()->prepare('UPDATE mensajes SET leido=1 WHERE id=?')->execute([$payload['referencia']]);self::assertNull(prepararAvisoPush($payload));getDB()->prepare('UPDATE mensajes SET leido=0 WHERE id=?')->execute([$payload['referencia']]);
  getDB()->prepare('INSERT INTO musicmad_push_preferences(usuario_id,mensajes) VALUES(2,0) ON DUPLICATE KEY UPDATE mensajes=0')->execute();self::assertNull(prepararAvisoPush($payload));getDB()->prepare('UPDATE musicmad_push_preferences SET mensajes=1 WHERE usuario_id=2')->execute();
  getDB()->prepare('UPDATE sesiones_dispositivos SET revocada_en=UTC_TIMESTAMP() WHERE selector=?')->execute([$this->device]);self::assertNull(prepararAvisoPush($payload));
 }
 public function testBlockedActorCannotDeliverAnAlreadyQueuedPush(): void {
  $payload=$this->fixture();getDB()->prepare("INSERT INTO usuarios_bloqueados(usuario_id,objetivo_id,modo) VALUES(2,1,'bloquear')")->execute();self::assertNull(prepararAvisoPush($payload));
 }
 public function testEncryptedRequestAndVapidUseMockTransportOnly(): void {
  $payload=$this->fixture();$requests=[];$stack=\GuzzleHttp\HandlerStack::create(new \GuzzleHttp\Handler\MockHandler([new \GuzzleHttp\Psr7\Response(201)]));$stack->push(\GuzzleHttp\Middleware::history($requests));$client=new \GuzzleHttp\Client(['handler'=>$stack,'allow_redirects'=>false]);enviarTrabajoPush($payload,$client);
  self::assertCount(1,$requests);$request=$requests[0]['request'];self::assertSame($this->subscription['endpoint'],(string)$request->getUri());self::assertNotSame('',$request->getHeaderLine('Authorization'));self::assertSame('aes128gcm',$request->getHeaderLine('Content-Encoding'));self::assertGreaterThan(100,strlen((string)$request->getBody()));self::assertStringNotContainsString('Private',(string)$request->getBody());
 }
 public function testExpiredEndpointIsRemovedAndRedirectIsNotFollowed(): void {
  $payload=$this->fixture();$client=new \GuzzleHttp\Client(['handler'=>\GuzzleHttp\HandlerStack::create(new \GuzzleHttp\Handler\MockHandler([new \GuzzleHttp\Psr7\Response(410)]))]);enviarTrabajoPush($payload,$client);self::assertNull(prepararAvisoPush($payload));
  $payload=$this->fixture();$client=new \GuzzleHttp\Client(['handler'=>\GuzzleHttp\HandlerStack::create(new \GuzzleHttp\Handler\MockHandler([new \GuzzleHttp\Psr7\Response(302,['Location'=>'https://evil.invalid'])]))]);$this->expectException(RuntimeException::class);enviarTrabajoPush($payload,$client);
 }
 public function testBandPushRechecksMembershipArchiveAndInvitationExpiry(): void {
  $payload=$this->fixture();$r=crearBanda(1,['nombre'=>'Push band QA '.bin2hex(random_bytes(5))]);$band=$r['id'];self::assertTrue(unirseBandaWorkspace(2,$band,'Batería'));$notice=notificarActividadBanda(2,1,$band,'rol',2);$payload['categoria']='bandas';$payload['referencia']=$notice;self::assertNotNull(prepararAvisoPush($payload));archivarBandaWorkspace(1,$band);self::assertNull(prepararAvisoPush($payload));archivarBandaWorkspace(1,$band,true);self::assertNotNull(prepararAvisoPush($payload));expulsarMiembroWorkspace(1,$band,2);self::assertNull(prepararAvisoPush($payload));
  invitarABanda(1,$band,2);$st=getDB()->prepare("SELECT n.id FROM notificaciones n JOIN musicmad_band_notifications bn ON bn.notificacion_id=n.id WHERE n.banda_id=? AND n.usuario_id=2 AND bn.subtipo='invitacion' ORDER BY n.id DESC LIMIT 1");$st->execute([$band]);$payload['referencia']=(int)$st->fetchColumn();self::assertNotNull(prepararAvisoPush($payload));getDB()->prepare('UPDATE musicmad_band_invites SET expira_en=UTC_TIMESTAMP()-INTERVAL 1 SECOND WHERE banda_id=?')->execute([$band]);self::assertNull(prepararAvisoPush($payload));
 }
 public function testQueueDedupeCreatesOneEncryptedJobPerReferenceAndDevice(): void {
  $payload=$this->fixture();encolarAvisoPush(2,1,'mensajes',$payload['referencia']);encolarAvisoPush(2,1,'mensajes',$payload['referencia']);$st=getDB()->prepare("SELECT payload FROM musicmad_jobs WHERE usuario_id=2 AND tipo='push' AND dedupe=?");$dedupe=hash('sha256','push:2:'.$payload['suscripcion'].':mensajes:1:'.$payload['referencia']);$st->execute([$dedupe]);$rows=$st->fetchAll();self::assertCount(1,$rows);self::assertStringNotContainsString('mensajes',$rows[0]['payload']);
 }
 public function testQuietHoursAndDeviceBoundProbeAreEnforcedAtDelivery(): void {
  $payload=$this->fixture();
  $now=new DateTimeImmutable('now',new DateTimeZone('UTC'));
  guardarSilencioPush(2,['activo'=>true,'desde'=>$now->modify('-1 minute')->format('H:i'),'hasta'=>$now->modify('+1 minute')->format('H:i'),'zona'=>'UTC']);
  self::assertNull(prepararAvisoPush($payload));
  try{encolarPruebaPush(2,$this->device);self::fail('Quiet probe accepted');}catch(LogicException $error){self::assertStringContainsString('silencioso',$error->getMessage());}
  guardarSilencioPush(2,['activo'=>false,'desde'=>'22:00','hasta'=>'08:00','zona'=>'UTC']);
  self::assertTrue(encolarPruebaPush(2,$this->device));self::assertFalse(encolarPruebaPush(2,$this->device));
  $payload['categoria']='prueba';$payload['actor']=2;$payload['referencia']=time();
  $row=prepararAvisoPush($payload);self::assertSame('avisos-dispositivo.php',$row['payload']['destino']);
  $payload['actor']=1;self::assertNull(prepararAvisoPush($payload));$payload['actor']=2;$payload['referencia']=time()-301;self::assertNull(prepararAvisoPush($payload));
 }
}
