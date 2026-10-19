<?php

use PHPUnit\Framework\TestCase;

final class QaPathsTest extends TestCase
{
    public function testQaStorageIsOutsideTheApplication(): void
    {
        $app = rtrim(str_replace('\\', '/', dirname(__DIR__)), '/');
        $qa = rtrim(str_replace('\\', '/', musicmadQaRoot()), '/');
        if (PHP_OS_FAMILY === 'Windows') {
            $app = strtolower($app);
            $qa = strtolower($qa);
        }
        self::assertNotSame($app, $qa);
        self::assertFalse(str_starts_with($qa . '/', $app . '/'));
        self::assertSame(MUSICMAD_QA_ROOT, musicmadQaRoot());
        $config = file_get_contents(dirname(__DIR__) . '/phpunit.xml');
        self::assertStringContainsString('cacheResult="false"', $config);
        self::assertStringNotContainsString('cacheDirectory=', $config);
    }

    public function testOverridesRejectRelativeAndPublicLocations(): void
    {
        foreach (['relative/qa', dirname(__DIR__) . '/qa-public'] as $root) {
            [$status, $output] = $this->resolveInChild($root);
            self::assertSame(1, $status);
            self::assertSame('invalid', $output);
        }
    }

    public function testAbsoluteExternalOverrideWinsOverLocalLocation(): void
    {
        $root = str_replace('\\', '/', sys_get_temp_dir()) . '/musicmad-qa-explicit-fixture';
        [$status, $output] = $this->resolveInChild($root);
        self::assertSame(0, $status);
        self::assertSame($root, $output);
    }

    private function resolveInChild(string $root): array
    {
        $script = 'putenv("MM_QA_ROOT=" . $argv[1]); try { require $argv[2]; echo musicmadQaRoot(); } catch (RuntimeException $e) { echo "invalid"; exit(1); }';
        $process = proc_open(
            [PHP_BINARY, '-r', $script, $root, __DIR__ . '/paths.php'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);
        self::assertSame('', $errors);
        return [$status, $output];
    }
}
