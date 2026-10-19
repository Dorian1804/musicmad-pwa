<?php

use PHPUnit\Framework\TestCase;

final class QualityConfigurationTest extends TestCase
{
    public function testAllRuntimePhpIsExplicitlyEnumeratedWithoutPrivateData(): void
    {
        $root = dirname(__DIR__);
        $files = json_decode(file_get_contents(__DIR__ . '/quality-files.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertGreaterThanOrEqual(215, count($files));
        self::assertSame(array_values(array_unique($files)), $files);
        $stan = file_get_contents($root . '/phpstan.neon');
        foreach ($files as $file) {
            self::assertDoesNotMatchRegularExpression('~(^|/)(local\.php|\.env[^/]*|uploads|backups|tests|vendor)(/|$)~i', $file);
            self::assertFileExists($root . '/' . $file);
            self::assertStringContainsString('        - ' . $file . "\n", $stan);
        }
        self::assertStringNotContainsString('baseline', $stan);
        self::assertStringNotContainsString('ignoreErrors', $stan);
    }

    public function testDevelopmentDependenciesAreLockedAndIsolated(): void
    {
        $lock = json_decode(file_get_contents(__DIR__ . '/quality/composer.lock'), true, 512, JSON_THROW_ON_ERROR);
        $packages = array_column($lock['packages-dev'], 'version', 'name');
        self::assertSame('v3.95.27', $packages['friendsofphp/php-cs-fixer']);
        self::assertSame('2.3.1', $packages['phpstan/phpstan']);
        self::assertStringContainsString('<exclude>tests/.tools</exclude>', file_get_contents(dirname(__DIR__) . '/phpunit.xml'));
    }

    public function testCiChecksFormattingAnalysisCssAndClientRegressions(): void
    {
        $workflow = file_get_contents(dirname(__DIR__) . '/.github/workflows/phpunit.yml');
        foreach (['--dry-run --sequential', 'phpstan analyse', 'tests/build-css.py', 'tests/stage.test.js', 'tests/i18n-client.test.js'] as $gate) {
            self::assertStringContainsString($gate, $workflow);
        }
    }
    public function testCumulativeSqlAliasAddsNoNewMigrationOrUserMutation(): void
    {
        $root = dirname(__DIR__);
        $canonical = file_get_contents($root . '/config/actualizar_musicmad_v100.sql');
        $alias = file_get_contents($root . '/config/actualizar_musicmad_v104.sql');
        $withoutComments = static fn (string $sql): string => trim(preg_replace('/^--[^\n]*\n/m', '', $sql));
        self::assertSame($withoutComments($canonical), $withoutComments($alias));
        self::assertDoesNotMatchRegularExpression('/(?:^|;)\s*(?:DROP|TRUNCATE|DELETE|UPDATE)\b/i', $withoutComments($alias));
    }
}
