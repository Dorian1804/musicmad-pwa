<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/database_backup.php';
try {
    if (count($argv) !== 2) {
        throw new RuntimeException('Indica un directorio privado existente fuera del directorio público.');
    }
    $directory = realpath($argv[1]);
    $root = realpath(APP_ROOT);
    $publicRoot = realpath(dirname(APP_ROOT));
    if ($directory === false || !is_dir($directory) || is_link($argv[1]) || !is_writable($directory)) {
        throw new RuntimeException('Directorio privado no disponible.');
    }
    // Conservative boundary: refuse the app and its parent (normally public_html).
    $normalized = strtolower(str_replace('\\', '/', $directory)) . '/';
    $public = strtolower(str_replace('\\', '/', $publicRoot ?: $root)) . '/';
    if (str_starts_with($normalized, $public)) {
        throw new RuntimeException('El respaldo debe estar fuera del directorio público.');
    }
    $lock = fopen($directory . '/.musicmad-backup.lock', 'c');
    if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
        throw new RuntimeException('Otro respaldo está en curso.');
    }@chmod($directory . '/.musicmad-backup.lock', 0600);
    try {
        $name = 'musicmad-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.sql';
        $part = $directory . '/' . $name . '.part';
        $db = getDB();
        $schemaLock = 'musicmad_schema_' . substr(hash('sha256', (string)$db->query('SELECT DATABASE()')->fetchColumn()), 0, 32);
        $st = $db->prepare('SELECT GET_LOCK(?,0)');
        $st->execute([$schemaLock]);
        if ((int)$st->fetchColumn() !== 1) {
            throw new RuntimeException('Una migración está en curso.');
        }
        try {
            escribirRespaldoSQL($db, $part);
        } finally {
            $db->prepare('SELECT RELEASE_LOCK(?)')->execute([$schemaLock]);
        }
        if (!rename($part, $directory . '/' . $name)) {
            @unlink($part);
            throw new RuntimeException('No se pudo finalizar el respaldo.');
        }
        // Only our completed, regular snapshots; never follow a link or recurse.
        foreach (glob($directory . '/musicmad-*.sql') ?: [] as $old) {
            if (!is_link($old) && is_file($old) && preg_match('/^musicmad-[0-9]{8}-[0-9]{6}-[a-f0-9]{8}\.sql$/D', basename($old)) && filemtime($old) < time() - 7 * 86400) {
                unlink($old);
            }
        }
        echo "Respaldo completado. Conserva el directorio privado fuera del ZIP.\n";
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
} catch (Throwable $error) {
    error_log('Database backup: ' . get_class($error));
    fwrite(STDERR, "No se completó el respaldo. Revisa directorio privado, permisos, tablas InnoDB y espacio disponible.\n");
    exit(1);
}
