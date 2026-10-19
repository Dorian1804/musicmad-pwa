<?php

use PHPUnit\Framework\TestCase;

final class PublicArtifactsSecurityTest extends TestCase
{
    private function apacheConfiguration(): string
    {
        return (string) file_get_contents(dirname(__DIR__) . '/.htaccess');
    }

    /** @return array{target: string, flags: string}|null */
    private function firstRewrite(string $path): ?array
    {
        preg_match_all(
            '/^\s*RewriteRule\s+(\S+)\s+(\S+)\s+\[([^\]]+)\]\s*$/m',
            $this->apacheConfiguration(),
            $rules,
            PREG_SET_ORDER
        );

        foreach ($rules as $rule) {
            $flags = explode(',', $rule[3]);
            $pattern = '~' . str_replace('~', '\\~', $rule[1]) . '~';
            if (in_array('NC', $flags, true)) {
                $pattern .= 'i';
            }
            if (preg_match($pattern, $path) === 1) {
                return ['target' => $rule[2], 'flags' => $rule[3]];
            }
        }

        return null;
    }

    private function deniedByFilesMatch(string $basename): bool
    {
        preg_match_all(
            '/<FilesMatch "([^"]+)">\s*Require all denied\s*<\/FilesMatch>/s',
            $this->apacheConfiguration(),
            $rules,
            PREG_SET_ORDER
        );

        foreach ($rules as $rule) {
            if (preg_match('~' . str_replace('~', '\\~', $rule[1]) . '~', $basename) === 1) {
                return true;
            }
        }

        return false;
    }

    public function testPrivateFoldersAndAllDirectMediaAreDenied(): void
    {
        $paths = [
            'config/local.php',
            'vendor/autoload.php',
            'includes/auth.php',
            'storage/errors/application.log',
            'bin/jobs.php',
            'tests/bootstrap.php',
            'backups/musicmad-media/audio/example.mp3',
            '.github/workflows/tests.yml',
            '.tests-tmp/uploads/audio/example.mp3',
            '.phpunit.cache/test-results',
            '.git/config',
            'uploads',
            'uploads/audio/example.mp3',
            'uploads/videos/example.mp4',
            'uploads/partituras/example.pdf',
            'uploads/avatars/invalid.svg',
            'uploads/avatars/nested/photo.jpg',
            'UPLOADS/AVATARS/photo.jpg',
            'BACKUPS/private.zip',
        ];

        foreach ($paths as $path) {
            $rule = $this->firstRewrite($path);
            self::assertNotNull($rule, $path);
            self::assertSame('-', $rule['target'], $path);
            self::assertStringContainsString('R=404', $rule['flags'], $path);
        }
    }

    public function testHistoricalImagesUseEndpointBeforeTheUploadDenyRule(): void
    {
        foreach (['avatars', 'covers', 'market'] as $kind) {
            $rule = $this->firstRewrite('uploads/' . $kind . '/42_0123456789abcdef01234567.jpg');
            self::assertNotNull($rule, $kind);
            self::assertSame('media-image.php?kind=$1&name=$2', $rule['target'], $kind);
            self::assertSame('END', $rule['flags'], $kind);
        }
    }

    public function testJsonExceptionsAreExactPublicResourcePaths(): void
    {
        foreach (['manifest.json', 'api/v1/openapi.json'] as $path) {
            self::assertSame($path === 'manifest.json' || str_ends_with($path, '.php') ? 'front.php' : '-', $this->firstRewrite($path)['target'], $path);
            self::assertContains('END', explode(',', $this->firstRewrite($path)['flags']), $path);
            self::assertFalse($this->deniedByFilesMatch(basename($path)), $path);
            self::assertFileExists(dirname(__DIR__) . '/' . $path);
        }

        foreach ([
            'MANIFIESTO_ENTREGA.json',
            'MANIFIESTO_PARCHE.json',
            'composer.json',
            'anything.json',
            'anything.JSON',
            'subdirectory/manifest.json',
            'subdirectory/openapi.json',
            'api/manifest.json',
            'api/v1/openapi.json/extra',
            'manifest.json/extra',
            'Manifest.json',
            'api/v1/Openapi.json',
        ] as $path) {
            $rule = $this->firstRewrite($path);
            self::assertNotNull($rule, $path);
            self::assertSame('-', $rule['target'], $path);
            self::assertStringContainsString('R=404', $rule['flags'], $path);
        }

        self::assertStringContainsString(
            'RewriteRule ^manifest[.]json$ front.php [END]',
            str_replace("\r\n", "\n", $this->apacheConfiguration())
        );
        self::assertStringNotContainsString('application/manifest+json .webmanifest .json', $this->apacheConfiguration());
    }

    public function testAuditsArchivesAndConfigurationFilesAreNeverPublic(): void
    {
        foreach ([
            '.env', '.env.production', '.gitignore', '.editorconfig',
            'composer.lock', 'phpunit.xml', 'phpunit.xml.dist',
            'AVANCE_ACTUALIZACION.md', 'AUDITORIA_MUSICMAD_MOVIL.MD',
            'MANIFIESTO_ENTREGA.json', 'MANIFIESTO_PARCHE.json',
            'MusicMad.zip', 'MusicMad.ZIP', 'backup.tar', 'backup.tar.gz',
            'backup.tgz', 'backup.bz2', 'backup.tbz', 'backup.tbz2',
            'backup.xz', 'backup.txz', 'backup.zst', 'backup.7z',
            'backup.rar', 'tools.phar', 'data.sql', 'error.log',
            'config.ini', 'file.bak', 'file.old', 'file.orig',
            'file.dist', 'file.swp', 'file.tmp',
        ] as $name) {
            self::assertTrue($this->deniedByFilesMatch($name), $name);
        }

        self::assertStringContainsString('Options -Indexes', $this->apacheConfiguration());
    }

    public function testRuntimeRoutesAndLocalPublicLibrariesStayAvailable(): void
    {
        foreach ([
            'audio.php', 'video.php', 'partitura.php', 'media-image.php',
            'CSS/app.css', 'js/app.js', 'sw.js',
            'assets/vendor/leaflet/leaflet.js',
            'assets/vendor/leaflet/leaflet.css',
        ] as $path) {
            self::assertSame($path === 'manifest.json' || str_ends_with($path, '.php') ? 'front.php' : '-', $this->firstRewrite($path)['target'], $path);
            self::assertContains('END', explode(',', $this->firstRewrite($path)['flags']), $path);
            self::assertFalse($this->deniedByFilesMatch(basename($path)), $path);
        }
    }

    public function testEveryApacheCspAndPhpCspForbidUnsafeExecution(): void
    {
        preg_match_all(
            '/Header always set Content-Security-Policy "([^"]+)"/',
            $this->apacheConfiguration(),
            $policies
        );
        self::assertNotEmpty($policies[1]);
        foreach (array_merge($policies[1], [musicmadCsp()]) as $policy) {
            self::assertStringNotContainsString('unsafe-inline', $policy);
            self::assertStringNotContainsString('unsafe-eval', $policy);
            self::assertStringContainsString("object-src 'none'", $policy);
            self::assertStringNotContainsString('script-src *', $policy);
        }
    }
}
