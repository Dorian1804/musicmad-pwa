$ErrorActionPreference = 'Stop'
$taskRoot = [IO.Path]::GetFullPath((Split-Path -Parent $PSScriptRoot)).TrimEnd('\')
. (Join-Path $PSScriptRoot 'release-paths.ps1')
$taskPaths = Initialize-MusicMadReleasePaths $taskRoot
$taskOutput = Get-MusicMadReleasePath $taskPaths 'MusicMad_v92_lote_A_seguridad.zip'
Assert-MusicMadReleaseAvailable $taskOutput
$taskPartial = $taskOutput + '.part'
$taskEncoding = [Text.UTF8Encoding]::new($false, $true)
Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem

function Get-SecurityReleaseDigest([byte[]]$Data) {
    $hash = [Security.Cryptography.SHA256]::Create()
    try { [BitConverter]::ToString($hash.ComputeHash($Data)).Replace('-', '').ToLowerInvariant() }
    finally { $hash.Dispose() }
}

function Assert-SecurityReleaseInput([string]$Path) {
    $item = Get-Item -LiteralPath $Path -Force
    if ($item.PSIsContainer -or ($item.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0) {
        throw 'La base de entrega debe ser un archivo regular.'
    }
}

$taskBaseName = 'MusicMad_v85_actualizacion_2026-10-13.zip'
$taskBasePath = Get-MusicMadReleasePath $taskPaths $taskBaseName
$taskBaseChecksumPath = Get-MusicMadReleasePath $taskPaths ($taskBaseName + '.sha256')
Assert-SecurityReleaseInput $taskBasePath
Assert-SecurityReleaseInput $taskBaseChecksumPath
$taskChecksumText = [IO.File]::ReadAllText($taskBaseChecksumPath, $taskEncoding)
$taskChecksumPattern = '\A(?<sha>[a-fA-F0-9]{64})[ \t]+\*?' + [regex]::Escape($taskBaseName) + '(?:\r?\n)?\z'
$taskChecksumMatch = [regex]::Match($taskChecksumText, $taskChecksumPattern)
if (-not $taskChecksumMatch.Success) { throw 'El sidecar v85 debe contener una sola huella SHA-256 y el nombre exacto del ZIP.' }
$taskBaseDigest = $taskChecksumMatch.Groups['sha'].Value.ToLowerInvariant()
if ((Get-FileHash -LiteralPath $taskBasePath -Algorithm SHA256).Hash.ToLowerInvariant() -cne $taskBaseDigest) {
    throw 'La base v85 no coincide con su sidecar auditado.'
}
$taskPatchPath = Get-MusicMadReleasePath $taskPaths 'MusicMad_parche_v90_audio.zip'
$taskPatchDigest = '3a5ceabea1c3e623a7aea563ce259f138d76fba27f2c09a948019babcf9c99bd'
Assert-SecurityReleaseInput $taskPatchPath
if ((Get-FileHash -LiteralPath $taskPatchPath -Algorithm SHA256).Hash.ToLowerInvariant() -cne $taskPatchDigest) {
    throw 'La base v90 no coincide con la entrega auditada.'
}

$taskFiles = [Collections.Generic.Dictionary[string, byte[]]]::new([StringComparer]::Ordinal)
function Merge-SecurityReleaseArchive([string]$Path) {
    $archive = [IO.Compression.ZipFile]::OpenRead($Path)
    $names = [Collections.Generic.HashSet[string]]::new([StringComparer]::OrdinalIgnoreCase)
    try {
        foreach ($entry in $archive.Entries) {
            $name = $entry.FullName
            if ($name.EndsWith('/')) { continue }
            # Legacy archives are immutable. Excluded media, notes, manifests
            # and staging are discarded by name before their bytes are read.
            if (-not (Test-MusicMadPackagePath $name)) { continue }
            if (-not $names.Add($name)) { throw ('Entrada duplicada en base: ' + $name) }
            if ((($entry.ExternalAttributes -shr 16) -band 0xF000) -eq 0xA000) { throw 'La base contiene un enlace simbólico.' }
            $input = $entry.Open()
            $memory = [IO.MemoryStream]::new()
            try {
                $input.CopyTo($memory)
                if ($memory.Length -ne $entry.Length) { throw ('Entrada incompleta en base: ' + $name) }
                $taskFiles[$name] = $memory.ToArray()
            } finally { $input.Dispose(); $memory.Dispose() }
        }
    } finally { $archive.Dispose() }
}
Merge-SecurityReleaseArchive $taskBasePath
Merge-SecurityReleaseArchive $taskPatchPath

# This is deliberately a whitelist. No recursive scan of the live app, private
# configuration, uploads, backups or QA fixtures can become part of the release.
$taskOverrides = @(
    '.htaccess', '.gitattributes', '.editorconfig', 'config/database.php',
    'audio.php', 'video.php', 'partitura.php', 'media-image.php',
    'includes/media_storage.php', 'includes/media_backup.php',
    'includes/upload_transport.php', 'includes/auth.php', 'includes/social.php',
    'includes/avatar_upload.php', 'includes/global_search.php',
    'includes/artist_statistics.php', 'includes/listening_rooms.php',
    'includes/audio_waveform.php', 'includes/clip_stories.php',
    'includes/publicacion.php', 'includes/video_recovery.php',
    'includes/header.php', 'bin/backup-media.php'
)
foreach ($name in $taskOverrides) {
    Assert-MusicMadPackagePath $name
    $path = [IO.Path]::GetFullPath((Join-Path $taskRoot $name))
    if (-not $path.StartsWith($taskRoot + '\', [StringComparison]::OrdinalIgnoreCase)) { throw 'Override fuera del proyecto.' }
    $ancestor = $path
    while ($ancestor -and -not $ancestor.Equals($taskRoot, [StringComparison]::OrdinalIgnoreCase)) {
        $item = Get-Item -LiteralPath $ancestor -Force
        if (($item.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0) { throw 'Override con enlace no permitido.' }
        $ancestor = Split-Path -Parent $ancestor
    }
    $before = Get-Item -LiteralPath $path -Force
    if ($before.PSIsContainer) { throw ('El override no es un archivo: ' + $name) }
    $size = $before.Length
    $modified = $before.LastWriteTimeUtc.Ticks
    $bytes = [IO.File]::ReadAllBytes($path)
    $after = Get-Item -LiteralPath $path -Force
    if ($after.Length -ne $size -or $after.LastWriteTimeUtc.Ticks -ne $modified) { throw ('El override cambió durante el build: ' + $name) }
    $taskFiles[$name] = $bytes
}

# The full v85 cut does not deliver v86 verification or SQL031. Existing hosting
# installations may already contain that module: preserve it through an optional
# include in shipping bytes only, without rewriting includes/social.php on disk.
$taskSocial = $taskEncoding.GetString($taskFiles['includes/social.php'])
$taskVerificationLine = "require_once __DIR__.'/account_verification.php';"
$taskVerificationOptional = "if (is_file(__DIR__.'/account_verification.php')) require_once __DIR__.'/account_verification.php';"
$taskVerificationPattern = '(?m)^' + [regex]::Escape($taskVerificationLine) + '(?:\r?\n|\z)'
if ([regex]::Matches($taskSocial, $taskVerificationPattern).Count -ne 1) { throw 'Se esperaba exactamente la línea local de verificación de cuenta.' }
$taskSocial = [regex]::Replace($taskSocial, $taskVerificationPattern, $taskVerificationOptional + [Environment]::NewLine)
$taskFiles['includes/social.php'] = $taskEncoding.GetBytes($taskSocial)
if ($taskFiles.ContainsKey('includes/account_verification.php')) { throw 'El lote A no entrega el módulo v86 ni SQL031.' }

$taskWorker = $taskEncoding.GetString($taskFiles['sw.js'])
$taskWorkerVersion = "const VERSION = 'v90';"
if ([regex]::Matches($taskWorker, [regex]::Escape($taskWorkerVersion)).Count -ne 1) { throw 'La base del worker no es v90.' }
$taskWorker = $taskWorker.Replace($taskWorkerVersion, "const VERSION = 'v92';")
if ($taskWorker.Contains('verification.css') -or $taskWorker.Contains('account_verification')) { throw 'El worker incluye desarrollo no entregado.' }
$taskFiles['sw.js'] = $taskEncoding.GetBytes($taskWorker)
if (-not $taskEncoding.GetString($taskFiles['includes/header.php']).Contains('$assetVersion = ''92'';')) { throw 'La cabecera todavía no anuncia la versión 92.' }

$taskRequired = @(
    'index.php', 'feed.php', '.htaccess', '.gitattributes', '.editorconfig',
    'vendor/autoload.php', 'vendor/composer/platform_check.php',
    'config/actualizar_musicmad_v85.sql', 'storage/.htaccess',
    'includes/media_storage.php', 'includes/media_backup.php', 'media-image.php'
)
foreach ($name in $taskRequired) {
    if (-not $taskFiles.ContainsKey($name)) { throw ('Falta runtime requerido: ' + $name) }
}
for ($number = 1; $number -le 30; $number++) {
    $prefix = 'config/migrations/' + $number.ToString('000') + '_'
    if (@($taskFiles.Keys | Where-Object { $_.StartsWith($prefix, [StringComparison]::Ordinal) -and $_.EndsWith('.sql') }).Count -ne 1) {
        throw ('Falta o está duplicada la migración existente: ' + $number.ToString('000'))
    }
}
$taskCaseNames = [Collections.Generic.HashSet[string]]::new([StringComparer]::OrdinalIgnoreCase)
foreach ($name in $taskFiles.Keys) {
    Assert-MusicMadPackagePath $name
    if (-not $taskCaseNames.Add($name)) { throw ('Colisión de mayúsculas en entrega: ' + $name) }
    if ($name -match '^config/migrations/(?<number>[0-9]{3})_' -and [int]$Matches['number'] -gt 30) { throw 'Este lote no entrega SQL posterior a 030.' }
}

# Resolve production require/include paths introduced by current overrides.
# Dynamic private configuration remains intentionally absent. The sole QA-only
# require is guarded by MUSICMAD_TEST_RUN and is not a production dependency.
$taskDependencyChecks = 0
$taskIncludePatterns = @(
    '(?m)\b(?:require|include)(?:_once)?\s+__DIR__\s*\.\s*[''"](?<tail>[^''"]+)[''"]\s*;',
    '(?m)\b(?:require|include)(?:_once)?\s+dirname\(__DIR__\)\s*\.\s*[''"](?<tail>[^''"]+)[''"]\s*;',
    '(?m)\b(?:require|include)(?:_once)?\s+APP_ROOT\s*\.\s*[''"](?<tail>[^''"]+)[''"]\s*;'
)
foreach ($name in @($taskOverrides | Where-Object { $_.EndsWith('.php') })) {
    $text = $taskEncoding.GetString($taskFiles[$name])
    $sourceDirectory = Split-Path -Parent (Join-Path $taskRoot $name)
    for ($index = 0; $index -lt $taskIncludePatterns.Count; $index++) {
        foreach ($match in [regex]::Matches($text, $taskIncludePatterns[$index])) {
            $base = if ($index -eq 0) { $sourceDirectory } elseif ($index -eq 1) { Split-Path -Parent $sourceDirectory } else { $taskRoot }
            $dependency = [IO.Path]::GetFullPath((Join-Path $base $match.Groups['tail'].Value.TrimStart('/', '\')))
            if (-not $dependency.StartsWith($taskRoot + '\', [StringComparison]::OrdinalIgnoreCase)) { throw ('Require fuera de la entrega: ' + $name) }
            $relative = $dependency.Substring($taskRoot.Length + 1).Replace('\', '/')
            if ($name -ceq 'includes/media_backup.php' -and $relative -ceq 'tests/paths.php' -and $text.Contains("defined('MUSICMAD_TEST_RUN')")) { continue }
            if ($name -ceq 'includes/social.php' -and $relative -ceq 'includes/account_verification.php' -and $text.Contains($taskVerificationOptional)) { continue }
            if (-not $taskFiles.ContainsKey($relative)) { throw ('Require sin módulo entregado: ' + $name + ' -> ' + $relative) }
            $taskDependencyChecks++
        }
    }
}

$taskShell = [regex]::Match($taskWorker, '(?s)const\s+SHELL\s*=\s*\[(?<entries>.*?)\];')
if (-not $taskShell.Success) { throw 'No se puede auditar SHELL del worker.' }
$taskWorkerReferences = [Collections.Generic.HashSet[string]]::new([StringComparer]::Ordinal)
$taskAssetLists = @($taskShell.Groups['entries'].Value)
foreach ($match in [regex]::Matches($taskWorker, '\bimportScripts\((?<entries>[^)]+)\)')) { $taskAssetLists += $match.Groups['entries'].Value }
foreach ($list in $taskAssetLists) {
    foreach ($match in [regex]::Matches($list, '[''"](?<path>[^''"]+)[''"]')) {
        $asset = $match.Groups['path'].Value
        Assert-MusicMadPackagePath $asset
        if (-not $taskFiles.ContainsKey($asset)) { throw ('El worker solicita un recurso no entregado: ' + $asset) }
        $taskWorkerReferences.Add($asset) | Out-Null
    }
}

$taskRecords = [Collections.Generic.List[object]]::new()
$taskStream = [IO.File]::Open($taskPartial, [IO.FileMode]::CreateNew, [IO.FileAccess]::ReadWrite, [IO.FileShare]::None)
$taskZip = [IO.Compression.ZipArchive]::new($taskStream, [IO.Compression.ZipArchiveMode]::Create, $false)
try {
    foreach ($name in @($taskFiles.Keys | Sort-Object)) {
        $bytes = $taskFiles[$name]
        $entry = $taskZip.CreateEntry($name, [IO.Compression.CompressionLevel]::Optimal)
        $output = $entry.Open()
        try { $output.Write($bytes, 0, $bytes.Length) } finally { $output.Dispose() }
        $taskRecords.Add([ordered]@{ archivo = $name; bytes = $bytes.Length; sha256 = (Get-SecurityReleaseDigest $bytes) })
    }
} finally { $taskZip.Dispose() }

$taskAudit = [IO.Compression.ZipFile]::OpenRead($taskPartial)
try {
    if ($taskAudit.Entries.Count -ne $taskRecords.Count) { throw 'Conteo ZIP inesperado.' }
    foreach ($entry in $taskAudit.Entries) { Assert-MusicMadPackagePath $entry.FullName }
    foreach ($record in $taskRecords) {
        $entry = $taskAudit.GetEntry($record.archivo)
        if ($null -eq $entry -or $entry.Length -ne $record.bytes) { throw ('Tamaño ZIP inválido: ' + $record.archivo) }
        $input = $entry.Open()
        $hash = [Security.Cryptography.SHA256]::Create()
        try { $digest = [BitConverter]::ToString($hash.ComputeHash($input)).Replace('-', '').ToLowerInvariant() }
        finally { $input.Dispose(); $hash.Dispose() }
        if ($digest -cne $record.sha256) { throw ('SHA-256 ZIP inválido: ' + $record.archivo) }
    }
    if ($null -eq $taskAudit.GetEntry('index.php') -or $null -ne $taskAudit.GetEntry('MusicMad_PWA/index.php')) { throw 'El ZIP no es plano.' }
} finally { $taskAudit.Dispose() }

# Stage a verified copy of runtime only, outside the app. No directory cleanup,
# user files, application execution or database connection is performed here.
$taskStage = Get-MusicMadBuildStage $taskPaths 'v92-lote-A'
foreach ($record in $taskRecords) {
    $destination = [IO.Path]::GetFullPath((Join-Path $taskStage $record.archivo))
    if (-not $destination.StartsWith($taskStage + '\', [StringComparison]::OrdinalIgnoreCase)) { throw 'Destino fuera de staging.' }
    [IO.Directory]::CreateDirectory((Split-Path -Parent $destination)) | Out-Null
    [IO.File]::WriteAllBytes($destination, $taskFiles[$record.archivo])
    if ((Get-Item -LiteralPath $destination).Length -ne $record.bytes -or
        (Get-FileHash -LiteralPath $destination -Algorithm SHA256).Hash.ToLowerInvariant() -cne $record.sha256) {
        throw ('Integridad de staging inválida: ' + $record.archivo)
    }
}
if (@(Get-ChildItem -LiteralPath $taskStage -File -Force -Recurse).Count -ne $taskRecords.Count) { throw 'Conteo de staging inesperado.' }

# The hosting delta contains only the explicit current overrides and the release
# worker. It never reuploads dependencies or removes pre-existing host modules.
$taskDeltaNames = @($taskOverrides) + @('sw.js')
$taskDeltaRecords = @($taskRecords | Where-Object { $taskDeltaNames -ccontains $_.archivo })
if ($taskDeltaRecords.Count -ne $taskDeltaNames.Count) { throw 'Conteo de delta inesperado.' }
$taskDeltaStage = Get-MusicMadBuildStage $taskPaths 'v92-lote-A-delta'
foreach ($record in $taskDeltaRecords) {
    $destination = [IO.Path]::GetFullPath((Join-Path $taskDeltaStage $record.archivo))
    if (-not $destination.StartsWith($taskDeltaStage + '\', [StringComparison]::OrdinalIgnoreCase)) { throw 'Destino fuera del staging delta.' }
    [IO.Directory]::CreateDirectory((Split-Path -Parent $destination)) | Out-Null
    [IO.File]::WriteAllBytes($destination, $taskFiles[$record.archivo])
    if ((Get-Item -LiteralPath $destination).Length -ne $record.bytes -or
        (Get-FileHash -LiteralPath $destination -Algorithm SHA256).Hash.ToLowerInvariant() -cne $record.sha256) {
        throw ('Integridad de delta inválida: ' + $record.archivo)
    }
}
if (@(Get-ChildItem -LiteralPath $taskDeltaStage -File -Force -Recurse).Count -ne $taskDeltaRecords.Count) { throw 'Conteo de staging delta inesperado.' }

Move-Item -LiteralPath $taskPartial -Destination $taskOutput
$taskDigest = (Get-FileHash -LiteralPath $taskOutput -Algorithm SHA256).Hash.ToLowerInvariant()
$taskManifest = [ordered]@{
    version = '92'; lote = 'A'; base = '85'; incluye_parches = @('87', '88', '89', '90')
    fecha_utc = [DateTime]::UtcNow.ToString('o'); tipo = 'Entrega completa: seguridad, limpieza y medios persistentes'
    sql_nuevo = $false; migraciones_existentes = '001-030'; sql_acumulativo_previo = 'config/actualizar_musicmad_v85.sql'
    desplegado = $false; hosting_verificado = $false; instrucciones_fuera_del_zip = $true
    base_sha256 = $taskBaseDigest; parche_v90_sha256 = $taskPatchDigest; zip_sha256 = $taskDigest
    archivos_verificados = $taskRecords.Count; dependencias_php_verificadas = $taskDependencyChecks
    recursos_worker_verificados = $taskWorkerReferences.Count; archivos = $taskRecords; delta_archivos = $taskDeltaRecords
}
Write-MusicMadManifestSidecar $taskOutput $taskManifest
[IO.File]::WriteAllText($taskOutput + '.sha256', $taskDigest + '  ' + [IO.Path]::GetFileName($taskOutput) + [Environment]::NewLine, [Text.UTF8Encoding]::new($false))
[pscustomobject]@{ zip = $taskOutput; staging = $taskStage; staging_delta = $taskDeltaStage; archivos = $taskRecords.Count; archivos_delta = $taskDeltaRecords.Count; bytes = (Get-Item -LiteralPath $taskOutput).Length; sha256 = $taskDigest } | ConvertTo-Json
