<?php
use PHPUnit\Framework\TestCase;
require_once dirname(__DIR__).'/includes/totp.php';
final class TotpTest extends TestCase
{
    public function testRFC6238VectorsAndBase32(): void
    {
        $secret=base32Codificar('12345678901234567890');self::assertSame('12345678901234567890',base32Decodificar($secret));
        foreach([59=>'94287082',1111111109=>'07081804',1111111111=>'14050471',1234567890=>'89005924',2000000000=>'69279037',20000000000=>'65353130'] as $time=>$expected)self::assertSame($expected,codigoTotp($secret,$time,8));
    }
    public function testWindowAndAntiReplay(): void
    {
        $secret=base32Codificar(str_repeat('x',20));$code=codigoTotp($secret,1000000);$counter=intdiv(1000000,30);
        self::assertSame($counter,contadorTotpValido($secret,$code,1000000));self::assertNull(contadorTotpValido($secret,$code,1000000,$counter));self::assertNull(contadorTotpValido($secret,$code,1000090));
        self::assertNull(contadorTotpValido($secret,'1234567',1000000));
    }
    public function testCipherIsRandomizedAndBoundToOwner(): void
    {
        $a=cifrarSecretoMusicMad('secret-value','totp','1');$b=cifrarSecretoMusicMad('secret-value','totp','1');self::assertNotSame($a,$b);self::assertSame('secret-value',descifrarSecretoMusicMad($a,'totp','1'));
        $this->expectException(RuntimeException::class);descifrarSecretoMusicMad($a,'totp','2');
    }
    public function testCipherTamperingIsRejected(): void
    {
        $value=cifrarSecretoMusicMad('secret-value','totp','1');$value[15]=chr(ord($value[15])^1);$this->expectException(RuntimeException::class);descifrarSecretoMusicMad($value,'totp','1');
    }
}
