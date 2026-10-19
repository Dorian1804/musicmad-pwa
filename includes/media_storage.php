<?php

require_once __DIR__ . '/media_backup.php';

/** A conservative pre-decode estimate. It cannot replace the hosting's process memory limit. */
function presupuestoImagenSeguro(int $width, int $height, string $memoryLimit, int $used): bool
{
    if ($width < 1 || $height < 1 || $height > 16000000 || $width > intdiv(16000000, $height) || $used < 0) {
        return false;
    }
    $limit = 256 * 1048576;
    if ($memoryLimit !== '-1') {
        if (!preg_match('/^([0-9]+)\s*([KMG]?)$/iD', trim($memoryLimit), $match)) {
            return false;
        }
        $factor = match (strtoupper($match[2])) {
            'K' => 1024, 'M' => 1048576, 'G' => 1073741824, default => 1
        };
        $limit = min($limit, (float)$match[1] * $factor);
    }
    return $used + $width * $height * 8 + 16 * 1048576 <= $limit;
}

/** User bytes live outside the deployable application, under the private backup root. */
function musicmadMediaRoot(): string
{
    if (defined('APP_UPLOAD_ROOT') && musicmadConfiguredMediaRootIsPrivate()) {
        $configured = realpath((string)APP_UPLOAD_ROOT);
        if ($configured === false || !is_writable($configured)) {
            throw new RespaldoMedioException('El almacén permanente no está disponible.');
        }
        return str_replace('\\', '/', $configured);
    }
    static $root = null;
    if ($root !== null) {
        return $root;
    }
    $parent = directorioRespaldoMedios();
    $candidate = $parent . '/live';
    if (is_link($candidate) || (!is_dir($candidate) && !@mkdir($candidate, 0700) && !is_dir($candidate))) {
        throw new RespaldoMedioException('No se pudo preparar el almacén permanente. Tu archivo no se publicó.');
    }
    $resolved = realpath($candidate);
    if ($resolved === false || dirname($resolved) !== realpath($parent) || !is_writable($resolved)) {
        throw new RespaldoMedioException('El almacén permanente no está disponible.');
    }
    return $root = $resolved;
}

/** A historical public upload root is a read source, never the destination of new uploads. */
function musicmadConfiguredMediaRootIsPrivate(): bool
{
    if (!defined('APP_UPLOAD_ROOT') || is_link((string)APP_UPLOAD_ROOT)) {
        return false;
    }
    $path = realpath((string)APP_UPLOAD_ROOT);
    $app = realpath(APP_ROOT);
    $document = realpath((string)($_SERVER['DOCUMENT_ROOT'] ?? ''));
    return $path !== false && is_dir($path)
        && ($app === false || !rutaDentroRespaldo($path, $app))
        && ($document === false || !rutaDentroRespaldo($path, $document));
}

function musicmadUploadDirectory(string $kind): string
{
    if (!in_array($kind, ['audio','videos','avatars','covers','market','partituras','clips','stems','events'], true)) {
        throw new InvalidArgumentException('Tipo de medios inválido.');
    }
    $root = musicmadMediaRoot();
    $dir = $root . '/' . $kind;
    if (is_link($dir)) {
        throw new RespaldoMedioException('El almacén de medios no es seguro.');
    }
    if ((!is_dir($dir) && !@mkdir($dir, 0700) && !is_dir($dir))
        || realpath(dirname($dir)) !== realpath($root) || !is_writable($dir)) {
        throw new RespaldoMedioException('No se pudo preparar el almacén de medios.');
    }
    return $dir;
}

function musicmadMediaNameValid(string $kind, string $name): bool
{
    return match ($kind) {
        'audio','videos','partituras' => nombreMedioRespaldoValido($kind, $name),
        'avatars','covers' => preg_match('/^[1-9][0-9]*_[a-f0-9]{24}\.jpg$/D', $name) === 1,
        'market' => preg_match('/^[a-f0-9]{24}\.jpg$/D', $name) === 1,
        default => false,
    };
}

/** Copy and verify before publishing a name. Never replace an existing destination or delete the source. */
function musicmadPreserveMediaFile(string $source, string $destination): bool
{
    if (is_link($source) || is_link(dirname($source)) || !is_file($source) || is_link($destination)) {
        return false;
    }
    if (is_file($destination)) {
        return filesize($source) === filesize($destination)
            && hash_equals(hash_file('sha256', $source), hash_file('sha256', $destination));
    }
    $size = filesize($source);
    $hash = hash_file('sha256', $source);
    if ($size === false || $size < 1 || !is_string($hash)) {
        return false;
    }
    $part = dirname($destination) . '/.preserve-' . bin2hex(random_bytes(12));
    try {
        if (!@copy($source, $part) || filesize($part) !== $size || !hash_equals($hash, hash_file('sha256', $part))) {
            return false;
        }
        @chmod($part, 0600);
        // Both paths belong to the same private directory; publication is exclusive.
        if (!@link($part, $destination)) {
            return !is_link($destination) && is_file($destination)
                && filesize($destination) === $size && hash_equals($hash, hash_file('sha256', $destination));
        }
        return true;
    } finally {
        if (is_file($part) && !is_link($part)) {
            @unlink($part);
        }
    }
}

/** Read-through compatibility for old database names and public image URLs. */
function musicmadMediaDirectoryForFile(string $kind, string $name): string
{
    $directory = musicmadUploadDirectory($kind);
    if (!musicmadMediaNameValid($kind, $name) || is_file($directory . '/' . $name) || is_link($directory . '/' . $name)) {
        return $directory;
    }
    if (defined('MUSICMAD_TEST_RUN') && MUSICMAD_TEST_RUN === true && musicmadConfiguredMediaRootIsPrivate()) {
        recuperarMedioDesdeRespaldo($kind, $name);
        return $directory;
    }
    $sources = [APP_ROOT . '/uploads'];
    if (defined('APP_UPLOAD_ROOT')) {
        array_unshift($sources, rtrim((string)APP_UPLOAD_ROOT, '/\\'));
    }
    foreach (array_unique($sources) as $legacyRoot) {
        $legacy = $legacyRoot . '/' . $kind;
        $base = realpath($legacy);
        $source = realpath($legacy . '/' . $name);
        if ($base === false || $source === false || dirname($source) !== $base
            || is_link($legacyRoot) || is_link($legacy) || is_link($legacy . '/' . $name)) {
            continue;
        }
        // Preservation never moves or replaces the old bytes. Failed copies remain readable.
        return musicmadPreserveMediaFile($source, $directory . '/' . $name) ? $directory : $legacy;
    }
    recuperarMedioDesdeRespaldo($kind, $name);
    return $directory;
}

/** An obsolete image URL must not resurrect an image removed from its owning record. */
function musicmadImageIsReferenced(string $kind, string $name): bool
{
    if (!musicmadMediaNameValid($kind, $name)) {
        return false;
    }
    $sql = match ($kind) {
        'avatars' => 'SELECT 1 FROM usuarios WHERE avatar=? AND activo=1 LIMIT 1',
        'covers' => 'SELECT 1 FROM fondos_perfil f JOIN usuarios u ON u.id=f.usuario_id AND u.activo=1 WHERE f.archivo=? LIMIT 1',
        'market' => "SELECT 1 FROM instrumentos_fotos f JOIN instrumentos_usados i ON i.id=f.instrumento_id AND i.estado<>'oculto' JOIN usuarios u ON u.id=i.usuario_id AND u.activo=1 WHERE f.archivo=? LIMIT 1",
        default => null,
    };
    if ($sql === null) {
        return false;
    }
    $find = getDB()->prepare($sql);
    $find->execute([$kind === 'avatars' ? 'uploads/avatars/' . $name : $name]);
    return (bool) $find->fetchColumn();
}

/** Public presentation URL; database values and built-in images retain their paths. */
function musicmadPublicImageUrl(string $path): string
{
    if (!preg_match('#^uploads/(avatars|covers|market)/([^/]+)$#D', $path, $parts)
        || !musicmadMediaNameValid($parts[1], $parts[2])) {
        return $path;
    }

    return rtrim(APP_URL, '/') . '/media-image.php?kind=' . rawurlencode($parts[1])
        . '&name=' . rawurlencode($parts[2]);
}
