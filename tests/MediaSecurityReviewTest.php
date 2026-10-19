<?php

use PHPUnit\Framework\TestCase;

final class MediaSecurityReviewTest extends TestCase
{
    public function testMetadataRejectsUnknownKindBeforeParsing(): void
    {
        $this->expectException(InvalidArgumentException::class);
        analizarMedioLocal(musicmadQaRoot() . '/uploads/audio/qa-tone.wav', 'other');
    }

    public function testKnownDurationCannotBypassBoundsWithNonFiniteOrLongValues(): void
    {
        foreach ([NAN, INF, -1, 0, 21601] as $value) {
            try {
                validarDuracionMedio($value);
                self::fail('Invalid duration accepted');
            } catch (InvalidArgumentException $error) {
                self::assertNotEmpty($error->getMessage());
            }
        }
        self::assertNull(validarDuracionMedio(null));
        self::assertSame(2.5, validarDuracionMedio(2.5));
    }

    public function testGdBudgetRejectsOversizedDecodeBeforeAllocating(): void
    {
        self::assertFalse(presupuestoImagenSeguro(4000, 4000, '128M', 70 * 1048576));
        self::assertTrue(presupuestoImagenSeguro(512, 512, '128M', 30 * 1048576));
        self::assertFalse(presupuestoImagenSeguro(PHP_INT_MAX, PHP_INT_MAX, '-1', 0));
    }

    public function testFfmpegRejectsRemoteInputBeforeLookingUpBinary(): void
    {
        $this->expectException(InvalidArgumentException::class);
        picosFFmpegLocal('https://example.invalid/input', 1);
    }
}
