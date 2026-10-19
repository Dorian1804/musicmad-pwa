$ErrorActionPreference='Stop'
$taskPatchRoot=[IO.Path]::GetFullPath((Split-Path -Parent $PSScriptRoot)).TrimEnd('\')
. (Join-Path $PSScriptRoot 'release-paths.ps1')
$taskPaths=Initialize-MusicMadReleasePaths $taskPatchRoot
$taskPatchOutput=Get-MusicMadReleasePath $taskPaths 'MusicMad_parche_v90_audio.zip'
$taskPatchPart=$taskPatchOutput+'.part'
if((Test-Path -LiteralPath $taskPatchOutput) -or (Test-Path -LiteralPath $taskPatchPart)){throw 'La entrega ya existe; no se sobrescribe.'}
Assert-MusicMadReleaseAvailable $taskPatchOutput
Add-Type -AssemblyName System.IO.Compression.FileSystem
$taskPatchBytes=[Collections.Generic.Dictionary[string,byte[]]]::new([StringComparer]::Ordinal)
$taskBaselinePath=Get-MusicMadReleasePath $taskPaths 'MusicMad_parche_v89_google_movil_musica.zip'
if((Get-FileHash -LiteralPath $taskBaselinePath -Algorithm SHA256).Hash.ToLowerInvariant() -ne '2e37b02e4f205d81cc3c5af9874db585878c93e47e92f5cc20a5e2cfd72a65ae'){throw 'La base v89 no coincide con la entrega auditada.'}
$taskBaseline=[IO.Compression.ZipFile]::OpenRead($taskBaselinePath)
try{
 foreach($taskEntry in $taskBaseline.Entries){
  $taskName=$taskEntry.FullName;if($taskName -match '(?i)^MANIFIESTO[^/]*[.]json$|^[^/]+[.]md$'){continue}
  Assert-MusicMadPackagePath $taskName
  $taskInput=$taskEntry.Open();$taskMemory=[IO.MemoryStream]::new();try{$taskInput.CopyTo($taskMemory);$taskPatchBytes[$taskName]=$taskMemory.ToArray()}finally{$taskInput.Dispose();$taskMemory.Dispose()}
 }
}finally{$taskBaseline.Dispose()}
foreach($taskName in @('PARCHE_V87_LEEME.md','PARCHE_V88_LEEME.md','PARCHE_V89_LEEME.md','PARCHE_V90_LEEME.md')){Copy-MusicMadDeliveryDocument $taskPaths $taskName}
$taskRuntimeFiles=@('audio.php','includes/header.php','js/app.js','js/player.js','js/playback-errors.js')
foreach($taskName in $taskRuntimeFiles){
 Assert-MusicMadPackagePath $taskName
 $taskPath=[IO.Path]::GetFullPath((Join-Path $taskPatchRoot $taskName));if(-not $taskPath.StartsWith($taskPatchRoot+'\',[StringComparison]::OrdinalIgnoreCase)){throw 'Fuera del proyecto.'}
 $taskFile=Get-Item -LiteralPath $taskPath;if(($taskFile.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0){throw 'No se empaquetan enlaces.'}
 $taskPatchBytes[$taskName]=[IO.File]::ReadAllBytes($taskPath)
}
$taskWorker=[Text.Encoding]::UTF8.GetString($taskPatchBytes['sw.js'])
if(-not $taskWorker.Contains("const VERSION = 'v89';")){throw 'Base inesperada del worker.'}
$taskPatchBytes['sw.js']=[Text.UTF8Encoding]::new($false).GetBytes($taskWorker.Replace("const VERSION = 'v89';","const VERSION = 'v90';").Replace("'js/player.js'","'js/playback-errors.js','js/player.js'"))
$taskStage=Get-MusicMadBuildStage $taskPaths 'v90'
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
 $taskManifest=[ordered]@{version='90';base='85';incluye_parches=@('87','88','89','90');tipo='Diagnóstico de audio y aviso único';sql_nuevo=$false;desplegado=$false;archivos=$taskPatchManifest}
}finally{$taskZip.Dispose()}
$taskAudit=[IO.Compression.ZipFile]::OpenRead($taskPatchPart)
try{
 if($taskAudit.Entries.Count -ne $taskPatchBytes.Count){throw 'Número de archivos inesperado.'}
 foreach($taskEntry in $taskAudit.Entries){$taskName=$taskEntry.FullName;Assert-MusicMadPackagePath $taskName;if(-not $taskPatchBytes.ContainsKey($taskName)){throw 'Archivo no autorizado.'}}
 foreach($taskRecord in $taskPatchManifest){$taskEntry=$taskAudit.GetEntry($taskRecord.archivo);$taskInput=$taskEntry.Open();$taskMemory=[IO.MemoryStream]::new();try{$taskInput.CopyTo($taskMemory);$taskDigest=Get-PatchDigest $taskMemory.ToArray()}finally{$taskInput.Dispose();$taskMemory.Dispose()};if($taskDigest -ne $taskRecord.sha256 -or $taskEntry.Length -ne $taskRecord.bytes){throw 'Integridad inválida.'}}
 if($taskAudit.GetEntry('google-regreso.php') -eq $null -or $taskAudit.GetEntry('MusicMad_PWA/google-regreso.php') -ne $null){throw 'El parche no es plano.'}
}finally{$taskAudit.Dispose()}
Move-Item -LiteralPath $taskPatchPart -Destination $taskPatchOutput
Write-MusicMadManifestSidecar $taskPatchOutput $taskManifest
$taskDigest=(Get-FileHash -LiteralPath $taskPatchOutput -Algorithm SHA256).Hash.ToLowerInvariant()
[IO.File]::WriteAllText($taskPatchOutput+'.sha256',$taskDigest+'  '+[IO.Path]::GetFileName($taskPatchOutput)+[Environment]::NewLine,[Text.UTF8Encoding]::new($false))
[pscustomobject]@{zip=$taskPatchOutput;archivos=$taskPatchBytes.Count;bytes=(Get-Item -LiteralPath $taskPatchOutput).Length;sha256=$taskDigest}|ConvertTo-Json
