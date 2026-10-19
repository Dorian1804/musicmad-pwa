<?php

require_once __DIR__ . '/paths.php';
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/includes/database_backup.php';
final class DatabaseBackupIntegrationTest extends TestCase
{
    public function testSnapshotRestoresBinaryUnicodeAndNullIntoSeparateQaTable(): void
    {
        if (getenv('MM_RUN_QA') !== '1') {
            $this->markTestSkipped('Solo QA aislada.');
        }
        self::assertSame('musicmad_qa', DB_NAME);
        $name = 'qa_backup_' . bin2hex(random_bytes(6));
        $copy = $name . '_restore';
        $file = musicmadQaRoot() . '/' . $name . '.sql';
        $db = getDB();
        try {
            $db->exec('CREATE TABLE ' . identificadorRespaldo($name) . ' (id INT PRIMARY KEY, texto VARCHAR(100), datos BLOB, vacio VARCHAR(10) NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
            $db->prepare('INSERT INTO ' . identificadorRespaldo($name) . ' VALUES(?,?,?,?)')->execute([1,"Música ' 🎸\n", "\x00\xff\x01",null]);
            escribirRespaldoSQL($db, $file, 10, [$name]);
            self::assertFileExists($file);
            self::assertFalse($db->inTransaction());
            $sql = file_get_contents($file);
            self::assertStringNotContainsString('DROP TABLE', $sql);
            $db->exec(str_replace(identificadorRespaldo($name), identificadorRespaldo($copy), $sql));
            self::assertSame($db->query('SELECT * FROM ' . identificadorRespaldo($name))->fetchAll(), $db->query('SELECT * FROM ' . identificadorRespaldo($copy))->fetchAll());
            try {
                escribirRespaldoSQL($db, $file, 10, [$name]);
                self::fail('Existing snapshot overwritten');
            } catch (RuntimeException $e) {
                self::assertFileExists($file);
            }
        } finally {
            $db->exec('SET FOREIGN_KEY_CHECKS=1');
            $db->exec('DROP TABLE IF EXISTS ' . identificadorRespaldo($copy));
            $db->exec('DROP TABLE IF EXISTS ' . identificadorRespaldo($name));
            if (is_file($file)) {
                unlink($file);
            }
        }
    }
}
