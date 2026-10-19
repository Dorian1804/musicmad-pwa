<?php

use PHPUnit\Framework\TestCase;

final class ErrorResponseTest extends TestCase
{
    public function testBasesAreLocalAndBoundedWithoutTraversalOrRemoteLinks(): void
    {
        self::assertSame('/', normalizarBaseErrorMusicMad(''));
        self::assertSame('/MusicMad_PWA/', normalizarBaseErrorMusicMad('/MusicMad_PWA'));
        self::assertSame('/app/', normalizarBaseErrorMusicMad('/app/api/v1'));
        foreach (['//evil.invalid/','/../','/app/../../','/app/./','/app/"/','https://evil.invalid','/app/\n/'] as $path) {
            self::assertSame('/', normalizarBaseErrorMusicMad($path));
        }
    }
    public function testJsonChoiceDoesNotDependOnBodyAndHtmlTemplatesNeverExposeRequestOrInlineCode(): void
    {
        $old = $_SERVER;
        try {
            unset($_SERVER['HTTP_ACCEPT'],$_SERVER['HTTP_X_REQUESTED_WITH']);
            self::assertFalse(errorMusicMadEsJson());
            $_SERVER['HTTP_ACCEPT'] = 'application/json';
            self::assertTrue(errorMusicMadEsJson());
            unset($_SERVER['HTTP_ACCEPT']);
            $_SERVER['HTTP_X_REQUESTED_WITH'] = 'MusicMad-PJAX';
            self::assertTrue(errorMusicMadEsJson());
        } finally {
            $_SERVER = $old;
        }
        $source = file_get_contents(dirname(__DIR__) . '/includes/error_view.php');
        self::assertStringNotContainsString('$_GET', $source);
        self::assertStringNotContainsString('<script', $source);
        self::assertStringNotContainsString('style=', $source);
        self::assertStringContainsString('noindex,nofollow', $source);
    }
}
