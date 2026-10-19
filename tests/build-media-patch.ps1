$ErrorActionPreference='Stop'
$taskPatchRoot=[IO.Path]::GetFullPath((Split-Path -Parent $PSScriptRoot)).TrimEnd('\')
. (Join-Path $PSScriptRoot 'release-paths.ps1')
$taskPaths=Initialize-MusicMadReleasePaths $taskPatchRoot
$taskPatchOutput=Get-MusicMadReleasePath $taskPaths 'MusicMad_parche_v88_respaldos_videos.zip'
$taskPatchPart=$taskPatchOutput+'.part'
if((Test-Path -LiteralPath $taskPatchOutput) -or (Test-Path -LiteralPath $taskPatchPart)){throw 'La entrega ya existe; no se sobrescribe.'}
Assert-MusicMadReleaseAvailable $taskPatchOutput
Add-Type -AssemblyName System.IO.Compression.FileSystem
$taskPatchBytes=[Collections.Generic.Dictionary[string,byte[]]]::new([StringComparer]::Ordinal)
# Explicitly include the preceding map/mail patch, keeping this update cumulative over v85.
$taskBaselineFiles=@('.htaccess','includes/security_headers.php','includes/header.php','includes/mailer.php','includes/.htaccess','config/.htaccess','config/correo.example.php','vendor/.htaccess','bin/.htaccess','js/scene-map.js','CSS/map.css','mapa.php','ubicacion.php','sw.js','assets/vendor/leaflet/leaflet.css','assets/vendor/leaflet/leaflet.js','assets/vendor/leaflet/LICENSE','assets/vendor/leaflet/images/layers.png','assets/vendor/leaflet/images/layers-2x.png','assets/vendor/leaflet/images/marker-icon.png','assets/vendor/leaflet/images/marker-icon-2x.png','assets/vendor/leaflet/images/marker-shadow.png')
foreach($taskName in @('PARCHE_V87_LEEME.md','PARCHE_V88_LEEME.md')){Copy-MusicMadDeliveryDocument $taskPaths $taskName}
$taskBaseline=[IO.Compression.ZipFile]::OpenRead((Get-MusicMadReleasePath $taskPaths 'MusicMad_parche_v87_mapa_correo.zip'))
try{
 foreach($taskName in $taskBaselineFiles){
  Assert-MusicMadPackagePath $taskName
  $taskEntry=$taskBaseline.GetEntry($taskName);if($taskEntry -eq $null){throw ('Falta un archivo base: '+$taskName)}
  $taskInput=$taskEntry.Open();$taskMemory=[IO.MemoryStream]::new();try{$taskInput.CopyTo($taskMemory);$taskPatchBytes[$taskName]=$taskMemory.ToArray()}finally{$taskInput.Dispose();$taskMemory.Dispose()}
 }
}finally{$taskBaseline.Dispose()}
$taskRuntimeFiles=@('includes/media_backup.php','includes/video_recovery.php','includes/social2.php','includes/upload_transport.php','includes/publicacion.php','includes/local_destination.php','includes/header.php','audio.php','video.php','partitura.php','restaurar-video.php','subir-archivo.php','config/database.php','config/media-backup.example.php','js/pending-actions.js','js/upload-progress.js','js/reaction-menu.js','CSS/social.css','CSS/mobile.css','bin/backup-media.php')
foreach($taskName in $taskRuntimeFiles){
 Assert-MusicMadPackagePath $taskName
 $taskPath=[IO.Path]::GetFullPath((Join-Path $taskPatchRoot $taskName));if(-not $taskPath.StartsWith($taskPatchRoot+'\',[StringComparison]::OrdinalIgnoreCase)){throw 'Fuera del proyecto.'}
 $taskFile=Get-Item -LiteralPath $taskPath;if(($taskFile.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0){throw 'No se empaquetan enlaces.'}
 $taskPatchBytes[$taskName]=[IO.File]::ReadAllBytes($taskPath)
}
$taskWorker=[Text.Encoding]::UTF8.GetString($taskPatchBytes['sw.js'])
if(-not $taskWorker.Contains("const VERSION = 'v87';")){throw 'Base inesperada del worker.'}
$taskWorker=$taskWorker.Replace("const VERSION = 'v87';","const VERSION = 'v88';").Replace("'js/reaction-burst.js'","'js/reaction-menu.js','js/reaction-burst.js'")
$taskPatchBytes['sw.js']=[Text.UTF8Encoding]::new($false).GetBytes($taskWorker)
# Only runtime files are staged, outside the public tree and never user media.
$taskStage=Get-MusicMadBuildStage $taskPaths 'v88'
foreach($taskName in $taskPatchBytes.Keys){$taskDestination=Join-Path $taskStage $taskName;$taskParent=Split-Path -Parent $taskDestination;if(-not (Test-Path -LiteralPath $taskParent)){[IO.Directory]::CreateDirectory($taskParent)|Out-Null};[IO.File]::WriteAllBytes($taskDestination,$taskPatchBytes[$taskName])}
function Get-PatchDigest([byte[]]$taskData){$taskHash=[Security.Cryptography.SHA256]::Create();try{return [BitConverter]::ToString($taskHash.ComputeHash($taskData)).Replace('-','').ToLowerInvariant()}finally{$taskHash.Dispose()}}
$taskPatchManifest=[Collections.Generic.List[object]]::new()
$taskStream=[IO.File]::Open($taskPatchPart,[IO.FileMode]::CreateNew,[IO.FileAccess]::ReadWrite,[IO.FileShare]::None)
$taskZip=[IO.Compression.ZipArchive]::new($taskStream,[IO.Compression.ZipArchiveMode]::Create,$false)
try{
 foreach($taskName in @($taskPatchBytes.Keys|Sort-Object)){
  $taskBytes=$taskPatchBytes[$taskName];$taskEntry=$taskZip.CreateEntry($taskName,[IO.Compression.CompressionLevel]::Optimal);$taskEntryStream=$taskEntry.Open();try{$taskEntryStream.Write($taskBytes,0,$taskBytes.Length)}finally{$taskEntryStream.Dispose()}
  $taskPatchManifest.Add([ordered]@{archivo=$taskName;bytes=$taskBytes.Length;sha256=(Get-PatchDigest $taskBytes)})
 }
 $taskManifest=[ordered]@{version='88';base='85';incluye_parches=@('87','88');tipo='Reacciones desplegables, respaldo privado y restauración de videos';sql_nuevo=$false;desplegado=$false;archivos=$taskPatchManifest}
}finally{$taskZip.Dispose()}
$taskAudit=[IO.Compression.ZipFile]::OpenRead($taskPatchPart)
try{
 if($taskAudit.Entries.Count -ne $taskPatchBytes.Count){throw 'Número de archivos inesperado.'}
 foreach($taskEntry in $taskAudit.Entries){
  $taskName=$taskEntry.FullName
  Assert-MusicMadPackagePath $taskName
  if(-not $taskPatchBytes.ContainsKey($taskName)){throw 'Archivo no autorizado.'}
 }
 foreach($taskRecord in $taskPatchManifest){$taskEntry=$taskAudit.GetEntry($taskRecord.archivo);$taskInput=$taskEntry.Open();$taskMemory=[IO.MemoryStream]::new();try{$taskInput.CopyTo($taskMemory);$taskDigest=Get-PatchDigest $taskMemory.ToArray()}finally{$taskInput.Dispose();$taskMemory.Dispose()};if($taskDigest -ne $taskRecord.sha256 -or $taskEntry.Length -ne $taskRecord.bytes){throw 'Integridad inválida.'}}
 if($taskAudit.GetEntry('restaurar-video.php') -eq $null -or $taskAudit.GetEntry('MusicMad_PWA/restaurar-video.php') -ne $null){throw 'El parche no es plano.'}
}finally{$taskAudit.Dispose()}
Move-Item -LiteralPath $taskPatchPart -Destination $taskPatchOutput
Write-MusicMadManifestSidecar $taskPatchOutput $taskManifest
$taskDigest=(Get-FileHash -LiteralPath $taskPatchOutput -Algorithm SHA256).Hash.ToLowerInvariant()
[IO.File]::WriteAllText($taskPatchOutput+'.sha256',$taskDigest+'  '+[IO.Path]::GetFileName($taskPatchOutput)+[Environment]::NewLine,[Text.UTF8Encoding]::new($false))
[pscustomobject]@{zip=$taskPatchOutput;archivos=$taskPatchBytes.Count;bytes=(Get-Item -LiteralPath $taskPatchOutput).Length;sha256=$taskDigest}|ConvertTo-Json
