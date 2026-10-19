$ErrorActionPreference = 'Stop'
$taskRoot = [IO.Path]::GetFullPath((Split-Path -Parent $PSScriptRoot)).TrimEnd('\')
. (Join-Path $PSScriptRoot 'release-paths.ps1')
$taskPaths = Initialize-MusicMadReleasePaths $taskRoot
$taskOutput = Get-MusicMadReleasePath $taskPaths 'MusicMad_v100_lote_G1_idiomas.zip'
Assert-MusicMadReleaseAvailable $taskOutput
$taskBase = Get-MusicMadReleasePath $taskPaths 'MusicMad_v98_lote_F_notificaciones.zip'
$taskBaseDigest = '1595932dc2daf7d417fb2955630ae5e37209556dcd5b4d63e93cd8a8bdf9291a'
if ((Get-FileHash -LiteralPath $taskBase -Algorithm SHA256).Hash.ToLowerInvariant() -cne $taskBaseDigest) { throw 'La base F no coincide con la entrega auditada.' }
Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem
$taskFiles = [Collections.Generic.Dictionary[string, byte[]]]::new([StringComparer]::Ordinal)
$taskSeen = [Collections.Generic.HashSet[string]]::new([StringComparer]::OrdinalIgnoreCase)
$taskArchive = [IO.Compression.ZipFile]::OpenRead($taskBase)
try {
    foreach ($entry in $taskArchive.Entries) {
        Assert-MusicMadPackagePath $entry.FullName
        if (-not $taskSeen.Add($entry.FullName)) { throw 'Entradas duplicadas en la base.' }
        if ((($entry.ExternalAttributes -shr 16) -band 0xF000) -eq 0xA000) { throw 'La base tiene enlaces.' }
        $input = $entry.Open(); $memory = [IO.MemoryStream]::new()
        try { $input.CopyTo($memory); $taskFiles[$entry.FullName] = $memory.ToArray() }
        finally { $input.Dispose(); $memory.Dispose() }
    }
} finally { $taskArchive.Dispose() }
$taskOverrides = @(
    'includes/i18n.php',
    'idioma.php',
    'manifesto.php',
    'CSS/language.css',
    'includes/auth.php',
    'includes/header.php',
    'includes/email_required.php',
    'includes/onboarding_required.php',
    'includes/navigation.php',
    'includes/navbar.php',
    'menu.php',
    'login.php',
    'registro.php',
    'configuracion.php',
    'includes/email_account.php',
    'includes/account_export.php',
    'includes/web_push.php',
    'api/v1/index.php',
    'js/navigation.js',
    'js/push-texts.js',
    'sw.js',
    'config/migrations/035_user_locale.sql',
    'config/actualizar_musicmad_v100.sql',
    'CSS/social.css',
    'feed.php',
    'includes/global_search.php',
    'includes/publicacion.php',
    'includes/linked_text.php',
    'includes/locales/es.php',
    'includes/locales/en.php',
    'includes/locales/pt-BR.php',
    'includes/locales/fr.php',
    'includes/locales/de.php',
    'includes/locales/it.php',
    'includes/locales/ja.php',
    'includes/locales/ko.php',
    'includes/locales/zh-CN.php'
)
foreach ($name in $taskOverrides) {
    Assert-MusicMadPackagePath $name
    $path = [IO.Path]::GetFullPath((Join-Path $taskRoot $name))
    if (-not $path.StartsWith($taskRoot + '\', [StringComparison]::OrdinalIgnoreCase)) { throw 'Ruta fuera del proyecto.' }
    $ancestor = $path
    while ($ancestor -and $ancestor -cne $taskRoot) {
        if (((Get-Item -LiteralPath $ancestor -Force).Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0) { throw 'No se admiten enlaces.' }
        $ancestor = Split-Path -Parent $ancestor
    }
    $taskFiles[$name] = [IO.File]::ReadAllBytes($path)
}
$taskUtf8 = [Text.UTF8Encoding]::new($false, $true)
if (-not $taskUtf8.GetString($taskFiles['sw.js']).Contains("const VERSION = 'v100';")) { throw 'Worker incorrecto.' }
if (-not $taskUtf8.GetString($taskFiles['includes/header.php']).Contains("`$assetVersion = '100';")) { throw 'Cabecera incorrecta.' }
$taskWorker = $taskUtf8.GetString($taskFiles['sw.js'])
$taskShell = [regex]::Match($taskWorker, '(?s)const\s+SHELL\s*=\s*\[(?<entries>.*?)\];')
if (-not $taskShell.Success) { throw 'SHELL no auditable.' }
$taskLists = @($taskShell.Groups['entries'].Value)
foreach ($match in [regex]::Matches($taskWorker, '(?:importScripts|SHELL\.push)\((?<entries>[^)]+)\)')) { $taskLists += $match.Groups['entries'].Value }
foreach ($list in $taskLists) {
    foreach ($match in [regex]::Matches($list, '[''"](?<path>[^''"]+)[''"]')) {
        $asset = $match.Groups['path'].Value
        Assert-MusicMadPackagePath $asset
        if (-not $taskFiles.ContainsKey($asset)) { throw ('Recurso del worker sin entregar: ' + $asset) }
    }
}
$taskStage = Get-MusicMadBuildStage $taskPaths 'v100-lote-G1'
$taskRecords = [Collections.Generic.List[object]]::new()
$taskStream = [IO.File]::Open($taskOutput + '.part', [IO.FileMode]::CreateNew)
$taskZip = [IO.Compression.ZipArchive]::new($taskStream, [IO.Compression.ZipArchiveMode]::Create, $false)
try {
    foreach ($name in @($taskFiles.Keys | Sort-Object)) {
        Assert-MusicMadPackagePath $name
        $bytes = $taskFiles[$name]
        $entry = $taskZip.CreateEntry($name, [IO.Compression.CompressionLevel]::Optimal)
        $output = $entry.Open()
        try { $output.Write($bytes, 0, $bytes.Length) } finally { $output.Dispose() }
        $destination = Join-Path $taskStage $name
        [IO.Directory]::CreateDirectory((Split-Path -Parent $destination)) | Out-Null
        [IO.File]::WriteAllBytes($destination, $bytes)
        $taskRecords.Add([ordered]@{archivo=$name; bytes=$bytes.Length; sha256=(Get-FileHash -LiteralPath $destination -Algorithm SHA256).Hash.ToLowerInvariant()})
    }
} finally { $taskZip.Dispose() }
$taskAudit = [IO.Compression.ZipFile]::OpenRead($taskOutput + '.part')
try {
    if ($taskAudit.Entries.Count -ne $taskRecords.Count -or -not $taskAudit.GetEntry('index.php')) { throw 'ZIP incompleto o anidado.' }
    foreach ($record in $taskRecords) {
        $entry = $taskAudit.GetEntry($record.archivo)
        Assert-MusicMadPackagePath $entry.FullName
        $input = $entry.Open(); $hash = [Security.Cryptography.SHA256]::Create()
        try { $digest = [BitConverter]::ToString($hash.ComputeHash($input)).Replace('-', '').ToLowerInvariant() }
        finally { $input.Dispose(); $hash.Dispose() }
        if ($digest -cne $record.sha256) { throw 'Fallo de integridad del ZIP.' }
    }
} finally { $taskAudit.Dispose() }
Move-Item -LiteralPath ($taskOutput + '.part') -Destination $taskOutput
$taskDigest = (Get-FileHash -LiteralPath $taskOutput -Algorithm SHA256).Hash.ToLowerInvariant()
Write-MusicMadManifestSidecar $taskOutput ([ordered]@{
    version='100'; lote='G1'; acumulativo=$true; base_sha256=$taskBaseDigest; zip_sha256=$taskDigest
    sql='config/migrations/035_user_locale.sql'; sql_nuevo=$true; desplegado=$false; archivos=$taskRecords
    archivos_tocados=$taskOverrides; secretos_y_medios_excluidos=$true
})
[IO.File]::WriteAllText($taskOutput + '.sha256', $taskDigest + '  ' + [IO.Path]::GetFileName($taskOutput) + "`n", $taskUtf8)
[pscustomobject]@{zip=$taskOutput; staging=$taskStage; archivos=$taskRecords.Count; sha256=$taskDigest} | ConvertTo-Json
