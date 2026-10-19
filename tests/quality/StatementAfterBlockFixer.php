<?php

namespace MusicMadQuality;

use PhpCsFixer\AbstractFixer;
use PhpCsFixer\FixerDefinition\CodeSample;
use PhpCsFixer\FixerDefinition\FixerDefinition;
use PhpCsFixer\FixerDefinition\FixerDefinitionInterface;
use PhpCsFixer\Tokenizer\Token;
use PhpCsFixer\Tokenizer\Tokens;

/** PSR-12's semicolon rule does not separate a statement glued to a closing block. */
final class StatementAfterBlockFixer extends AbstractFixer
{
    public function getName(): string
    {
        return 'MusicMad/statement_after_block';
    }

    public function getPriority(): int
    {
        return 0; // Before brace positioning and statement indentation.
    }

    public function getDefinition(): FixerDefinitionInterface
    {
        return new FixerDefinition('Separate independent statements after a block.', [new CodeSample('<?php if ($ok) { work(); }requerirSesion();')]);
    }

    public function isCandidate(Tokens $tokens): bool
    {
        return $tokens->isTokenKindFound('}');
    }

    protected function applyFix(\SplFileInfo $file, Tokens $tokens): void
    {
        $starts = [T_IF,T_FOR,T_FOREACH,T_WHILE,T_TRY,T_RETURN,T_THROW,T_VARIABLE,T_STRING,T_ECHO,T_EXIT,T_UNSET,T_REQUIRE,T_REQUIRE_ONCE,T_SWITCH,T_FUNCTION,T_CLASS,T_FINAL,T_ABSTRACT,T_PUBLIC,T_PROTECTED,T_PRIVATE];
        for ($i = count($tokens) - 1; $i >= 0; $i--) {
            if (!$tokens[$i]->equals('}')) {
                continue;
            }
            $next = $tokens->getNextMeaningfulToken($i);
            if ($next === null || !$tokens[$next]->isGivenKind($starts)) {
                continue;
            }
            $open = $tokens->findBlockStart(Tokens::BLOCK_TYPE_CURLY_BRACE, $i);
            if (!$tokens[$open]->equals('{')) {
                continue; // String interpolation must remain byte-for-byte unchanged.
            }
            $before = $tokens->getPrevMeaningfulToken($open);
            if ($tokens[$next]->isGivenKind(T_WHILE) && $before !== null && $tokens[$before]->isGivenKind(T_DO)) {
                continue;
            }
            if ($next === $i + 1) {
                $tokens->insertAt($next, new Token([T_WHITESPACE, "\n"]));
            } elseif ($tokens[$i + 1]->isGivenKind(T_WHITESPACE) && !str_contains($tokens[$i + 1]->getContent(), "\n")) {
                $tokens[$i + 1] = new Token([T_WHITESPACE, "\n"]);
            }
        }
    }
}
