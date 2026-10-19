param([Parameter(Mandatory=$true)][string]$Output, [switch]$WithoutVendor)
$ErrorActionPreference='Stop'
$taskPHP = if (Get-Command php -ErrorAction SilentlyContinue) { (Get-Command php).Source } else { 'C:/xaamp/php/php.exe' }
$taskArguments=@((Join-Path $PSScriptRoot 'package.php'), '--output', $Output)
if($WithoutVendor){$taskArguments+='--without-vendor'}
& $taskPHP @taskArguments
exit $LASTEXITCODE
