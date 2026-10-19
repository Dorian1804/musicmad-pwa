<?php

/** Trusted migration files only: native MySQL prepares cannot execute SQL batches. */
function ejecutarLoteSQLMusicMad(PDO $db, string $sql): void
{
    $emulated = $db->getAttribute(PDO::ATTR_EMULATE_PREPARES);
    $statement = null;
    try {
        $db->setAttribute(PDO::ATTR_EMULATE_PREPARES, true);
        $statement = $db->query($sql);
        if ($statement !== false) {
            do {
                if ($statement->columnCount() > 0) {
                    while ($statement->fetch(PDO::FETCH_NUM) !== false) {
                    }
                }
            } while ($statement->nextRowset());
        }
    } finally {
        if ($statement !== null && $statement !== false) {
            $statement->closeCursor();
        }
        $db->setAttribute(PDO::ATTR_EMULATE_PREPARES, $emulated);
    }
}
function ejecutarMigracionesMusicMad(PDO $db, string $directory, bool $statusOnly = false): array
{
    $lock = 'musicmad_schema_' . substr(hash('sha256', (string)$db->query('SELECT DATABASE()')->fetchColumn()), 0, 32);
    $st = $db->prepare('SELECT GET_LOCK(?,0)');
    $st->execute([$lock]);
    if ((int)$st->fetchColumn() !== 1) {
        throw new RuntimeException('Otra actualización de esquema está en curso.');
    }
    try {
        $db->exec('CREATE TABLE IF NOT EXISTS musicmad_migrations(version VARCHAR(100) PRIMARY KEY,checksum CHAR(64) NOT NULL,aplicada_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $files = glob($directory . '/[0-9][0-9][0-9]_*.sql');
        if ($files === false) {
            throw new RuntimeException('Directorio de migraciones no disponible.');
        }
        sort($files, SORT_STRING);
        $plan = [];
        foreach ($files as $file) {
            if (is_link($file) || !is_file($file) || !is_readable($file)) {
                throw new RuntimeException('Migración no disponible.');
            }
            $version = basename($file);
            $checksum = hash_file('sha256', $file);
            if ($checksum === false) {
                throw new RuntimeException('No se pudo verificar una migración.');
            }
            $st = $db->prepare('SELECT checksum FROM musicmad_migrations WHERE version=?');
            $st->execute([$version]);
            $old = $st->fetchColumn();
            if ($old !== false && !hash_equals((string)$old, $checksum)) {
                throw new RuntimeException('La migración ' . $version . ' cambió después de aplicarse.');
            }
            $plan[] = ['version' => $version,'checksum' => $checksum,'file' => $file,'estado' => $old === false ? 'pendiente' : 'aplicada'];
        }
        foreach ($plan as &$item) {
            if ($item['estado'] !== 'pendiente' || $statusOnly) {
                continue;
            }
            $sql = file_get_contents($item['file']);
            if ($sql === false || !hash_equals($item['checksum'], hash('sha256', $sql))) {
                throw new RuntimeException('La migración cambió durante la actualización.');
            }
            ejecutarLoteSQLMusicMad($db, $sql);
            $db->prepare('INSERT INTO musicmad_migrations(version,checksum) VALUES(?,?)')->execute([$item['version'],$item['checksum']]);
            $item['estado'] = 'aplicada';
        }
        unset($item);
        return array_map(static fn ($item) => ['version' => $item['version'],'estado' => $item['estado']], $plan);
    } finally {
        $db->prepare('SELECT RELEASE_LOCK(?)')->execute([$lock]);
    }
}
