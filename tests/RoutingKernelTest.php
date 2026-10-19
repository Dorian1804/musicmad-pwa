<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use function MusicMad\Routing\basePath;
use function MusicMad\Routing\catalogue;
use function MusicMad\Routing\destination;
use function MusicMad\Routing\dispatch;
use function MusicMad\Routing\pathFor;
use function MusicMad\Routing\query;
use function MusicMad\Routing\requestParts;
use function MusicMad\Routing\resolve;
use function MusicMad\Routing\segmentPatterns;

require_once dirname(__DIR__) . '/includes/routing/destination.php';
require_once dirname(__DIR__) . '/includes/routing/dispatch.php';

final class RoutingKernelTest extends TestCase
{
    public function testCatalogueHasUniqueRoutesNamesAndExplicitLegacyEntries(): void
    {
        $names = $paths = $legacy = [];
        foreach (catalogue() as $entry) {
            self::assertNotContains($entry['name'], $names);
            $names[] = $entry['name'];
            if ($entry['path'] !== null) {
                self::assertNotContains($entry['path'], $paths);
                $paths[] = $entry['path'];
            }
            foreach ($entry['legacy'] as $old) {
                self::assertNotContains($old, $legacy);
                self::assertDoesNotMatchRegularExpression('~(^|/)(config|includes|tests|bin|storage|uploads)(/|$)~', $old);
                $legacy[] = $old;
            }
            self::assertNotEmpty($entry['methods']);
            self::assertSame([], array_diff($entry['methods'], ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS']));
            self::assertFileExists(dirname(__DIR__) . '/' . ($entry['handler'] === 'api' ? 'api/v1/index.php' : $entry['handler'] . '.php'));
        }
        self::assertGreaterThan(100, count($names));
        self::assertNotContains('dataBase.php', $legacy);
    }

    public function testEveryCleanRouteUsesItsExactAlphabetAndAllowedMethodsInBothDeployments(): void
    {
        foreach (['/', '/MusicMad_PWA/'] as $base) {
            foreach (catalogue() as $entry) {
                if ($entry['path'] === null) {
                    continue;
                }
                $params = [];
                if (str_contains($entry['path'], '{public_id}')) {
                    $params['public_id'] = str_repeat('a', 32);
                }
                if (str_contains($entry['path'], '{alias}')) {
                    $params['alias'] = 'musico_qa';
                }
                $url = pathFor($entry['name'], $params, $base);
                foreach ($entry['methods'] as $method) {
                    $result = resolve($url, $method, $base);
                    self::assertSame(200, $result['status'], $entry['name'] . ':' . $method);
                    self::assertSame($entry['name'], $result['route']['name']);
                    self::assertSame($params, $result['params']);
                    self::assertFalse($result['legacy']);
                }
                $wrong = in_array('DELETE', $entry['methods'], true) ? 'TRACE' : 'DELETE';
                self::assertSame(['status' => 405, 'allow' => $entry['methods']], resolve($url, $wrong, $base));
            }
        }
    }

    public function testEveryListedLegacyFilenameIsRecognizedButNoFileIsDiscoveredDynamically(): void
    {
        foreach (catalogue() as $entry) {
            foreach ($entry['legacy'] as $old) {
                $result = resolve('/MusicMad_PWA/' . $old, $entry['methods'][0], '/MusicMad_PWA/');
                self::assertSame(200, $result['status'], $old);
                self::assertSame($entry['name'], $result['route']['name']);
                self::assertTrue($result['legacy']);
            }
        }
        foreach (['/dataBase.php', '/includes/auth.php', '/config/local.php', '/config/database.php',
            '/tests/router.php', '/bin/jobs.php', '/storage/data', '/vendor/autoload.php', '/unknown.php'] as $uri) {
            self::assertSame(['status' => 404], resolve($uri, 'GET'), $uri);
        }
    }

    public function testTraversalEncodingAmbiguityAndRemoteAuthoritiesFailBeforeLookup(): void
    {
        foreach (['//evil.invalid/feed', 'https://evil.invalid/feed', '/./feed', '/../feed',
            '/%2e%2e/feed', '/%2E/feed', '/%2f/feed', '/%252e%252e%252fconfig', '/feed%00',
            '/feed//', '/feed.php/', '/feed.php.php', '/feed.php/extra', '/feed\\extra',
            "/feed\0", "/feed\r\n", '/feed?q=%00', '/feed?q=%0d%0a', '/feed#fragment',
            '/feed?q=%ff', '/feed?q=%zz', '/feed?id[]=1', '/feed?id%5b%5d=1', '/feed?id=1&id=2',
            '/feed?q=hello world', '/feed?=x', '/feed?Q=x'] as $uri) {
            self::assertSame(['status' => 404], resolve($uri, 'GET'), bin2hex($uri));
        }
    }

    public function testOpaqueReferencesAreLowercaseHex32AndAliasesKeepTheirExistingBoundaries(): void
    {
        foreach ([str_repeat('a', 31), str_repeat('a', 33), str_repeat('A', 32), str_repeat('g', 32), '1', '123.php'] as $id) {
            self::assertSame(['status' => 404], resolve('/sala/' . $id, 'GET'));
        }
        foreach (['ab', '1abc', '_abc', 'ABC', str_repeat('a', 31), 'abc-def', 'abc.php'] as $alias) {
            self::assertSame(['status' => 404], resolve('/@' . $alias, 'GET'));
        }
        foreach (['abc', str_repeat('a', 30)] as $alias) {
            self::assertSame(200, resolve('/@' . $alias, 'GET')['status']);
        }
        $patterns = segmentPatterns();
        self::assertSame(1, preg_match('~^' . $patterns['token'] . '$~D', str_repeat('a', 64)));
        self::assertSame(0, preg_match('~^' . $patterns['token'] . '$~D', str_repeat('a', 63)));
    }

    public function testBaseDetectionDoesNotDependOnHostAndNeverEscapesTheDeployment(): void
    {
        self::assertSame('/', basePath('/index.php'));
        self::assertSame('/MusicMad_PWA/', basePath('/MusicMad_PWA/index.php'));
        self::assertSame('/MusicMad_PWA/', basePath('/MusicMad_PWA/api/v1/index.php'));
        self::assertSame('/MusicMad_PWA/', basePath('/other/index.php', 'https://example.invalid/MusicMad_PWA'));
        self::assertSame('/', basePath('/index.php', 'http://127.0.0.1:8765'));
        self::assertSame(['status' => 404], resolve('/outside/feed', 'GET', '/MusicMad_PWA/'));
        self::assertSame(['status' => 404], resolve('/MusicMad_PWAextra/feed', 'GET', '/MusicMad_PWA/'));
        self::assertTrue(resolve('/MusicMad_PWA', 'GET', '/MusicMad_PWA/')['canonical_slash']);
        self::assertTrue(resolve('/MusicMad_PWA/feed/', 'GET', '/MusicMad_PWA/')['canonical_slash']);
    }

    public function testInvalidConfiguredBasesAreRejected(): void
    {
        foreach (['https://user:pass@example.invalid/app', 'https://user@example.invalid/app',
            'https://example.invalid/a/../b', 'https://example.invalid/app//', 'https://example.invalid/app?q=x',
            'https://example.invalid/app#x', 'http://example.invalid/app', '//example.invalid/app'] as $config) {
            try {
                basePath('/index.php', $config);
                self::fail('Unsafe base was accepted.');
            } catch (InvalidArgumentException $expected) {
                self::assertSame('Invalid application base.', $expected->getMessage());
            }
        }
    }

    public function testQueryParserPreservesUtf8ScalarsButRejectsAmbiguousInputs(): void
    {
        self::assertSame(['q' => 'música en vivo', 'cursor' => 'abc_123'], query('q=m%C3%BAsica+en+vivo&cursor=abc_123'));
        self::assertNull(query(implode('&', array_map(static fn (int $n): string => 'q' . $n . '=1', range(0, 40)))));
        self::assertNull(query('q=' . str_repeat('a', 2049)));
        self::assertNull(requestParts(str_repeat('/a', 6000)));
    }

    public function testSafeReturnTargetsCannotBecomeOpenRedirects(): void
    {
        foreach (['https://evil.invalid/feed', '//evil.invalid', 'javascript:alert(1)',
            '/%2f%2fevil.invalid', '/%252f%252fevil.invalid', '../feed', '/logout', '/acciones/social',
            '/auth/google/retorno?code=private', '/feed#%0a', '/feed?q=%0d%0a', '/login?next=https://evil.invalid'] as $value) {
            self::assertSame('/feed', destination($value), $value);
        }
        self::assertSame('/feed', destination('evil', 'https://evil.invalid'));
        self::assertSame('/mensajes', destination('evil', '/mensajes'));
        self::assertSame('/feed?q=solo', destination('feed.php?q=solo&csrf=secret&resource=users&next=https://evil.invalid'));
        self::assertSame('/mensajes?txt=Hola%20m%C3%BAsico', destination('mensajes.php?txt=Hola músico'));
        self::assertSame('/@musico_qa?vista=1', destination('portafolio.php?usuario=musico_qa&vista=1'));
        self::assertSame('/MusicMad_PWA/feed?genero=Rock#compartir', destination('feed.php?genero=Rock#compartir', '/feed', '/MusicMad_PWA/'));
        self::assertSame('/MusicMad_PWA/feed', destination('/other', 'https://evil.invalid', '/MusicMad_PWA/'));
    }

    public function testNumericLegacyResourcesNeedAuthorizedResolutionBeforeCanonicalization(): void
    {
        $old = resolve('/sala.php?id=42', 'GET');
        self::assertTrue($old['legacy']);
        self::assertSame('room', $old['route']['resource']);
        self::assertSame([], $old['params']);
        self::assertSame('/feed', destination('sala.php?id=42'));
        self::assertSame('/sala/' . str_repeat('b', 32), pathFor('sala', ['public_id' => str_repeat('b', 32)]));
    }

    public function testApiWhitelistCannotBeOverriddenByQueryParameters(): void
    {
        self::assertSame('api-me', resolve('/api/v1/index.php?resource=me', 'GET')['route']['name']);
        self::assertSame(200, resolve('/api/v1/posts', 'POST')['status']);
        self::assertSame(['status' => 405, 'allow' => ['POST']], resolve('/api/v1/posts', 'GET'));
        self::assertSame(['status' => 404], resolve('/api/v1/me?resource=feed', 'GET'));
        self::assertSame(['status' => 404], resolve('/api/v1/index.php?resource=../../config', 'GET'));
        self::assertSame(['status' => 404], resolve('/api/v1/users', 'GET'));
    }

    public function testPathBuilderRejectsUnexpectedAndUnsafeParameters(): void
    {
        foreach ([['public_id' => '../feed'], ['public_id' => str_repeat('a', 32), 'extra' => 'x'], []] as $params) {
            try {
                pathFor('sala', $params);
                self::fail('Unsafe route parameter accepted.');
            } catch (InvalidArgumentException $expected) {
                self::assertNotEmpty($expected->getMessage());
            }
        }
    }

    public function testUnknownHandlerCannotIncludeAnyFile(): void
    {
        $this->expectException(OutOfBoundsException::class);
        dispatch('../../config/local.php');
    }
    public function testDispatcherIncludesAreLiteralsAndExactlyMatchTheCatalogue(): void
    {
        $tokens = token_get_all(file_get_contents(dirname(__DIR__) . '/includes/routing/dispatch.php'));
        $expressions = [];
        for ($i = 0; $i < count($tokens); $i++) {
            if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_REQUIRE) {
                continue;
            }
            $expr = '';
            for ($i++; $i < count($tokens) && $tokens[$i] !== ';'; $i++) {
                $token = $tokens[$i];
                if (is_array($token)) {
                    self::assertNotSame(T_VARIABLE, $token[0]);
                    if ($token[0] !== T_WHITESPACE) {
                        $expr .= $token[1];
                    }
                } else {
                    $expr .= $token;
                }
            }
            self::assertMatchesRegularExpression("~^dirname\\(__DIR__,2\\)\\.'/[a-zA-Z0-9_/-]+[.]php'$~D", $expr);
            $expressions[] = $expr;
        }
        self::assertCount(count(array_unique(array_column(catalogue(), 'handler'))), $expressions);
    }
}
