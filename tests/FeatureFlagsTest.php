<?php

use PHPUnit\Framework\TestCase;

final class FeatureFlagsTest extends TestCase
{
    public function testDefaultKnownFeaturesAreEnabledAndUnknownDenied(): void
    {
        foreach (MUSICMAD_EXPERIMENTAL_FEATURES as $feature) {
            self::assertTrue(evaluarFlagMusicMad($feature, 1, []));
        }
        self::assertFalse(evaluarFlagMusicMad('admin', 1, []));
    }
    public function testZeroConfigurationDoesNotFallBackToEnabledDefaults(): void
    {
        $old = getenv('MM_FEATURE_FLAGS');
        putenv('MM_FEATURE_FLAGS=0');
        try {
            self::assertFalse(funcionalidadMusicMad('playlists_equipo', 1));
        } finally {
            if ($old === false) {
                putenv('MM_FEATURE_FLAGS');
            } else {
                putenv('MM_FEATURE_FLAGS=' . $old);
            }
        }
    }
    public function testExplicitOffAndStableRolloutNeverGrantGuestAccess(): void
    {
        $flags = validarFlagsMusicMad('{"clips":false,"estudio":{"enabled":true,"percent":25}}');
        self::assertFalse(evaluarFlagMusicMad('clips', 1, $flags));
        self::assertFalse(evaluarFlagMusicMad('estudio', 0, $flags));
        $enabled = 0;
        for ($uid = 1;$uid <= 500;$uid++) {
            $first = evaluarFlagMusicMad('estudio', $uid, $flags);
            self::assertSame($first, evaluarFlagMusicMad('estudio', $uid, $flags));
            $enabled += (int)$first;
        }
        self::assertGreaterThan(70, $enabled);
        self::assertLessThan(180, $enabled);
    }
    public function testMalformedConfigurationAndUnsupportedFieldsAreRejected(): void
    {
        foreach (['null','false','{"unknown":true}','{"salas":1}','{"clips":{"enabled":true,"percent":101}}','{"clips":{"enabled":true,"percent":50,"role":"admin"}}'] as $raw) {
            try {
                validarFlagsMusicMad($raw);
                self::fail('Invalid config accepted');
            } catch (InvalidArgumentException $e) {
                self::assertNotEmpty($e->getMessage());
            }
        }
    }
    public function testEveryEntryRouteOfEachExperimentalDomainHasGuard(): void
    {
        foreach (['salas.php','sala.php','sala-datos.php','sala-accion.php','colabs.php','colab.php','colab-subir.php','colab-accion.php','clips.php','clip.php','clip-medio.php','clip-accion.php','playlist-equipo.php','playlist-equipo-accion.php'] as $route) {
            self::assertNotNull(flagRutaMusicMad('/MusicMad_PWA/' . $route));
        }
        self::assertNull(flagRutaMusicMad('feed.php'));
    }
    public function testDisabledDomainBlocksDirectOperationsButNotPersonalPlaylistOwner(): void
    {
        $old = getenv('MM_FEATURE_FLAGS');
        putenv('MM_FEATURE_FLAGS={"salas":false,"clips":false,"estudio":false,"playlists_equipo":false}');
        try {
            foreach (['salas','clips','estudio'] as $feature) {
                self::assertTrue(funcionalidadMusicMad($feature, 1));
            }
            self::assertSame([], listasEquipoUsuario(1));
            self::assertTrue(puedeEditarPlaylist(1, ['id' => 1,'usuario_id' => 1,'colaborativa' => 0]));
            try {
                exigirFuncionMusicMad('playlists_equipo', 1);
                self::fail('Disabled action accepted');
            } catch (OutOfBoundsException $e) {
                self::assertNotEmpty($e->getMessage());
            }
        } finally {
            if ($old === false) {
                putenv('MM_FEATURE_FLAGS');
            } else {
                putenv('MM_FEATURE_FLAGS=' . $old);
            }
        }
    }
}
