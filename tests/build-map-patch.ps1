$ErrorActionPreference='Stop'
$taskPatchRoot=[IO.Path]::GetFullPath((Split-Path -Parent $PSScriptRoot)).TrimEnd('\')
. (Join-Path $PSScriptRoot 'release-paths.ps1')
$taskPaths=Initialize-MusicMadReleasePaths $taskPatchRoot
$taskPatchOutput=Get-MusicMadReleasePath $taskPaths 'MusicMad_parche_v87_mapa_correo.zip'
$taskPatchPart=$taskPatchOutput+'.part'
if((Test-Path -LiteralPath $taskPatchOutput) -or (Test-Path -LiteralPath $taskPatchPart)){throw 'La entrega ya existe; no se sobrescribe.'}
Assert-MusicMadReleaseAvailable $taskPatchOutput
$taskPatchFiles=@('.htaccess','includes/security_headers.php','includes/header.php','includes/mailer.php','includes/.htaccess','config/.htaccess','config/correo.example.php','vendor/.htaccess','bin/.htaccess','js/scene-map.js','CSS/map.css','mapa.php','ubicacion.php','sw.js')
Copy-MusicMadDeliveryDocument $taskPaths 'PARCHE_V87_LEEME.md'
foreach($taskItem in Get-ChildItem -LiteralPath (Join-Path $taskPatchRoot 'assets/vendor/leaflet') -File -Recurse){$taskPatchFiles+=$taskItem.FullName.Substring($taskPatchRoot.Length+1).Replace('\','/')}
Add-Type -AssemblyName System.IO.Compression.FileSystem
$taskPatchBytes=[Collections.Generic.Dictionary[string,byte[]]]::new([StringComparer]::Ordinal)
foreach($taskName in $taskPatchFiles){
 Assert-MusicMadPackagePath $taskName
 $taskPath=[IO.Path]::GetFullPath((Join-Path $taskPatchRoot $taskName));if(-not $taskPath.StartsWith($taskPatchRoot+'\',[StringComparison]::OrdinalIgnoreCase)){throw 'Fuera del proyecto.'}
 $taskFile=Get-Item -LiteralPath $taskPath;if(($taskFile.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0){throw 'No se empaquetan enlaces.'}
 $taskPatchBytes[$taskName]=[IO.File]::ReadAllBytes($taskPath)
}
# Local verification work is outside this patch. Ship the v85 worker with v87 only.
$taskBaseline=[IO.Compression.ZipFile]::OpenRead((Get-MusicMadReleasePath $taskPaths 'MusicMad_v85_actualizacion_2026-10-13.zip'))
try{
 $taskReader=[IO.StreamReader]::new($taskBaseline.GetEntry('sw.js').Open())
 try{$taskWorker=$taskReader.ReadToEnd()}finally{$taskReader.Dispose()}
 if(-not $taskWorker.Contains("const VERSION = 'v85';")){throw 'Base inesperada.'}
 $taskWorker=$taskWorker.Replace("const VERSION = 'v85';","const VERSION = 'v87';")
 $taskPatchBytes['sw.js']=[Text.UTF8Encoding]::new($false).GetBytes($taskWorker)
 $taskStage=Get-MusicMadBuildStage $taskPaths 'v87'
 [IO.File]::WriteAllBytes((Join-Path $taskStage 'sw.js'),$taskPatchBytes['sw.js'])
}finally{$taskBaseline.Dispose()}
function Get-PatchDigest([byte[]]$taskData){$taskHash=[Security.Cryptography.SHA256]::Create();try{return [BitConverter]::ToString($taskHash.ComputeHash($taskData)).Replace('-','').ToLowerInvariant()}finally{$taskHash.Dispose()}}
$taskPatchManifest=[Collections.Generic.List[object]]::new()
$taskStream=[IO.File]::Open($taskPatchPart,[IO.FileMode]::CreateNew,[IO.FileAccess]::ReadWrite,[IO.FileShare]::None)
$taskZip=[IO.Compression.ZipArchive]::new($taskStream,[IO.Compression.ZipArchiveMode]::Create,$false)
try{
 foreach($taskName in @($taskPatchBytes.Keys|Sort-Object)){
  $taskBytes=$taskPatchBytes[$taskName];$taskEntry=$taskZip.CreateEntry($taskName,[IO.Compression.CompressionLevel]::Optimal);$taskEntryStream=$taskEntry.Open();try{$taskEntryStream.Write($taskBytes,0,$taskBytes.Length)}finally{$taskEntryStream.Dispose()}
  $taskPatchManifest.Add([ordered]@{archivo=$taskName;bytes=$taskBytes.Length;sha256=(Get-PatchDigest $taskBytes)})
 }
 $taskManifest=[ordered]@{version='87';base='85';tipo='Parche de mapa y configuración de correo';sql_nuevo=$false;archivos=$taskPatchManifest}
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
 if($taskAudit.GetEntry('mapa.php') -eq $null -or $taskAudit.GetEntry('MusicMad_PWA/mapa.php') -ne $null){throw 'El parche no es plano.'}
}finally{$taskAudit.Dispose()}
Move-Item -LiteralPath $taskPatchPart -Destination $taskPatchOutput
Write-MusicMadManifestSidecar $taskPatchOutput $taskManifest
$taskDigest=(Get-FileHash -LiteralPath $taskPatchOutput -Algorithm SHA256).Hash.ToLowerInvariant()
[IO.File]::WriteAllText($taskPatchOutput+'.sha256',$taskDigest+'  '+[IO.Path]::GetFileName($taskPatchOutput)+[Environment]::NewLine,[Text.UTF8Encoding]::new($false))
[pscustomobject]@{zip=$taskPatchOutput;archivos=$taskPatchBytes.Count;bytes=(Get-Item -LiteralPath $taskPatchOutput).Length;sha256=$taskDigest}|ConvertTo-Json
