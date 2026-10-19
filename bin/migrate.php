<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
if (count($argv) > 2 || (isset($argv[1]) && $argv[1] !== '--status')) {
    fwrite(STDERR, "Uso: php bin/migrate.php [--status]\n");
    exit(2);
}
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/migration_runner.php';
try {
    foreach (ejecutarMigracionesMusicMad(getDB(), APP_ROOT . '/config/migrations', isset($argv[1])) as $item) {
        echo $item['version'] . ' — ' . $item['estado'] . "\n";
    }
    echo isset($argv[1]) ? "Estado verificado; no se aplicaron migraciones.\n" : "Actualizaciones de esquema completas.\n";
} catch (Throwable $error) {
    error_log('Migration runner: ' . get_class($error));
    fwrite(STDERR, "Actualización detenida: verifica disponibilidad, integridad y permisos de las migraciones. No ejecutes dos actualizaciones simultáneas.\n");
    exit(1);
}
