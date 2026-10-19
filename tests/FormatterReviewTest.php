<?php

use PHPUnit\Framework\TestCase;

final class FormatterReviewTest extends TestCase
{
    public function testIndependentBlockStatementsSplitWithoutChangingInterpolationOrContinuation(): void
    {
        $autoload = __DIR__ . '/.tools/quality/vendor/autoload.php';
        if (!is_file($autoload)) {
            $this->markTestSkipped('Quality tooling not installed.');
        }
        require_once $autoload;
        require_once __DIR__ . '/quality/StatementAfterBlockFixer.php';
        $source = '<?php if ($ok) { work(); }requerirSesion(); $text="{$first}{$second}"; do { tick(); } while ($ok);';
        $tokens = PhpCsFixer\Tokenizer\Tokens::fromCode($source);
        (new MusicMadQuality\StatementAfterBlockFixer())->fix(new SplFileInfo(__FILE__), $tokens);
        $result = $tokens->generateCode();
        self::assertStringContainsString("}\nrequerirSesion();", $result);
        self::assertStringContainsString('"{$first}{$second}"', $result);
        self::assertStringContainsString('} while ($ok)', $result);
    }
}
