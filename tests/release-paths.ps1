$ErrorActionPreference = 'Stop'

# The deployable tree never contains archives, delivery notes or release manifests.
# Set MM_RELEASE_DIR to an existing private workspace when the sibling default is
# not writable. This helper never reads application configuration or user media.
function Initialize-MusicMadReleasePaths([string]$ProjectRoot) {
    $project = [IO.Path]::GetFullPath($ProjectRoot).TrimEnd('\', '/')
    $configured = [Environment]::GetEnvironmentVariable('MM_RELEASE_DIR')
    $locationFile = Join-Path $PSScriptRoot 'release-location.txt'
    if ([string]::IsNullOrWhiteSpace($configured) -and (Test-Path -LiteralPath $locationFile -PathType Leaf)) {
        $locationItem = Get-Item -LiteralPath $locationFile -Force
        if (($locationItem.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0) { throw 'La ubicación local de entregas no admite enlaces.' }
        $configured = [IO.File]::ReadAllText($locationFile).Trim()
        if ([string]::IsNullOrWhiteSpace($configured)) { throw 'La ubicación local de entregas está vacía.' }
    }
    $directory = if ([string]::IsNullOrWhiteSpace($configured)) {
        Join-Path (Split-Path -Parent $project) 'MusicMad-entregas'
    } else {
        [IO.Path]::GetFullPath($configured)
    }
    $directory = [IO.Path]::GetFullPath($directory).TrimEnd('\', '/')
    if ($directory.Equals($project, [StringComparison]::OrdinalIgnoreCase) -or
        $directory.StartsWith($project + '\', [StringComparison]::OrdinalIgnoreCase)) {
        throw 'MM_RELEASE_DIR debe estar fuera de la carpeta pública del proyecto.'
    }
    # Do not follow junctions/symlinks in an output path, including its parents.
    $ancestor = $directory
    while ($ancestor) {
        if (Test-Path -LiteralPath $ancestor) {
            $item = Get-Item -LiteralPath $ancestor -Force
            if (($item.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0) {
                throw 'La ruta de entregas no admite enlaces ni junctions.'
            }
        }
        $parent = Split-Path -Parent $ancestor
        if ($parent -eq $ancestor) { break }
        $ancestor = $parent
    }
    [IO.Directory]::CreateDirectory($directory) | Out-Null
    $hash = [Security.Cryptography.SHA256]::Create()
    try {
        $fingerprint = [BitConverter]::ToString($hash.ComputeHash([Text.Encoding]::UTF8.GetBytes($project))).Replace('-', '').Substring(0, 16).ToLowerInvariant()
    } finally { $hash.Dispose() }
    $stage = [IO.Path]::GetFullPath((Join-Path ([IO.Path]::GetTempPath()) ('MusicMad-release-staging-' + $fingerprint))).TrimEnd('\', '/')
    if ($stage.Equals($project, [StringComparison]::OrdinalIgnoreCase) -or
        $stage.StartsWith($project + '\', [StringComparison]::OrdinalIgnoreCase)) {
        throw 'El staging de entregas debe estar fuera de la carpeta pública.'
    }
    [pscustomobject]@{ ProjectRoot = $project; ReleaseDir = $directory; StageRoot = $stage }
}

function Get-MusicMadReleasePath($Paths, [string]$Name) {
    if ([IO.Path]::GetFileName($Name) -ne $Name -or $Name -in @('', '.', '..') -or $Name.Contains(':')) {
        throw 'Nombre de entrega inválido.'
    }
    Join-Path $Paths.ReleaseDir $Name
}

function Test-MusicMadPackagePath([string]$Name) {
    if ([string]::IsNullOrWhiteSpace($Name) -or $Name.Contains('\') -or $Name.Contains(':') -or
        $Name -match '[\x00-\x1F\x7F]|(^/|//|(^|/)\.\.?(/|$)|(^|/)[^/]*[ .](/|$))') { return $false }
    if ($Name -match '(?i)(^|/)(\.env[^/]*|local[.]php|auth[.]json|uploads|backups|tests?|\.tests-tmp|\.phpunit[.]cache|\.git|\.github|node_modules|docs?)(/|$)') { return $false }
    if ($Name -match '(?i)^storage/' -and $Name -cne 'storage/.htaccess') { return $false }
    if ($Name -match '(?i)(^|/)MANIFIESTO[^/]*[.]json$|[.](zip|7z|rar|tar|gz|sha256|part)$') { return $false }
    # Delivery/audit Markdown is not a runtime asset. Vendor license Markdown may
    # remain under the already-private dependency tree for license compliance.
    if ($Name -match '(?i)[.]md$' -and $Name -notmatch '(?i)^vendor/.*(?:LICENSE|COPYING|NOTICE)[^/]*[.]md$') { return $false }
    if ($Name -match '(?i)(demo_seed|seed-demo|get_oauth_token)[.]php$') { return $false }
    return $true
}

function Assert-MusicMadPackagePath([string]$Name) {
    if (-not (Test-MusicMadPackagePath $Name)) { throw ('Contenido prohibido en entrega: ' + $Name) }
}

function Assert-MusicMadReleaseAvailable([string]$ArchivePath) {
    foreach ($target in @($ArchivePath, ($ArchivePath + '.part'), ($ArchivePath + '.sha256'), ($ArchivePath + '.manifest.json'))) {
        if (Test-Path -LiteralPath $target) { throw 'La entrega o uno de sus sidecars ya existe; no se sobrescribe.' }
    }
}

function Get-MusicMadBuildStage($Paths, [string]$Name) {
    if ($Name -notmatch '^[A-Za-z0-9_-]+$') { throw 'Nombre de staging inválido.' }
    $stage = Join-Path $Paths.StageRoot $Name
    $ancestor = $stage
    while ($ancestor) {
        if (Test-Path -LiteralPath $ancestor) {
            $item = Get-Item -LiteralPath $ancestor -Force
            if (($item.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0) { throw 'Staging con enlace no permitido.' }
        }
        $parent = Split-Path -Parent $ancestor
        if ($parent -eq $ancestor) { break }
        $ancestor = $parent
    }
    if ((Test-Path -LiteralPath $stage) -and @(Get-ChildItem -LiteralPath $stage -Force).Count -gt 0) {
        throw 'El staging ya contiene archivos; utiliza una versión de entrega nueva.'
    }
    [IO.Directory]::CreateDirectory($stage) | Out-Null
    return $stage
}

function Write-MusicMadManifestSidecar([string]$ArchivePath, $Manifest) {
    $target = $ArchivePath + '.manifest.json'
    $stream = [IO.File]::Open($target, [IO.FileMode]::CreateNew, [IO.FileAccess]::Write, [IO.FileShare]::None)
    $writer = [IO.StreamWriter]::new($stream, [Text.UTF8Encoding]::new($false))
    try { $writer.Write(($Manifest | ConvertTo-Json -Depth 8)) } finally { $writer.Dispose() }
}

function Copy-MusicMadDeliveryDocument($Paths, [string]$Name) {
    if ([IO.Path]::GetFileName($Name) -ne $Name -or $Name -notmatch '(?i)[.]md$') { throw 'Documento de entrega inválido.' }
    $source = Join-Path $Paths.ProjectRoot $Name
    $target = Get-MusicMadReleasePath $Paths $Name
    if (Test-Path -LiteralPath $source -PathType Leaf) {
        $item = Get-Item -LiteralPath $source -Force
        if (($item.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0) { throw 'Documento enlazado no permitido.' }
        if (Test-Path -LiteralPath $target) {
            if ((Get-FileHash -LiteralPath $source -Algorithm SHA256).Hash -ne (Get-FileHash -LiteralPath $target -Algorithm SHA256).Hash) {
                throw ('Ya existe otra versión del documento externo: ' + $Name)
            }
        } else { [IO.File]::Copy($source, $target, $false) }
    } elseif (-not (Test-Path -LiteralPath $target -PathType Leaf)) {
        throw ('Falta el documento de entrega externo: ' + $Name)
    }
}
