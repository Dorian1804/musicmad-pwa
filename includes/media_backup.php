<?php

final class RespaldoMedioException extends RuntimeException
{
}
function nombreMedioRespaldoValido(string $kind, string $name): bool
{
    if (in_array($kind, ['avatars', 'covers'], true)) {
        return preg_match('/^[1-9][0-9]*_[a-f0-9]{24}\.jpg$/D', $name) === 1;
    }
    if ($kind === 'market') {
        return preg_match('/^[a-f0-9]{24}\.jpg$/D', $name) === 1;
    }
    $ext = match($kind) {
        'audio' => 'mp3|m4a|ogg|wav','videos' => 'mp4|webm','partituras' => 'pdf',default => ''
    };
    return $ext !== '' && preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,70}\.(' . $ext . ')$/Di', $name) === 1;
}
function rutaDentroRespaldo(string $path, string $root): bool
{
    $normalize = static fn ($value) => rtrim(str_replace('\\', '/', $value), '/') . '/';
    if (PHP_OS_FAMILY === 'Windows') {
        return str_starts_with(strtolower($normalize($path)), strtolower($normalize($root)));
    }
    return str_starts_with($normalize($path), $normalize($root));
}
/** Only existing private destinations outside the app and its public parent. */
function directorioRespaldoMedios(): string
{
    if (defined('MUSICMAD_TEST_RUN') && MUSICMAD_TEST_RUN === true) {
        require_once dirname(__DIR__) . '/tests/paths.php';
        $path = musicmadQaRoot() . '/media-backups';
        if (is_link($path)) {
            throw new RespaldoMedioException('El respaldo no está disponible.');
        }
        if (!is_dir($path) && !mkdir($path, 0700, true)) {
            throw new RespaldoMedioException('El respaldo no está disponible.');
        }
        return $path;
    }
    $configured = getenv('MM_MEDIA_BACKUP_DIR');
    if ($configured === false || $configured === '') {
        $configured = defined('MUSICMAD_MEDIA_BACKUP_DIR') ? (string)MUSICMAD_MEDIA_BACKUP_DIR : '';
    }
    $candidates = $configured !== '' ? [$configured] : [];
    if (!$candidates) {
        $ancestor = dirname(APP_ROOT);
        for ($i = 0;$i < 6;$i++) {
            $candidates[] = $ancestor . '/backups/musicmad-media';
            $parent = dirname($ancestor);
            if ($parent === $ancestor) {
                break;
            }
            $ancestor = $parent;
        }
    }
    $public = realpath(dirname(APP_ROOT));
    $document = realpath((string)($_SERVER['DOCUMENT_ROOT'] ?? ''));
    foreach ($candidates as $candidate) {
        $path = realpath($candidate);
        if ($path === false || !is_dir($path) || is_link($candidate) || !is_writable($path) || ($public !== false && rutaDentroRespaldo($path, $public)) || ($document !== false && rutaDentroRespaldo($path, $document))) {
            continue;
        }
        if (!is_file($path . '/.htaccess') && file_put_contents($path . '/.htaccess', "Require all denied\n", LOCK_EX) === false) {
            continue;
        }
        return $path;
    }
    throw new RespaldoMedioException('Falta el respaldo privado de medios. Conservamos tu archivo para reintentar; el equipo debe revisar la carpeta de respaldos.');
}
function carpetaRespaldoMedios(string $kind): string
{
    if (!in_array($kind, ['audio','videos','partituras','avatars','covers','market'], true)) {
        throw new InvalidArgumentException('Medio no válido.');
    }
    $root = directorioRespaldoMedios();
    $dir = $root . '/' . $kind;
    if (is_link($dir) || (!is_dir($dir) && !mkdir($dir, 0700)) || !is_writable($dir)) {
        throw new RespaldoMedioException('No se pudo preparar el respaldo privado.');
    }
    $real = realpath($dir);
    if ($real === false || dirname($real) !== realpath($root)) {
        throw new RespaldoMedioException('El respaldo privado no es seguro.');
    }
    return $real;
}
/** Immutable copy of a validated upload; never read credentials or copy app code. */
function respaldarMedioGuardado(string $kind, string $name): void
{
    if (!nombreMedioRespaldoValido($kind, $name)) {
        throw new InvalidArgumentException('Nombre de respaldo no válido.');
    }
    $source = musicmadMediaDirectoryForFile($kind, $name) . '/' . $name;
    if (is_link($source) || is_link(dirname($source)) || !is_file($source)) {
        throw new RespaldoMedioException('No se encontró el archivo para respaldarlo.');
    }
    $directory = carpetaRespaldoMedios($kind);
    $destination = $directory . '/' . $name;
    $hash = hash_file('sha256', $source);
    $size = filesize($source);
    if (!is_string($hash) || $size === false || $size < 1) {
        throw new RespaldoMedioException('No se pudo comprobar el archivo.');
    }
    if (is_link($destination)) {
        throw new RespaldoMedioException('El respaldo privado no es seguro.');
    }
    if (is_file($destination)) {
        if (filesize($destination) !== $size || !hash_equals($hash, hash_file('sha256', $destination))) {
            throw new RespaldoMedioException('Ya existe otra copia con ese nombre. No se sobrescribió.');
        }
        return;
    }
    $part = $directory . '/.copy-' . bin2hex(random_bytes(12));
    $in = fopen($source, 'rb');
    $out = fopen($part, 'xb');
    if (!$in || !$out) {
        if (is_resource($in)) {
            fclose($in);
        }
        if (is_resource($out)) {
            fclose($out);
        }
        throw new RespaldoMedioException('No se pudo escribir el respaldo.');
    }
    @chmod($part, 0600);
    $digest = hash_init('sha256');
    $copied = 0;
    try {
        while (!feof($in)) {
            $chunk = fread($in, 1048576);
            if ($chunk === false) {
                throw new RespaldoMedioException('No se pudo leer el archivo.');
            }
            if ($chunk === '') {
                break;
            }
            hash_update($digest, $chunk);
            $offset = 0;
            while ($offset < strlen($chunk)) {
                $n = fwrite($out, substr($chunk, $offset));
                if ($n === false || $n === 0) {
                    throw new RespaldoMedioException('No hay espacio para el respaldo.');
                }
                $offset += $n;
            }
            $copied += strlen($chunk);
        }
        if (!fflush($out) || $copied !== $size || !hash_equals($hash, hash_final($digest))) {
            throw new RespaldoMedioException('El respaldo llegó incompleto.');
        }
        if (function_exists('fsync') && !fsync($out)) {
            throw new RespaldoMedioException('No se pudo confirmar el respaldo en disco.');
        }
        fclose($out);
        $out = null;
        fclose($in);
        $in = null;
        // link is exclusive; a concurrent writer cannot replace a completed copy.
        if (!@link($part, $destination) && (!is_file($destination) || is_link($destination) || !hash_equals($hash, hash_file('sha256', $destination)))) {
            throw new RespaldoMedioException('No se pudo finalizar el respaldo.');
        }
        @chmod($destination, 0600);
    } finally {
        if (is_resource($in)) {
            fclose($in);
        }
        if (is_resource($out)) {
            fclose($out);
        }
        if (is_file($part) && !is_link($part)) {
            unlink($part);
        }
    }
}
/** Restore missing bytes only, using the private immutable copy and existing DB name. */
function recuperarMedioDesdeRespaldo(string $kind, string $name): bool
{
    if (!nombreMedioRespaldoValido($kind, $name)) {
        return false;
    }
    $targetDir = musicmadUploadDirectory($kind);
    $target = $targetDir . '/' . $name;
    if (is_link($targetDir) || is_link($target)) {
        return false;
    }
    if (is_file($target)) {
        return true;
    }
    try {
        $backup = carpetaRespaldoMedios($kind) . '/' . $name;
    } catch (RespaldoMedioException $error) {
        return false;
    }
    if (is_link($backup) || !is_file($backup) || filesize($backup) < 1) {
        return false;
    }
    if (!is_dir($targetDir) && !mkdir($targetDir, 0755, true)) {
        return false;
    }
    $part = $targetDir . '/.restore-' . bin2hex(random_bytes(12));
    try {
        if (!copy($backup, $part) || !hash_equals(hash_file('sha256', $backup), hash_file('sha256', $part))) {
            return false;
        }
        @chmod($part, 0644);
        return @link($part, $target) || (!is_link($target) && is_file($target) && hash_equals(hash_file('sha256', $backup), hash_file('sha256', $target)));
    } finally {
        if (is_file($part) && !is_link($part)) {
            unlink($part);
        }
    }
}
