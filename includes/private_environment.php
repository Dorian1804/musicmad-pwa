<?php

/** Environment (including an explicit empty value) precedes legacy private constants. */
function musicmadConfigValue(string $name): string
{
    if (!in_array($name, ['DB_HOST','DB_NAME','DB_USER','DB_PASS','GOOGLE_CLIENT_ID','GOOGLE_CLIENT_SECRET','GOOGLE_REDIRECT_URI'], true)) {
        throw new InvalidArgumentException('Opción de configuración no permitida.');
    }
    $environment = getenv('MM_' . $name);
    if ($environment !== false) {
        return $environment;
    }
    $legacy = defined($name) ? constant($name) : '';
    return is_string($legacy) || is_int($legacy) ? (string)$legacy : '';
}

/** Discover an existing private environment without opening its contents. */
function musicmadPrivateEnvironmentFile(string $appRoot, string $documentRoot = ''): ?string
{
    $app = realpath($appRoot);
    if ($app === false || !is_dir($app)) {
        return null;
    }
    $publicParent = realpath(dirname($app));
    $document = $documentRoot !== '' ? realpath($documentRoot) : false;
    $normalize = static function (string $path): string {
        $path = rtrim(str_replace('\\', '/', $path), '/') . '/';
        return PHP_OS_FAMILY === 'Windows' ? strtolower($path) : $path;
    };

    $ancestor = dirname($app);
    for ($depth = 0; $depth < 6; $depth++) {
        $candidate = $ancestor . '/backups/musicmad-media/private-config/.env';
        $private = realpath($candidate);
        if ($private !== false && is_file($private) && is_readable($private)) {
            $linked = false;
            for ($component = $candidate; ; $component = dirname($component)) {
                if (is_link($component)) {
                    $linked = true;
                    break;
                }
                if (dirname($component) === $component) {
                    break;
                }
            }
            if (!$linked
                && ($publicParent === false || !str_starts_with($normalize($private), $normalize($publicParent)))
                && ($document === false || !str_starts_with($normalize($private), $normalize($document)))) {
                return $private;
            }
        }
        $parent = dirname($ancestor);
        if ($parent === $ancestor) {
            break;
        }
        $ancestor = $parent;
    }

    return null;
}

/** Fixed deployment migration: rename the legacy environment, never read/copy its bytes. */
function musicmadMigratePublicEnvironment(string $appRoot, string $documentRoot = ''): ?string
{
    $private = musicmadPrivateEnvironmentFile($appRoot, $documentRoot);
    $app = realpath($appRoot);
    if ($app === false) {
        return $private;
    }
    $source = $app . '/.env';
    if (!is_file($source) || is_link($source)) {
        return $private;
    }
    $normalize = static function (string $path): string {
        $path = rtrim(str_replace('\\', '/', $path), '/') . '/';
        return PHP_OS_FAMILY === 'Windows' ? strtolower($path) : $path;
    };
    $public = realpath(dirname($app));
    $document = $documentRoot !== '' ? realpath($documentRoot) : false;
    $ancestor = dirname($app);
    for ($depth = 0; $depth < 6; $depth++) {
        $backup = $ancestor . '/backups/musicmad-media';
        $realBackup = realpath($backup);
        $linked = false;
        for ($component = $backup; ; $component = dirname($component)) {
            if (is_link($component)) {
                $linked = true;
                break;
            }
            if (dirname($component) === $component) {
                break;
            }
        }
        if ($realBackup !== false && !$linked && is_dir($realBackup) && is_writable($realBackup)
            && ($public === false || !str_starts_with($normalize($realBackup), $normalize($public)))
            && ($document === false || !str_starts_with($normalize($realBackup), $normalize($document)))) {
            $dir = $realBackup . '/private-config';
            if (is_link($dir) || (!is_dir($dir) && !@mkdir($dir, 0700) && !is_dir($dir))) {
                return $private;
            }
            if (realpath(dirname($dir)) !== $realBackup || !is_writable($dir)) {
                return $private;
            }
            $lock = @fopen($dir . '/.migration.lock', 'c');
            if (!$lock) {
                return $private;
            }
            try {
                if (!flock($lock, LOCK_EX | LOCK_NB)) {
                    return $private;
                }
                clearstatcache(true, $source);
                if (!is_file($source) || is_link($source)) {
                    return musicmadPrivateEnvironmentFile($appRoot, $documentRoot);
                }
                $target = $dir . '/.env';
                if (is_link($target)) {
                    return $private;
                }
                // Preserve an existing private configuration; retire the public duplicate separately.
                $destination = file_exists($target) ? $dir . '/.env-retired-' . bin2hex(random_bytes(12)) : $target;
                if (!@rename($source, $destination)) {
                    error_log('MusicMad public environment relocation failed.');
                    return $private;
                }
                @chmod($destination, 0600);
                return musicmadPrivateEnvironmentFile($appRoot, $documentRoot);
            } finally {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
        $parent = dirname($ancestor);
        if ($parent === $ancestor) {
            break;
        }
        $ancestor = $parent;
    }
    return $private;
}
