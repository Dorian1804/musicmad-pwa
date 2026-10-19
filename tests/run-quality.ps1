$ErrorActionPreference = 'Stop'
$taskRoot = Split-Path -Parent $PSScriptRoot
$taskPhp = if (Test-Path 'C:/xaamp/php/php.exe') { 'C:/xaamp/php/php.exe' } else { (Get-Command php).Source }
Push-Location -LiteralPath $taskRoot
try {
    $taskFixer = 'tests/.tools/quality/vendor/bin/php-cs-fixer'
    $taskStan = 'tests/.tools/quality/vendor/bin/phpstan'
    if (-not (Test-Path $taskFixer) -or -not (Test-Path $taskStan)) {
        throw 'Instala tests/quality/composer.lock con COMPOSER_VENDOR_DIR dirigido a tests/.tools/quality/vendor. Consulta la guía del lote I.'
    }
    & $taskPhp $taskFixer fix --config=.php-cs-fixer.dist.php --dry-run --sequential --diff
    if ($LASTEXITCODE -ne 0) { throw 'El código no cumple PSR-12.' }
    & $taskPhp $taskStan analyse --configuration=phpstan.neon --no-progress --memory-limit=1G
    if ($LASTEXITCODE -ne 0) { throw 'PHPStan encontró errores.' }
} finally {
    Pop-Location
}

