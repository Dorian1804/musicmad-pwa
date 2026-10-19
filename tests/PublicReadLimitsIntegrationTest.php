<?php
use PHPUnit\Framework\TestCase;
final class PublicReadLimitsIntegrationTest extends TestCase
{
 public function testCountersPersistRejectedReadsWithoutClearAddressAndExpire(): void
 {
  if(getenv('MM_RUN_QA')!=='1')$this->markTestSkipped('Solo base QA aislada.');self::assertSame('musicmad_qa',DB_NAME);$address='qa-'.bin2hex(random_bytes(12));
  $hash=hash_hmac('sha256','public-read:'.gmdate('Y-m-d').'\0'.$address,prepararClaveLimites());
  try{
   self::assertTrue(registrarLecturaPublica($address,3));self::assertTrue(registrarLecturaPublica($address,3));self::assertTrue(registrarLecturaPublica($address,3));self::assertFalse(registrarLecturaPublica($address,3));self::assertFalse(registrarLecturaPublica($address,3));
   $st=getDB()->prepare('SELECT huella,solicitudes FROM musicmad_public_limits WHERE huella=?');$st->execute([$hash]);$row=$st->fetch();self::assertSame(5,(int)$row['solicitudes']);self::assertStringNotContainsString($address,json_encode($row));self::assertFalse(getDB()->inTransaction());
   getDB()->prepare('UPDATE musicmad_public_limits SET bucket=UTC_TIMESTAMP()-INTERVAL 2 HOUR WHERE huella=?')->execute([$hash]);self::assertGreaterThanOrEqual(1,limpiarLimitesLecturaPublica());$st->execute([$hash]);self::assertFalse($st->fetch());
  }finally{getDB()->prepare('DELETE FROM musicmad_public_limits WHERE huella=?')->execute([$hash]);}
 }
}
