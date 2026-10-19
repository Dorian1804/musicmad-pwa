<?php

use PHPUnit\Framework\TestCase;

final class PrivateEnvironmentDiscoveryTest extends TestCase
{
    private function syntheticRoot(): string
    {
        require_once __DIR__ . '/paths.php';
        $root = musicmadQaRoot() . '/private-env-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($root, 0700, true));
        return $root;
    }

    private function fakeEnvironment(string $root): string
    {
        $directory = $root . '/backups/musicmad-media/private-config';
        self::assertTrue(mkdir($directory, 0700, true));
        $path = $directory . '/.env';
        self::assertNotFalse(file_put_contents($path, "MM_APP_URL=https://synthetic.invalid\n"));
        return (string) realpath($path);
    }

    public function testPrivateEnvironmentWinsOverLegacyPublicFile(): void
    {
        $root = $this->syntheticRoot();
        $app = $root . '/htdocs/site/MusicMad_PWA';
        self::assertTrue(mkdir($app, 0700, true));
        self::assertNotFalse(file_put_contents($app . '/.env', 'QA_ONLY=legacy-fake'));
        $this->fakeEnvironment($root . '/htdocs/site');
        $this->fakeEnvironment($root . '/htdocs');
        $private = $this->fakeEnvironment($root);

        self::assertSame($private, musicmadPrivateEnvironmentFile($app, $root . '/htdocs'));
    }

    public function testRejectsCandidatesInsideEitherPublicBoundary(): void
    {
        $root = $this->syntheticRoot();
        $app = $root . '/one/two/three/four/five/six/MusicMad_PWA';
        self::assertTrue(mkdir($app, 0700, true));
        $this->fakeEnvironment(dirname($app));
        $this->fakeEnvironment(dirname(dirname($app)));

        // Six checked ancestors remain inside this synthetic tree.
        self::assertNull(musicmadPrivateEnvironmentFile($app, $root . '/one'));
    }

    public function testSearchStopsAtSixAncestors(): void
    {
        $root = $this->syntheticRoot();
        $app = $root . '/one/two/three/four/five/six/seven/MusicMad_PWA';
        self::assertTrue(mkdir($app, 0700, true));
        $this->fakeEnvironment($root);

        self::assertNull(musicmadPrivateEnvironmentFile($app, dirname($app)));
        self::assertNull(musicmadPrivateEnvironmentFile($root . '/missing-app'));
    }

    public function testSymlinkedPrivateFileIsSkippedWhenSupported(): void
    {
        $root = $this->syntheticRoot();
        $app = $root . '/htdocs/site/MusicMad_PWA';
        self::assertTrue(mkdir($app, 0700, true));
        $private = $this->fakeEnvironment($root);
        $linkedRoot = $root . '/htdocs/backups/musicmad-media/private-config';
        self::assertTrue(mkdir($linkedRoot, 0700, true));
        if (!@symlink($private, $linkedRoot . '/.env')) {
            self::markTestSkipped('La plataforma QA no permite crear enlaces simbolicos.');
        }

        self::assertSame($private, musicmadPrivateEnvironmentFile($app));
    }

    public function testQaGuardPrecedesAnyEnvironmentDiscoveryOrParsing(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__) . '/config/database.php');
        self::assertMatchesRegularExpression(
            '/\$envFileMusicMad = null;\s*if \(!\$testOnlyMusicMad\) \{\s*\$envFileMusicMad = musicmadMigratePublicEnvironment\(/',
            $source
        );
        self::assertMatchesRegularExpression(
            '/if \(\$envFileMusicMad !== null && is_file\(\$envFileMusicMad\)/',
            $source
        );
        self::assertTrue(defined('MUSICMAD_TEST_RUN') && MUSICMAD_TEST_RUN === true);
    }
}
