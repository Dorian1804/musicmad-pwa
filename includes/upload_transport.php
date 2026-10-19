<?php

// Private staging and capabilities are server-only, unrelated to user filenames.
const MUSICMAD_CHUNK_BYTES = 512 * 1024;
function directorioSubidasPendientes(): string
{
    return musicmadConfiguredMediaRootIsPrivate() ? dirname(musicmadMediaRoot()) . '/staging' : musicmadMediaRoot() . '/staging';
}
function capacidadSubidaInterna(): string
{
    static $key = null;
    return $key ??= bin2hex(random_bytes(32));
}
function archivoCargaReal(array $file): bool
{
    if (!is_string($file['tmp_name'] ?? null)) {
        return false;
    }
    if (is_uploaded_file($file['tmp_name'])) {
        return true;
    }
    if (!is_string($file['_capacidad'] ?? null) || !hash_equals(capacidadSubidaInterna(), $file['_capacidad'])) {
        return false;
    }
    $base = realpath(directorioSubidasPendientes());
    $path = realpath($file['tmp_name']);
    return $base !== false && $path !== false && dirname($path) === $base && !is_link($file['tmp_name']) && is_file($path);
}
function moverArchivoCarga(array $file, string $destination): bool
{
    if (!archivoCargaReal($file)) {
        return false;
    }
    if (is_uploaded_file($file['tmp_name'])) {
        return move_uploaded_file($file['tmp_name'], $destination);
    }
    // Keep the verified staging original until the database commit succeeds.
    return copy($file['tmp_name'], $destination);
}
function limiteSubida(string $kind): int
{
    return match ($kind) {
        'audio' => AUDIO_MAX_BYTES, 'video' => VIDEO_MAX_BYTES, 'partitura' => PARTITURA_MAX_BYTES, default => 0
    };
}
function validarInicioSubida(string $kind, string $name, int $bytes): void
{
    $extensions = match ($kind) {
        'audio' => array_keys(AUDIO_TIPOS), 'video' => ['mp4','webm'], 'partitura' => ['pdf'], default => []
    };
    if ($bytes < 1 || $bytes > limiteSubida($kind) || mb_strlen($name) > 180 || preg_match('/[\x00-\x1f\x7f]/', $name)
        || !in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), $extensions, true)) {
        throw new InvalidArgumentException('Revisa el formato y tamaño del archivo. Audio hasta 64 MB, video hasta 256 MB y PDF hasta 20 MB.');
    }
}
function rutaSubidaPendiente(string $id): string
{
    if (!preg_match('/^[a-f0-9]{32}$/D', $id)) {
        throw new InvalidArgumentException('Identificador de subida inválido.');
    }
    return directorioSubidasPendientes() . '/' . $id . '.part';
}
function prepararDirectorioSubidas(): void
{
    $directory = directorioSubidasPendientes();
    if (is_link($directory)) {
        throw new RuntimeException('El directorio de subida no es seguro.');
    }
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('No se pudo preparar el espacio de subida.');
    }
    if (!is_writable($directory)) {
        throw new RuntimeException('El hosting no permite guardar archivos.');
    }
    $protection = $directory . '/.htaccess';
    if (!is_file($protection) && file_put_contents($protection, "Require all denied\n") === false) {
        throw new RuntimeException('No se pudo proteger la carpeta de subida.');
    }
}
function obtenerSubidaPropia(PDO $db, string $id, int $uid, bool $lock = false): array
{
    rutaSubidaPendiente($id);
    $st = $db->prepare('SELECT *,expira_en>UTC_TIMESTAMP() AS vigente FROM musicmad_uploads WHERE id=:id AND usuario_id=:u' . ($lock ? ' FOR UPDATE' : ''));
    $st->execute([':id' => $id, ':u' => $uid]);
    $row = $st->fetch();
    if (!$row) {
        throw new OutOfBoundsException('No encontramos esta subida en tu cuenta.');
    }
    if ($row['estado'] === 'cancelado' || (!$row['vigente'] && $row['estado'] !== 'completo')) {
        throw new DomainException('La subida venció o fue cancelada. Vuelve a elegir el archivo.');
    }
    return $row;
}
function resumenSubida(array $row): array
{
    return ['id' => $row['id'],'offset' => (int)$row['recibidos'],'bytes' => (int)$row['bytes'],'estado' => $row['estado'], 'publicacion_id' => $row['publicacion_id'] === null ? null : (int)$row['publicacion_id'], 'chunk_bytes' => MUSICMAD_CHUNK_BYTES];
}
function crearSubidaPendiente(PDO $db, int $uid, string $kind, string $name, int $bytes, ?string $requestId = null): array
{
    validarInicioSubida($kind, $name, $bytes);
    if ($requestId !== null) {
        rutaSubidaPendiente($requestId);
    }
    prepararDirectorioSubidas();
    $db->beginTransaction();
    try {
        // Serialize quota checks per owner, not across all uploads.
        $lock = $db->prepare('SELECT id FROM usuarios WHERE id=:u AND activo=1 FOR UPDATE');
        $lock->execute([':u' => $uid]);
        if (!$lock->fetchColumn()) {
            throw new DomainException('La cuenta no está activa.');
        }
        if ($requestId !== null) {
            $st = $db->prepare('SELECT id FROM musicmad_uploads WHERE id=?');
            $st->execute([$requestId]);
            if ($st->fetchColumn()) {
                $old = obtenerSubidaPropia($db, $requestId, $uid, true);
                if ($old['tipo'] !== $kind || $old['nombre'] !== $name || (int)$old['bytes'] !== $bytes) {
                    throw new DomainException('Ese envío ya corresponde a otro archivo.');
                }
                $db->commit();
                return $old;
            }
        }
        $recent = $db->prepare('SELECT COUNT(*) FROM musicmad_uploads WHERE usuario_id=:u AND created_at>DATE_SUB(NOW(),INTERVAL 1 HOUR)');
        $recent->execute([':u' => $uid]);
        if ((int)$recent->fetchColumn() >= 40) {
            throw new DomainException('Has iniciado muchas subidas. Espera un poco antes de crear otra.');
        }
        $quota = $db->prepare("SELECT COUNT(*) AS n,COALESCE(SUM(bytes),0) AS bytes FROM musicmad_uploads WHERE usuario_id=:u AND estado='subiendo' AND expira_en>UTC_TIMESTAMP()");
        $quota->execute([':u' => $uid]);
        $pending = $quota->fetch();
        if ((int)$pending['n'] >= 4 || (int)$pending['bytes'] + $bytes > 512 * 1048576) {
            throw new DomainException('Tienes varias subidas pendientes. Reanuda o cancela alguna antes de iniciar otra.');
        }
        $id = $requestId ?? bin2hex(random_bytes(16));
        $db->prepare("INSERT INTO musicmad_uploads(id,usuario_id,tipo,nombre,bytes,expira_en) VALUES(:id,:u,:t,:n,:b,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 48 HOUR))")
            ->execute([':id' => $id,':u' => $uid,':t' => $kind,':n' => $name,':b' => $bytes]);
        $db->commit();
        return obtenerSubidaPropia($db, $id, $uid);
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $error;
    }
}
function recibirBloqueSubida(PDO $db, int $uid, string $id, int $offset, string $data, string $hash): array
{
    if ($offset < 0 || strlen($data) < 1 || strlen($data) > MUSICMAD_CHUNK_BYTES || !preg_match('/^[a-f0-9]{64}$/D', $hash) || !hash_equals(hash('sha256', $data), $hash)) {
        throw new InvalidArgumentException('El bloque llegó incompleto. Reintenta la subida.');
    }
    $db->beginTransaction();
    $handle = null;
    $row = null;
    try {
        $row = obtenerSubidaPropia($db, $id, $uid, true);
        if ($row['estado'] === 'completo' || $offset < (int)$row['recibidos']) {
            $db->commit();
            return $row;
        }
        if ($offset !== (int)$row['recibidos'] || $offset + strlen($data) > (int)$row['bytes']) {
            throw new LengthException('La posición de subida cambió. Consulta el avance para reanudar.');
        }
        prepararDirectorioSubidas();
        $path = rutaSubidaPendiente($id);
        if (is_link($path)) {
            throw new RuntimeException('El archivo temporal no es seguro.');
        }
        $handle = fopen($path, 'c+b');
        if (!$handle || !flock($handle, LOCK_EX)) {
            throw new RuntimeException('No se pudo escribir el bloque.');
        }
        $disk = (int)fstat($handle)['size'];
        if ($disk < $offset) {
            throw new RuntimeException('El temporal está incompleto. Cancela esta subida y empieza otra.');
        }
        if (!ftruncate($handle, $offset) || fseek($handle, $offset) !== 0) {
            throw new RuntimeException('No se pudo preparar el bloque.');
        }
        $written = 0;
        while ($written < strlen($data)) {
            $n = fwrite($handle, substr($data, $written));
            if ($n === false || $n === 0) {
                throw new RuntimeException('No hay espacio para guardar el bloque.');
            }
            $written += $n;
        }
        if (!fflush($handle)) {
            throw new RuntimeException('No se pudo guardar el bloque.');
        }
        $db->prepare('UPDATE musicmad_uploads SET recibidos=:r,expira_en=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 48 HOUR) WHERE id=:id AND usuario_id=:u')
            ->execute([':r' => $offset + $written,':id' => $id,':u' => $uid]);
        $db->commit();
        $row['recibidos'] = $offset + $written;
        return $row;
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        if (is_resource($handle) && $row) {
            ftruncate($handle, (int)$row['recibidos']);
            fflush($handle);
        }
        throw $error;
    } finally {
        if (is_resource($handle)) {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
function finalizarSubida(PDO $db, int $uid, string $id, array $metadata): array
{
    $db->beginTransaction();
    $stored = null;
    $kind = null;
    try {
        $actor = $db->prepare('SELECT id FROM usuarios WHERE id=? AND activo=1 FOR UPDATE');
        $actor->execute([$uid]);
        if (!$actor->fetchColumn()) {
            throw new DomainException('La cuenta no está activa.');
        }
        $row = obtenerSubidaPropia($db, $id, $uid, true);
        if ($row['estado'] === 'completo') {
            if (!$row['publicacion_id']) {
                throw new DomainException('Esta publicación ya fue eliminada.');
            }
            $db->commit();
            return $row;
        }
        $path = rutaSubidaPendiente($id);
        clearstatcache(true, $path);
        if ((int)$row['recibidos'] !== (int)$row['bytes'] || !is_file($path) || is_link($path) || filesize($path) !== (int)$row['bytes']) {
            throw new LengthException('El archivo todavía no termina de subir.');
        }
        $kind = $row['tipo'];
        $restore = idCampo($metadata, 'restaurar_publicacion');
        if ($restore > 0) {
            if ($kind !== 'video' || idCampo($metadata, 'colaboracion_id') > 0 || idCampo($metadata, 'banda_id') > 0) {
                throw new InvalidArgumentException('La restauración recibe solo el video original.');
            }
            require_once __DIR__ . '/video_recovery.php';
            $file = ['name' => $row['nombre'],'tmp_name' => $path,'size' => (int)$row['bytes'],'error' => UPLOAD_ERR_OK,'_capacidad' => capacidadSubidaInterna()];
            $stored = restaurarArchivoVideoPropio($uid, $restore, $file);
            $db->prepare("UPDATE musicmad_uploads SET estado='completo',publicacion_id=? WHERE id=? AND usuario_id=?")->execute([$restore,$id,$uid]);
            $db->commit();
            @unlink($path);
            $row['estado'] = 'completo';
            $row['publicacion_id'] = $restore;
            return $row;
        }
        $collab = idCampo($metadata, 'colaboracion_id');
        if ($collab > 0 && ($kind !== 'audio' || idCampo($metadata, 'banda_id') > 0)) {
            throw new InvalidArgumentException('El estudio recibe un aporte de audio por envío.');
        }
        if ($collab > 0) {
            $metadata['tipo'] = 'demo';
        }
        if ($kind === 'video') {
            $metadata['tipo'] = 'video';
        }
        if ($kind === 'partitura') {
            $metadata['tipo'] = 'partitura';
        }
        $created = crearPublicacion($uid, $metadata);
        if (!$created['ok']) {
            throw new InvalidArgumentException($created['mensaje']);
        }
        $file = ['name' => $row['nombre'],'tmp_name' => $path,'size' => (int)$row['bytes'],'error' => UPLOAD_ERR_OK,'_capacidad' => capacidadSubidaInterna()];
        $stored = match($kind) {
            'audio' => guardarAudio((int)$created['id'], $file), 'video' => guardarVideo((int)$created['id'], $file), 'partitura' => guardarPartitura((int)$created['id'], $file)
        };
        if (!$stored['ok']) {
            throw new InvalidArgumentException($stored['mensaje']);
        }
        if ($collab > 0) {
            aportarStemColab($uid, $collab, array_replace($metadata, ['pista' => (int)$created['id']]), $id);
        }
        if (estadisticasArtistaDisponibles()) {
            logrosArtista($uid, true);
        }
        if (social3Disponible()) {
            guardarLicenciaPublicacion($uid, (int)$created['id'], textoCampo($metadata, 'licencia') ?: 'todos_derechos');
        }
        $db->prepare("UPDATE musicmad_uploads SET estado='completo',publicacion_id=:p WHERE id=:id AND usuario_id=:u")
            ->execute([':p' => (int)$created['id'],':id' => $id,':u' => $uid]);
        $db->commit();
        @unlink($path);
        $row['estado'] = 'completo';
        $row['publicacion_id'] = (int)$created['id'];
        return $row;
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        if ($stored && $stored['ok'] && isset($stored['archivo']) && $kind) {
            $directory = match($kind) {
                'audio' => AUDIO_DIR,'video' => VIDEO_DIR,'partitura' => PARTITURA_DIR
            };
            @unlink($directory . '/' . $stored['archivo']);
        }
        throw $error;
    }
}
function cancelarSubida(PDO $db, int $uid, string $id): array
{
    $db->beginTransaction();
    try {
        $row = obtenerSubidaPropia($db, $id, $uid, true);
        if ($row['estado'] !== 'completo') {
            $db->prepare("UPDATE musicmad_uploads SET estado='cancelado' WHERE id=:id AND usuario_id=:u")->execute([':id' => $id,':u' => $uid]);
            $path = rutaSubidaPendiente($id);
            if (!is_link($path)) {
                @unlink($path);
            }
            $row['estado'] = 'cancelado';
        }
        $db->commit();
        return $row;
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $error;
    }
}
