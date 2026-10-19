# The old static fixture could not exercise a PHP front controller.
# Use actual Apache + PHP and the isolated musicmad_qa database instead.
$ErrorActionPreference = 'Stop'
Push-Location -LiteralPath (Split-Path -Parent $PSScriptRoot)
try {
    python tests/run-routing-apache.py
    $taskExit = $LASTEXITCODE
} finally { Pop-Location }
exit $taskExit
