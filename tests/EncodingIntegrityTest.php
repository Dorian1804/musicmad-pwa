<?php

use PHPUnit\Framework\TestCase;

final class EncodingIntegrityTest extends TestCase
{
    public function testRuntimeSourcesRemainValidUtf8(): void
    {
        $files = json_decode(file_get_contents(__DIR__ . '/quality-files.json'), true, 512, JSON_THROW_ON_ERROR);
        foreach (array_merge($files, ['sw.js']) as $file) {
            self::assertSame(1, preg_match('//u', file_get_contents(dirname(__DIR__) . '/' . $file)), $file);
        }
    }

    public function testHeaderAndOfflineWorkerHaveNoDoubleDecodedText(): void
    {
        foreach (['includes/header.php', 'sw.js'] as $file) {
            $source = file_get_contents(dirname(__DIR__) . '/' . $file);
            foreach (["\u{00C3}", "\u{00C2}", "\u{00E2}\u{20AC}", "\u{00EF}\u{00BB}\u{00BF}"] as $marker) {
                self::assertStringNotContainsString($marker, $source, $file);
            }
        }
    }
}
