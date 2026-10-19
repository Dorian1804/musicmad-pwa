$ErrorActionPreference='Stop'
$taskRoot=[IO.Path]::GetFullPath((Split-Path -Parent $PSScriptRoot)).TrimEnd('\')
. (Join-Path $PSScriptRoot 'release-paths.ps1')
$taskPaths=Initialize-MusicMadReleasePaths $taskRoot
$taskOutput=Get-MusicMadReleasePath $taskPaths 'MusicMad_v85_actualizacion_2026-10-13.zip'
$taskPartial=$taskOutput+'.part'
if((Test-Path -LiteralPath $taskOutput) -or (Test-Path -LiteralPath $taskPartial)){throw 'La entrega ya existe: no se sobrescribe.'}
Assert-MusicMadReleaseAvailable $taskOutput
$taskSelected=[Collections.Generic.Dictionary[string,string]]::new([StringComparer]::Ordinal)
function Add-ReleaseFile([IO.FileInfo]$item){
 $absolute=[IO.Path]::GetFullPath($item.FullName)
 if(-not $absolute.StartsWith($taskRoot+'\',[StringComparison]::OrdinalIgnoreCase)){throw 'Archivo fuera del proyecto.'}
 if(($item.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0){throw 'No se empaquetan enlaces.'}
 $relative=$absolute.Substring($taskRoot.Length+1).Replace('\','/')
 if(-not (Test-MusicMadPackagePath $relative) -or $relative -match '(?i)(^|/)examples?(/|$)'){return}
 $taskSelected[$relative]=$absolute
}
$taskRootAllowed=@('.htaccess','.editorconfig','.gitattributes','manifest.json','manifest.webmanifest','sw.js','composer.json','composer.lock')
foreach($item in Get-ChildItem -LiteralPath $taskRoot -File -Force){if($item.Extension -in @('.php','.html') -or $item.Name -in $taskRootAllowed){if($item.Name -notmatch '^(qa[-_.]|test[-_.]|seed[-_.])'){Add-ReleaseFile $item}}}
foreach($folder in @('CSS','js','includes','api','assets','vendor')){
 foreach($item in Get-ChildItem -LiteralPath (Join-Path $taskRoot $folder) -File -Force -Recurse){
  if($item.Extension.ToLowerInvariant() -in @('.php','.js','.mjs','.cjs','.json','.css','.svg','.png','.woff','.woff2','.ttf','.html','.txt','.md') -or $item.Name -match '(?i)^(LICENSE.*|COPYING.*|NOTICE.*|\.htaccess)$'){Add-ReleaseFile $item}
 }
}
foreach($name in @('database.php','mail.php','local.example.php','actualizar_musicmad_v85.sql')){Add-ReleaseFile (Get-Item -LiteralPath (Join-Path $taskRoot ('config/'+$name)))}
foreach($item in Get-ChildItem -LiteralPath (Join-Path $taskRoot 'config/migrations') -File -Filter '*.sql'){if($item.Name -match '^[0-9]{3}_'){Add-ReleaseFile $item}}
foreach($name in @('migrate.php','backup.php','jobs.php','maintenance.php','audio-waveforms.php','generate-vapid.php','create-admin.php')){Add-ReleaseFile (Get-Item -LiteralPath (Join-Path $taskRoot ('bin/'+$name)))}
foreach($name in @('icon-192.png','icon-512.png','icon-512-maskable.png')){Add-ReleaseFile (Get-Item -LiteralPath (Join-Path $taskRoot ('img/'+$name)))}
foreach($folder in @('img/avatares/musicos','img/portadas')){foreach($item in Get-ChildItem -LiteralPath (Join-Path $taskRoot $folder) -File -Filter '*.svg'){Add-ReleaseFile $item}}
Add-ReleaseFile (Get-Item -LiteralPath (Join-Path $taskRoot 'storage/.htaccess'))
foreach($name in @('index.php','feed.php','includes/header.php','vendor/autoload.php','vendor/composer/platform_check.php','config/actualizar_musicmad_v85.sql','storage/.htaccess')){if(-not $taskSelected.ContainsKey($name)){throw ('Falta archivo requerido: '+$name)}}
foreach($name in @('LEEME_ACTUALIZACION_V85.md','ACTUALIZAR_HOSTING.md','FUNCIONES_100.md')){Copy-MusicMadDeliveryDocument $taskPaths $name}
Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem
$taskManifest=[Collections.Generic.List[object]]::new()
$taskStream=[IO.File]::Open($taskPartial,[IO.FileMode]::CreateNew,[IO.FileAccess]::ReadWrite,[IO.FileShare]::None)
$taskZip=[IO.Compression.ZipArchive]::new($taskStream,[IO.Compression.ZipArchiveMode]::Create,$false)
try{
 foreach($name in @($taskSelected.Keys | Sort-Object)){
  $file=Get-Item -LiteralPath $taskSelected[$name];$size=$file.Length;$modified=$file.LastWriteTimeUtc.Ticks
  $bytes=[IO.File]::ReadAllBytes($file.FullName)
  $after=Get-Item -LiteralPath $file.FullName;if($after.Length -ne $size -or $after.LastWriteTimeUtc.Ticks -ne $modified){throw ('Archivo cambió durante empaquetado: '+$name)}
  $entry=$taskZip.CreateEntry($name,[IO.Compression.CompressionLevel]::Optimal);$entryStream=$entry.Open();try{$entryStream.Write($bytes,0,$bytes.Length)}finally{$entryStream.Dispose()}
  $hash=[Security.Cryptography.SHA256]::Create();try{$digest=[BitConverter]::ToString($hash.ComputeHash($bytes)).Replace('-','').ToLowerInvariant()}finally{$hash.Dispose()}
  $taskManifest.Add([ordered]@{archivo=$name;bytes=$bytes.Length;sha256=$digest})
 }
 $manifest=[ordered]@{version='85';fecha_utc=[DateTime]::UtcNow.ToString('o');tipo='Corte acumulativo solicitado; las100 funciones no están completas';sql='config/actualizar_musicmad_v85.sql';archivos=$taskManifest}
}finally{$taskZip.Dispose()}
$taskAudit=[IO.Compression.ZipFile]::OpenRead($taskPartial)
try{
 if($taskAudit.Entries.Count -ne $taskManifest.Count){throw 'Número de archivos inesperado.'}
 foreach($entry in $taskAudit.Entries){
  $name=$entry.FullName
  Assert-MusicMadPackagePath $name
 }
 foreach($record in $taskManifest){$entry=$taskAudit.GetEntry($record.archivo);$hash=[Security.Cryptography.SHA256]::Create();$input=$entry.Open();try{$digest=[BitConverter]::ToString($hash.ComputeHash($input)).Replace('-','').ToLowerInvariant()}finally{$input.Dispose();$hash.Dispose()};if($digest -ne $record.sha256 -or $entry.Length -ne $record.bytes){throw ('Integridad inválida: '+$record.archivo)}}
 if($taskAudit.GetEntry('index.php') -eq $null -or $taskAudit.GetEntry('MusicMad_PWA/index.php') -ne $null){throw 'El ZIP no es plano.'}
}finally{$taskAudit.Dispose()}
Move-Item -LiteralPath $taskPartial -Destination $taskOutput
Write-MusicMadManifestSidecar $taskOutput $manifest
$taskDigest=(Get-FileHash -LiteralPath $taskOutput -Algorithm SHA256).Hash.ToLowerInvariant()
[IO.File]::WriteAllText($taskOutput+'.sha256',$taskDigest+'  '+[IO.Path]::GetFileName($taskOutput)+[Environment]::NewLine,[Text.UTF8Encoding]::new($false))
[pscustomobject]@{zip=$taskOutput;archivos=$taskManifest.Count;bytes=(Get-Item -LiteralPath $taskOutput).Length;sha256=$taskDigest}
