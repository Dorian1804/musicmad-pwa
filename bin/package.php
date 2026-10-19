<?php

/** Delivery tooling: deliberately excluded from application ZIPs. Never loads application configuration. */
function packagePathAllowed(string $name, bool $vendor = true): bool
{
    return $name !== '' && !preg_match('/[\x00-\x1f\x7f:]/', $name) && !str_contains($name, '\\') && !str_starts_with($name, '/')
        && !in_array('..', explode('/', $name), true)
        && !preg_match('~(^|/)(?:local[.]php|[.]env[^/]*|tests?|backups|[.]git[^/]*|[.]phpunit[^/]*|[.]tests-tmp|node_modules|docs)(/|$)~i', $name)
        && !preg_match('~^(?:uploads/|storage/|bin/package[.])|[.](?:zip|7z|rar|log|cache|md)$~i', $name)
        && $name !== 'config/musicmad.sql' && ($vendor || !str_starts_with($name, 'vendor/'));
}

function packageBytesSafe(string $name, string $bytes): bool
{
    // Match actual key material, not the PEM marker strings used by crypto libraries.
    if (preg_match('~GOCSPX-[A-Za-z0-9_-]{8,}|-----BEGIN [A-Z ]*(?:PRIVATE KEY|CERTIFICATE)-----\s+[A-Za-z0-9+/=\r\n]{40,}~', $bytes)) {
        return false;
    }
    if (str_starts_with($name, 'config/') && str_ends_with($name, '.php')) {
        preg_match_all('~define\(\s*[\'\"](?:DB_PASS|GOOGLE_CLIENT_SECRET|MAIL_PASS)[\'\"]\s*,\s*([\'\"])(.*?)\1\s*\)~s', $bytes, $matches);
        foreach ($matches[2] as $value) {
            if ($value !== '' && !str_starts_with($value, 'REEMPLAZAR_') && !str_starts_with($value, 'TU_')) {
                return false;
            }
        }
    }
    return true;
}

function verifyDeliveryPackage(string $path): int
{
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::RDONLY) !== true) {
        throw new RuntimeException('No se pudo abrir el paquete.');
    }
    try {
        $seen = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (!is_string($name) || !packagePathAllowed($name) || isset($seen[$name])) {
                throw new RuntimeException('El paquete contiene una ruta excluida o duplicada.');
            }
            $seen[$name] = true;
            $bytes = $zip->getFromIndex($i);
            if (!is_string($bytes) || !packageBytesSafe($name, $bytes)) {
                throw new RuntimeException('El paquete contiene contenido sensible o ilegible.');
            }
        }
        return $zip->numFiles;
    } finally {
        $zip->close();
    }
}

function buildDeliveryPackage(string $output, bool $vendor = true): int
{
    $root = realpath(dirname(__DIR__));
    $parent = realpath(dirname($output));
    if ($root === false || $parent === false || is_link(dirname($output)) || is_file($output)
        || str_starts_with(strtolower(str_replace('\\', '/', $parent) . '/'), strtolower(str_replace('\\', '/', $root) . '/'))
        || !str_ends_with($output, '.zip')) {
        throw new RuntimeException('El destino debe ser un ZIP nuevo fuera del proyecto.');
    }
    $list = json_decode(file_get_contents($root . '/tests/quality-files.json'), true, 512, JSON_THROW_ON_ERROR);
    $names = array_merge($list, ['.htaccess', '.user.ini', 'manifest.json', 'sw.js', 'offline.html', 'biblioteca.html', 'pendientes.html', 'composer.json', 'composer.lock', 'api/v1/openapi.json']);
    $names = array_merge($names, json_decode(file_get_contents($root . '/tests/package-assets.json'), true, 512, JSON_THROW_ON_ERROR));
    foreach (['config/migrations', ...($vendor ? ['vendor'] : [])] as $directory) {
        if (!is_dir($root . '/' . $directory)) {
            continue;
        }
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $directory, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile() && !$file->isLink()) {
                $names[] = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            }
        }
    }
    $zip = new ZipArchive();
    $temporary = $parent . '/.package-' . bin2hex(random_bytes(12)) . '.zip';
    if ($zip->open($temporary, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
        throw new RuntimeException('No se pudo crear el paquete.');
    }
    try {
        foreach (array_unique($names) as $name) {
            if (!packagePathAllowed($name, $vendor) || !is_file($root . '/' . $name)) {
                continue;
            }
            $path = realpath($root . '/' . $name);
            if ($path === false || is_link($root . '/' . $name) || !str_starts_with($path, $root . DIRECTORY_SEPARATOR)) {
                throw new RuntimeException('Origen de paquete no permitido.');
            }
            $bytes = file_get_contents($path);
            if (!is_string($bytes) || !packageBytesSafe($name, $bytes)) {
                throw new RuntimeException('Se bloqueó un archivo potencialmente sensible. No se generó una entrega.');
            }
            $zip->addFromString($name, $bytes);
        }
        $zip->close();
        $count = verifyDeliveryPackage($temporary);
        if (!link($temporary, $output)) {
            throw new RuntimeException('No se pudo publicar el paquete sin sobrescribir archivos.');
        }
        return $count;
    } finally {
        if (is_file($temporary)) {
            @unlink($temporary);
        }
    }
}

if (PHP_SAPI === 'cli' && realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    try {
        $options = getopt('', ['output:', 'without-vendor', 'verify:']);
        $count = isset($options['verify']) ? verifyDeliveryPackage($options['verify'])
            : buildDeliveryPackage($options['output'] ?? '', !isset($options['without-vendor']));
        echo 'Paquete comprobado: ' . $count . " archivos.\n";
    } catch (Throwable $error) {
        fwrite(STDERR, "No se generó/verificó el paquete: revisa las exclusiones, el destino y el detector de secretos.\n");
        exit(1);
    }
}
