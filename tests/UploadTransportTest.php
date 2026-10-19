<?php
use PHPUnit\Framework\TestCase;
final class UploadTransportTest extends TestCase
{
    public function testLimitsAllowLargeVideosAndAudio(): void
    {
        validarInicioSubida('video','ensayo.mp4',110*1048576);
        validarInicioSubida('audio','pista.wav',60*1048576);
        self::assertSame(256*1048576,limiteSubida('video'));
        self::assertSame(512*1024,MUSICMAD_CHUNK_BYTES);
    }
    public function testBadFormatsAndOversizedVideosAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        validarInicioSubida('video','shell.php',10);
    }
    public function testSizeCannotExceedTheServerLimit(): void
    {
        $this->expectException(InvalidArgumentException::class);
        validarInicioSubida('video','pista.mp4',VIDEO_MAX_BYTES+1);
    }
    public function testStagingPathRejectsTraversal(): void
    {
        $this->expectException(InvalidArgumentException::class);
        rutaSubidaPendiente('../config/local.php');
    }
    public function testClaimedUploadCannotReadAnArbitraryServerFile(): void
    {
        self::assertFalse(archivoCargaReal(['tmp_name'=>__FILE__]));
        self::assertFalse(archivoCargaReal(['tmp_name'=>__FILE__,'_capacidad'=>capacidadSubidaInterna()]));
        self::assertFalse(archivoCargaReal(['tmp_name'=>__FILE__,'_capacidad'=>'forged']));
    }
}
