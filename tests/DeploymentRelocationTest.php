<?php

use PHPUnit\Framework\TestCase;

final class DeploymentRelocationTest extends TestCase
{
    public function testFixedRelocationNeverOverwritesOrTouchesUserContent(): void
    {
        $process = proc_open([PHP_BINARY, __DIR__ . '/deployment-relocation-fixture.php'], [1 => ['pipe','w'],2 => ['pipe','w']], $pipes);
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $error);
        foreach (json_decode($output, true, 512, JSON_THROW_ON_ERROR) as $name => $passed) {
            self::assertTrue($passed, $name);
        }
    }
    public function testImageUrlsDoNotDependOnApacheRewriting(): void
    {
        $name = '1_' . str_repeat('a', 24) . '.jpg';
        self::assertSame(APP_URL . '/media-image.php?kind=avatars&name=' . $name, musicmadPublicImageUrl('uploads/avatars/' . $name));
        self::assertSame('img/avatares/musicos/m3.svg', musicmadPublicImageUrl('img/avatares/musicos/m3.svg'));
        self::assertSame('uploads/avatars/../config/local.php', musicmadPublicImageUrl('uploads/avatars/../config/local.php'));
    }
}
