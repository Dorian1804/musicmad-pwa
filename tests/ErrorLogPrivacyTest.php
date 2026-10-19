<?php

use PHPUnit\Framework\TestCase;

final class ErrorLogPrivacyTest extends TestCase
{
    public function testFirstPartyErrorLogsNeverIncludeRawExceptionMessages(): void
    {
        $root = dirname(__DIR__);
        $paths = [];
        foreach (['','includes','bin','api/v1','config'] as $directory) {
            foreach (glob($root . '/' . ($directory === '' ? '' : $directory . '/') . '*.php') ?: [] as $path) {
                // Never inspect private configuration, even as source in a regression test.
                if (basename($path) === 'local.php') {
                    continue;
                }
                $paths[] = $path;
            }
        }
        $count = 0;
        foreach ($paths as $path) {
            $tokens = token_get_all(file_get_contents($path));
            foreach ($tokens as $i => $token) {
                if (!is_array($token) || $token[0] !== T_STRING || strtolower($token[1]) !== 'error_log') {
                    continue;
                }
                $expression = '';
                $depth = 0;
                $opened = false;
                for ($j = $i + 1;$j < count($tokens);$j++) {
                    $value = is_array($tokens[$j]) ? $tokens[$j][1] : $tokens[$j];
                    if ($value === '(') {
                        $depth++;
                        $opened = true;
                    } elseif ($value === ')') {
                        $depth--;
                    }
                    $expression .= $value;
                    if ($opened && $depth === 0) {
                        break;
                    }
                }
                self::assertDoesNotMatchRegularExpression('/->\s*getMessage\s*\(/i', $expression, basename($path) . ':' . $token[2]);
                self::assertDoesNotMatchRegularExpression('/\$_(?:POST|GET|COOKIE|FILES|SESSION|SERVER)\b/', $expression, basename($path) . ':' . $token[2]);
                $count++;
            }
        }
        self::assertGreaterThan(25, $count, 'Audit must inspect actual application logging, not an empty fixture.');
    }
}
