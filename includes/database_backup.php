<?php

function identificadorRespaldo(string $name): string
{
    return '`' . str_replace('`', '``', $name) . '`';
}
/** SQL snapshot, without server users or environment secrets. Destination must be private. */
function escribirRespaldoSQL(PDO $db, string $destination, int $budgetSeconds = 55, ?array $onlyTables = null): void
{
    if (is_link($destination) || file_exists($destination)) {
        throw new RuntimeException('El destino ya existe.');
    }
    $handle = fopen($destination, 'xb');
    if ($handle === false) {
        throw new RuntimeException('No se pudo crear el respaldo.');
    }@chmod($destination, 0600);
    $start = microtime(true);
    $write = static function (string $data) use ($handle, $start, $budgetSeconds): void {
        if (microtime(true) - $start > $budgetSeconds) {
            throw new RuntimeException('Respaldo excedió el tiempo permitido.');
        }
        $length = strlen($data);
        for ($offset = 0;$offset < $length;) {
            $written = fwrite($handle, substr($data, $offset));
            if ($written === false || $written === 0) {
                throw new RuntimeException('No se pudo completar el respaldo.');
            }
            $offset += $written;
        }
    };
    try {
        if ($db->inTransaction()) {
            throw new RuntimeException('No se puede respaldar dentro de otra transacción.');
        }
        $tables = $db->query("SELECT TABLE_NAME,ENGINE FROM information_schema.tables WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_ASSOC);
        if ($onlyTables !== null) {
            $tables = array_values(array_filter($tables, static fn ($table) => in_array($table['TABLE_NAME'], $onlyTables, true)));
            if (count($tables) !== count(array_unique($onlyTables))) {
                throw new RuntimeException('Tabla de respaldo no disponible.');
            }
        }
        foreach ($tables as $table) {
            if (strcasecmp($table['ENGINE'], 'InnoDB') !== 0) {
                throw new RuntimeException('El respaldo consistente requiere tablas InnoDB.');
            }
        }
        $db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $db->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');
        $write("-- MusicMad SQL snapshot UTC " . gmdate('c') . "\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n");
        foreach ($tables as $table) {
            $quoted = identificadorRespaldo($table['TABLE_NAME']);
            $definition = $db->query('SHOW CREATE TABLE ' . $quoted)->fetch(PDO::FETCH_NUM);
            $write("\n" . $definition[1] . ";\n");
            $buffered = $db->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY);
            $db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
            $rows = null;
            try {
                $rows = $db->query('SELECT * FROM ' . $quoted);
                while ($row = $rows->fetch(PDO::FETCH_ASSOC)) {
                    $columns = array_map('identificadorRespaldo', array_keys($row));
                    $values = array_map(static fn ($value) => $value === null ? 'NULL' : "X'" . bin2hex((string)$value) . "'", array_values($row));
                    $write('INSERT INTO ' . $quoted . ' (' . implode(',', $columns) . ') VALUES (' . implode(',', $values) . ");\n");
                }
            } finally {
                if ($rows !== null) {
                    $rows->closeCursor();
                }
                $db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, $buffered);
            }
        }
        $write("\nSET FOREIGN_KEY_CHECKS=1;\n");
        $db->commit();
        if (!fflush($handle)) {
            throw new RuntimeException('No se pudo guardar el respaldo.');
        }
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        fclose($handle);
        @unlink($destination);
        throw $error;
    }
    fclose($handle);
}
