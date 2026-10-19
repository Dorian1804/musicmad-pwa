<?php

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/bin/package.php';

final class PackageSecurityTest extends TestCase
{
    public function testSensitivePathsAreExcludedBeforeReadingBytes(): void
    {
        foreach (['config/local.php','.env','.env.example','.env.production','uploads/audio/song.mp3','backups/db.sql','storage/staging/file','tests/.tools/tool.php','.phpunit.cache/result','nested/file.zip','bin/package.php','../local.php'] as $name) {
            self::assertFalse(packagePathAllowed($name), $name);
        }
        self::assertFalse(packagePathAllowed('vendor/autoload.php', false));
        self::assertTrue(packagePathAllowed('vendor/autoload.php'));
        self::assertTrue(packagePathAllowed('assets/vendor/leaflet/leaflet.js', false));
    }

    public function testSecretDetectorRejectsCredentialsWithoutReportingTheirValues(): void
    {
        self::assertFalse(packageBytesSafe('config/example.php', "<?php define('DB_PASS', 'synthetic-not-a-real-REPLACE_WITH_LOCAL_VALUE');"));
        self::assertFalse(packageBytesSafe('js/app.js', 'GOCSPX-' . str_repeat('x', 20)));
        self::assertFalse(packageBytesSafe('file.pem', "-----BEGIN PRIVATE KEY-----\n" . str_repeat('A', 64)));
        self::assertTrue(packageBytesSafe('config/local.example.php', "<?php // define('DB_PASS', 'REEMPLAZAR_CONTRASENA_MYSQL');"));
    }

    public function testConfigurationEnvironmentPrecedesLegacyAndExplicitEmptyDisablesProvider(): void
    {
        $prior = getenv('MM_GOOGLE_CLIENT_SECRET');
        try {
            putenv('MM_GOOGLE_CLIENT_SECRET=synthetic-environment-value');
            self::assertSame('synthetic-environment-value', musicmadConfigValue('GOOGLE_CLIENT_SECRET'));
            putenv('MM_GOOGLE_CLIENT_SECRET=');
            self::assertSame('', musicmadConfigValue('GOOGLE_CLIENT_SECRET'));
        } finally {
            putenv('MM_GOOGLE_CLIENT_SECRET' . ($prior === false ? '' : '=' . $prior));
        }
    }
}
