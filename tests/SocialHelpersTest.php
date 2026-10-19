<?php
use PHPUnit\Framework\TestCase;

final class SocialHelpersTest extends TestCase
{
    public function testOnlyHttpsLinksAreAccepted(): void
    {
        self::assertNull(infoEnlace('http://example.com/track'));
        self::assertNull(infoEnlace('javascript:alert(1)'));
        self::assertSame('enlace', infoEnlace('https://example.com/track')['modo']);
    }

    public function testYoutubeLinksUsePrivacyEnhancedEmbed(): void
    {
        $link = infoEnlace('https://youtu.be/abcdefghijk');
        self::assertSame('youtube', $link['modo']);
        self::assertSame('https://www.youtube-nocookie.com/embed/abcdefghijk', $link['src']);
    }

    public function testSpotifyLinksAreRestrictedToSupportedResources(): void
    {
        $link = infoEnlace('https://open.spotify.com/track/abc123');
        self::assertSame('spotify', $link['modo']);
        self::assertSame('https://open.spotify.com/embed/track/abc123', $link['src']);
    }

    public function testOutputEscapingEncodesHtml(): void
    {
        self::assertSame('&lt;img src=&quot;x&quot; onerror=&quot;alert(1)&quot;&gt;', e('<img src="x" onerror="alert(1)">'));
    }

    public function testMalformedYoutubeQueryAndEmbeddedCredentialsNeverBreakTheFeed(): void
    {
        self::assertSame('enlace',infoEnlace('https://www.youtube.com/watch?v[]=abcdefghijk')['modo']);
        self::assertSame('enlace',infoEnlace('https://www.youtube.com/watch?v[x]=abcdefghijk')['modo']);
        self::assertNull(infoEnlace('https://person:private@example.com/track'));
        self::assertNull(infoEnlace("https://example.com/track\nnext"));
        self::assertStringContainsString('APP_URL|PUBLIC_URL|FFMPEG_PATH|TRUST_PROXY',file_get_contents(dirname(__DIR__).'/config/database.php'));
    }
}
