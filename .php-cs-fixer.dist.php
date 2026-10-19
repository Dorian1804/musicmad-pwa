<?php

$root = __DIR__;
$paths = json_decode(file_get_contents($root . '/tests/quality-files.json'), true, 512, JSON_THROW_ON_ERROR);
$files = array_map(static fn (string $path): SplFileInfo => new SplFileInfo($root . '/' . $path), $paths);
$tests = PhpCsFixer\Finder::create()->files()->in($root . '/tests')->exclude(['.tools','node_modules','__pycache__'])->name('*.php');
$files = array_merge($files, iterator_to_array($tests, false));
foreach (['includes/demo_seed.php','includes/hashtag_backfill.php','bin/seed-demo.php','bin/index-hashtags.php'] as $extra) {
    $files[] = new SplFileInfo($root . '/' . $extra);
}
require_once $root . '/tests/quality/StatementAfterBlockFixer.php';

return (new PhpCsFixer\Config())
    ->registerCustomFixers([new MusicMadQuality\StatementAfterBlockFixer()])
    ->setRules([
        '@PSR12' => true,
        'MusicMad/statement_after_block' => true,
        'no_multiple_statements_per_line' => true,
        'binary_operator_spaces' => ['default' => 'single_space'],
        'concat_space' => ['spacing' => 'one'],
    ])
    ->setRiskyAllowed(false)
    ->setUsingCache(false)
    ->setFinder($files);
