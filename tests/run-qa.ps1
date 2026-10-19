$ErrorActionPreference='Stop'
$taskProjectRoot=Split-Path -Parent $PSScriptRoot
$taskOldQA=[Environment]::GetEnvironmentVariable('MM_RUN_QA','Process')
$taskOldOpenSSL=[Environment]::GetEnvironmentVariable('OPENSSL_CONF','Process')
$taskPHP='C:\xaamp\php\php.exe'
if(-not (Test-Path -LiteralPath $taskPHP)){$taskPHP=(Get-Command php).Source}
Push-Location -LiteralPath $taskProjectRoot
try {
 $env:MM_RUN_QA='1'
 $taskQARoot = & $taskPHP -r "require 'tests/paths.php'; echo musicmadQaRoot();"
 if ($LASTEXITCODE -ne 0) { throw 'No se pudo preparar la ruta QA externa.' }
 $taskCacheRoot = Join-Path $taskQARoot 'phpunit-cache'
 if(-not $taskOldOpenSSL -and (Test-Path -LiteralPath 'C:\xaamp\php\extras\ssl\openssl.cnf')){$env:OPENSSL_CONF='C:\xaamp\php\extras\ssl\openssl.cnf'}
 & $taskPHP tests/.tools/phpunit.phar --configuration phpunit.xml --cache-directory $taskCacheRoot
 $taskExit=$LASTEXITCODE
 if($taskExit -eq 0){node --test tests/sw-security.test.js tests/pending-actions.test.js tests/pwa-update.test.js tests/qr.test.js tests/waveform.test.js tests/recorder.test.js tests/listening.test.js tests/refresh.test.js tests/reaction-burst.test.js tests/reaction-menu.test.js tests/navigation.test.js tests/playback-errors.test.js tests/app-badge.test.js tests/scene-map.test.js tests/qa-paths.test.js tests/profile-media-url.test.js tests/i18n-client.test.js tests/stage.test.js;$taskExit=$LASTEXITCODE}
} finally {
 [Environment]::SetEnvironmentVariable('MM_RUN_QA',$taskOldQA,'Process')
 [Environment]::SetEnvironmentVariable('OPENSSL_CONF',$taskOldOpenSSL,'Process')
 Pop-Location
}
exit $taskExit
