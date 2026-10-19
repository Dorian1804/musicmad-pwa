$ErrorActionPreference = 'Stop'
$taskRoot = [IO.Path]::GetFullPath((Split-Path -Parent $PSScriptRoot)).TrimEnd('\')
. (Join-Path $PSScriptRoot 'release-paths.ps1')
$taskPaths = Initialize-MusicMadReleasePaths $taskRoot
$taskOutput = Get-MusicMadReleasePath $taskPaths 'MusicMad_v104_lotes_H_I.zip'
Assert-MusicMadReleaseAvailable $taskOutput
$taskBase = Get-MusicMadReleasePath $taskPaths 'MusicMad_v103_lote_H_escenario.zip'
$taskBaseDigest = '8e68896257ece1810400bf6d10d29d4c565a0a4c916f9a8ee9afb1eb5a447d6f'
if ((Get-FileHash -LiteralPath $taskBase -Algorithm SHA256).Hash.ToLowerInvariant() -cne $taskBaseDigest) { throw 'La base G2 no coincide con la entrega auditada.' }
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
    '404.php',
    '500.php',
    'acciones-pendientes.php',
    'admin.php',
    'api/v1/index.php',
    'audio.php',
    'avisos-dispositivo.php',
    'banda-accion.php',
    'banda-chat-vista.php',
    'banda-chat.php',
    'banda-ics.php',
    'banda.php',
    'bandas.php',
    'bienvenida-accion.php',
    'bienvenida.php',
    'bin/audio-waveforms.php',
    'bin/backup-media.php',
    'bin/backup.php',
    'bin/correo-diagnostico.php',
    'bin/create-admin.php',
    'bin/generate-vapid.php',
    'bin/jobs.php',
    'bin/maintenance.php',
    'bin/migrate.php',
    'bin/push-diagnostico.php',
    'buscar.php',
    'busco.php',
    'busqueda-global.php',
    'cambiar-REPLACE_WITH_LOCAL_VALUE.php',
    'carrito.php',
    'cartel-evento.php',
    'catalogo.php',
    'chat-live.php',
    'chat-stream.php',
    'checkout.php',
    'clip-accion.php',
    'clip-medio.php',
    'clip.php',
    'clips.php',
    'colab-accion.php',
    'colab-subir.php',
    'colab.php',
    'colabs.php',
    'comentarios.php',
    'compartir.php',
    'compatibles.php',
    'config/correo.example.php',
    'config/database.php',
    'config/local.example.php',
    'config/mail.php',
    'config/media-backup.example.php',
    'configuracion.php',
    'confirmar-correo.php',
    'dataBase.php',
    'datos.php',
    'diagnostico.php',
    'dos-pasos.php',
    'eliminar-cuenta.php',
    'embed.php',
    'epk.php',
    'escucha.php',
    'estadisticas.php',
    'evento-accion.php',
    'evento-ics.php',
    'evento.php',
    'eventos.php',
    'exportar-datos.php',
    'feed.php',
    'feedback-accion.php',
    'form-modificar.php',
    'google-inicio.php',
    'google-regreso.php',
    'idioma.php',
    'includes/access_policy.php',
    'includes/account_controls.php',
    'includes/account_export.php',
    'includes/account_forms.php',
    'includes/account_removal.php',
    'includes/account_sessions.php',
    'includes/account_verification.php',
    'includes/action_receipts.php',
    'includes/artist_portfolio.php',
    'includes/artist_statistics.php',
    'includes/audio_moments.php',
    'includes/audio_waveform.php',
    'includes/auth.php',
    'includes/avatar_upload.php',
    'includes/band_activity.php',
    'includes/band_forms.php',
    'includes/band_workspace.php',
    'includes/calendar_export.php',
    'includes/challenges.php',
    'includes/chat_live.php',
    'includes/chat_preferences_form.php',
    'includes/clip_stories.php',
    'includes/collab_workspace.php',
    'includes/comments_engine.php',
    'includes/database_backup.php',
    'includes/email_account.php',
    'includes/email_disposable_domains.php',
    'includes/email_link_context.php',
    'includes/email_policy.php',
    'includes/email_required.php',
    'includes/endorsements.php',
    'includes/error_response.php',
    'includes/error_view.php',
    'includes/event_card.php',
    'includes/event_notices.php',
    'includes/event_workspace.php',
    'includes/feature_flags.php',
    'includes/feed_engine.php',
    'includes/footer.php',
    'includes/global_search.php',
    'includes/google_oauth_state.php',
    'includes/google_provider.php',
    'includes/header.php',
    'includes/i18n.php',
    'includes/jobs.php',
    'includes/linked_text.php',
    'includes/listening_rooms.php',
    'includes/local_destination.php',
    'includes/locales/de.php',
    'includes/locales/en.php',
    'includes/locales/es.php',
    'includes/locales/fr.php',
    'includes/locales/it.php',
    'includes/locales/ja.php',
    'includes/locales/ko.php',
    'includes/locales/pt-BR.php',
    'includes/locales/zh-CN.php',
    'includes/mailer.php',
    'includes/media_backup.php',
    'includes/media_duplicates.php',
    'includes/media_metadata.php',
    'includes/media_storage.php',
    'includes/migration_runner.php',
    'includes/musician_matching.php',
    'includes/navbar.php',
    'includes/navigation.php',
    'includes/onboarding.php',
    'includes/onboarding_page.php',
    'includes/onboarding_required.php',
    'includes/REPLACE_WITH_LOCAL_VALUE_policy.php',
    'includes/playlist_workspace.php',
    'includes/private_environment.php',
    'includes/public_artifacts.php',
    'includes/public_read_limits.php',
    'includes/public_share.php',
    'includes/publicacion.php',
    'includes/push_controls.php',
    'includes/rate_limit.php',
    'includes/repost_comments.php',
    'includes/scene_map.php',
    'includes/security_headers.php',
    'includes/social.php',
    'includes/social2.php',
    'includes/social3.php',
    'includes/totp.php',
    'includes/totp_account.php',
    'includes/track_feedback.php',
    'includes/upload_transport.php',
    'includes/video_recovery.php',
    'includes/web_push.php',
    'index.php',
    'listas.php',
    'login.php',
    'logout.php',
    'logros.php',
    'manifesto.php',
    'mapa-datos.php',
    'mapa.php',
    'media-image.php',
    'mensajes.php',
    'menu.php',
    'mercado-reportes.php',
    'mercado.php',
    'moderacion.php',
    'musico.php',
    'musicos.php',
    'notificaciones.php',
    'onda.php',
    'partitura.php',
    'pendientes.php',
    'perfil-estilos.php',
    'perfil.php',
    'permisos.php',
    'pista-publica.php',
    'playlist-equipo-accion.php',
    'playlist-equipo.php',
    'portafolio.php',
    'publicacion.php',
    'push-suscripcion.php',
    'reactivar-cuenta.php',
    'recomendacion-accion.php',
    'recomendaciones.php',
    'recuperar.php',
    'registro.php',
    'repost-accion.php',
    'restablecer.php',
    'restaurar-video.php',
    'reto-accion.php',
    'reto.php',
    'retos.php',
    'sala-accion.php',
    'sala-datos.php',
    'sala.php',
    'salas.php',
    'seguridad.php',
    'social-accion.php',
    'subir-archivo.php',
    'ubicacion.php',
    'verificaciones.php',
    'verificar-correo.php',
    'video.php',
    'vista-publica.php',
    'sw.js',
    'config/actualizar_musicmad_v104.sql'
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
if (-not $taskUtf8.GetString($taskFiles['sw.js']).Contains("const VERSION = 'v104';")) { throw 'Worker incorrecto.' }
if (-not $taskUtf8.GetString($taskFiles['includes/header.php']).Contains("`$assetVersion = '104';")) { throw 'Cabecera incorrecta.' }
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
$taskStage = Get-MusicMadBuildStage $taskPaths 'v104-lotes-H-I'
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
    version='104'; lote='H+I'; acumulativo=$true; base_sha256=$taskBaseDigest; zip_sha256=$taskDigest
    sql='config/actualizar_musicmad_v104.sql'; sql_nuevo=$false; desplegado=$false; archivos=$taskRecords
    archivos_tocados=$taskOverrides; secretos_y_medios_excluidos=$true
})
[IO.File]::WriteAllText($taskOutput + '.sha256', $taskDigest + '  ' + [IO.Path]::GetFileName($taskOutput) + "`n", $taskUtf8)
[pscustomobject]@{zip=$taskOutput; staging=$taskStage; archivos=$taskRecords.Count; sha256=$taskDigest} | ConvertTo-Json
