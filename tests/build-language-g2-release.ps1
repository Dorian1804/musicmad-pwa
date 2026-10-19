$ErrorActionPreference = 'Stop'
$taskRoot = [IO.Path]::GetFullPath((Split-Path -Parent $PSScriptRoot)).TrimEnd('\')
. (Join-Path $PSScriptRoot 'release-paths.ps1')
$taskPaths = Initialize-MusicMadReleasePaths $taskRoot
$taskOutput = Get-MusicMadReleasePath $taskPaths 'MusicMad_v102_lote_G2_idiomas.zip'
Assert-MusicMadReleaseAvailable $taskOutput
$taskBase = Get-MusicMadReleasePath $taskPaths 'MusicMad_v101_lote_G1_idiomas.zip'
$taskBaseDigest = 'c4d98ee61dad6c6e2526815d85eca53b0ec915b93c454515f57320b0b4aee1a9'
if ((Get-FileHash -LiteralPath $taskBase -Algorithm SHA256).Hash.ToLowerInvariant() -cne $taskBaseDigest) { throw 'La base G1 no coincide con la entrega auditada.' }
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
    'includes/locales/es.php',
    'includes/locales/en.php',
    'includes/locales/pt-BR.php',
    'includes/locales/fr.php',
    'includes/locales/de.php',
    'includes/locales/it.php',
    'includes/locales/ja.php',
    'includes/locales/ko.php',
    'includes/locales/zh-CN.php',
    'assets/i18n/es.js',
    'assets/i18n/en.js',
    'assets/i18n/pt-BR.js',
    'assets/i18n/fr.js',
    'assets/i18n/de.js',
    'assets/i18n/it.js',
    'assets/i18n/ja.js',
    'assets/i18n/ko.js',
    'assets/i18n/zh-CN.js',
    'includes/i18n.php',
    'includes/header.php',
    'includes/onboarding_page.php',
    'includes/onboarding_required.php',
    'bienvenida-accion.php',
    'feed.php',
    'perfil.php',
    'js/i18n.js',
    'js/onboarding.js',
    'js/perfil.js',
    'js/sheets.js',
    'js/navigation.js',
    'sw.js'
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
if (-not $taskUtf8.GetString($taskFiles['sw.js']).Contains("const VERSION = 'v102';")) { throw 'Worker incorrecto.' }
if (-not $taskUtf8.GetString($taskFiles['includes/header.php']).Contains("`$assetVersion = '102';")) { throw 'Cabecera incorrecta.' }
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
$taskStage = Get-MusicMadBuildStage $taskPaths 'v102-lote-G2'
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
    version='102'; lote='G2'; acumulativo=$true; base_sha256=$taskBaseDigest; zip_sha256=$taskDigest
    sql=$null; sql_nuevo=$false; desplegado=$false; archivos=$taskRecords
    archivos_tocados=$taskOverrides; secretos_y_medios_excluidos=$true
})
[IO.File]::WriteAllText($taskOutput + '.sha256', $taskDigest + '  ' + [IO.Path]::GetFileName($taskOutput) + "`n", $taskUtf8)
[pscustomobject]@{zip=$taskOutput; staging=$taskStage; archivos=$taskRecords.Count; sha256=$taskDigest} | ConvertTo-Json
