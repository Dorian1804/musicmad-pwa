<?php

use PHPUnit\Framework\TestCase;

final class PublicShareTest extends TestCase
{
    public function testCanonicalPublicOriginRequiresHttpsAndSafeBasePath(): void
    {
        self::assertSame('https://musicmad.example', basePublicaMusicMad('https://musicmad.example/'));
        self::assertSame('https://musicmad.example/MusicMad_PWA', basePublicaMusicMad('https://musicmad.example/MusicMad_PWA/'));
        foreach (['http://musicmad.example','//musicmad.example','https://u:p@musicmad.example',
            'https://musicmad.example/a/../b','https://musicmad.example//app',
            'https://musicmad.example/%2e','https://musicmad.example?next=evil','https://musicmad.example/#secret'] as $url) {
            self::assertNull(basePublicaMusicMad($url), $url);
        }
    }

    public function testInvalidTypesAndIdentifiersDoNotBecomePublicResources(): void
    {
        self::assertNull(recursoCompartiblePublico('../config/local.php', 1));
        self::assertNull(recursoCompartiblePublico('perfil', 0));
        self::assertNull(recursoCompartiblePublico('sala', -1));
        self::assertNull(recursoCompartiblePublico('evento', 4294967296));
    }
}
