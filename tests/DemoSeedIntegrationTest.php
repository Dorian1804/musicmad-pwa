<?php

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/includes/demo_seed.php';
final class DemoSeedIntegrationTest extends TestCase
{
    public function testDestinationGuardRejectsProductionRemoteUnconfirmedAndImplicitOptIn(): void
    {
        foreach ([['REPLACE_WITH_LOCAL_VALUE','REPLACE_WITH_LOCAL_VALUE','localhost',true],['musicmad_demo','musicmad_demo','db.host.invalid',true],['musicmad_demo','musicmad_other_demo','localhost',true],['musicmad_demo','musicmad_demo','localhost',false],['musicmad_qa;DROP','musicmad_qa;DROP','localhost',true]] as $args) {
            try {
                validarDestinoDemoMusicMad(...$args);
                self::fail('Unsafe seed destination accepted.');
            } catch (DomainException $error) {
                self::assertStringContainsString('Solo se permiten', $error->getMessage());
            }
        }
        validarDestinoDemoMusicMad('musicmad_demo', 'musicmad_demo', '127.0.0.1', true);
        validarDestinoDemoMusicMad('musicmad_qa', 'musicmad_qa', '127.0.0.1;port=3308', true);
        self::assertCount(3, perfilesDemoMusicMad());
    }
    private function qa(): PDO
    {
        if (getenv('MM_RUN_QA') !== '1') {
            $this->markTestSkipped('Solo QA aislada.');
        }
        self::assertSame('musicmad_qa', DB_NAME);
        return getDB();
    }
    private function cleanup(PDO $db): void
    {
        foreach (perfilesDemoMusicMad() as $profile) {
            $db->prepare("DELETE FROM usuarios WHERE email=? AND apellido='Demo'")->execute([$profile['email']]);
        }
    }
    private function requireEmptyDemo(PDO $db): void
    {
        $st = $db->prepare('SELECT COUNT(*) FROM usuarios WHERE email IN (?,?,?)');
        $st->execute(array_column(perfilesDemoMusicMad(), 'email'));
        if ((int)$st->fetchColumn() !== 0) {
            $this->markTestSkipped('Conservar la demo existente; las pruebas necesitan un espacio de fixtures vacío.');
        }
    }
    public function testAtomicSeedRepeatAndPreservationOfUnrelatedRows(): void
    {
        $db = $this->qa();
        $this->requireEmptyDemo($db);
        $before = $db->query('SELECT id,REPLACE_WITH_LOCAL_VALUE,bio FROM usuarios WHERE id=1')->fetch();
        try {
            $result = sembrarDemoMusicMad($db, DB_NAME, DB_HOST, true);
            self::assertSame('creada', $result['estado']);
            self::assertCount(3, $result['usuarios']);
            self::assertSame(9, $result['publicaciones']);
            foreach ($result['usuarios'] as $id) {
                $st = $db->prepare('SELECT email,REPLACE_WITH_LOCAL_VALUE,rol FROM usuarios WHERE id=?');
                $st->execute([$id]);
                $user = $st->fetch();
                self::assertSame('cliente', $user['rol']);
                self::assertStringEndsWith('@musicmad.invalid', $user['email']);
                self::assertFalse(REPLACE_WITH_LOCAL_VALUE_verify('MusicMad_QA_Example_42!', $user['REPLACE_WITH_LOCAL_VALUE']));
                $st = $db->prepare('SELECT COUNT(*) FROM publicaciones WHERE usuario_id=?');
                $st->execute([$id]);
                self::assertSame(3, (int)$st->fetchColumn());
            }
            $repeat = sembrarDemoMusicMad($db, DB_NAME, DB_HOST, true);
            self::assertSame('existente', $repeat['estado']);
            self::assertSame($result['usuarios'], $repeat['usuarios']);
            self::assertSame($before, $db->query('SELECT id,REPLACE_WITH_LOCAL_VALUE,bio FROM usuarios WHERE id=1')->fetch());
            $db->prepare('UPDATE usuarios SET bio=? WHERE id=?')->execute(['Demo edited intentionally',$result['usuarios'][0]]);
            try {
                sembrarDemoMusicMad($db, DB_NAME, DB_HOST, true);
                self::fail('Edited demo overwritten.');
            } catch (DomainException $error) {
                self::assertStringContainsString('ocupado', $error->getMessage());
            }
            $st = $db->prepare('SELECT bio FROM usuarios WHERE id=?');
            $st->execute([$result['usuarios'][0]]);
            self::assertSame('Demo edited intentionally', $st->fetchColumn());
        } finally {
            $this->cleanup($db);
        }
    }
    public function testPartialCollisionStopsBeforeAnyInsert(): void
    {
        $db = $this->qa();
        $this->requireEmptyDemo($db);
        $profile = perfilesDemoMusicMad()[1];
        $db->prepare("INSERT INTO usuarios(nombre,apellido,email,REPLACE_WITH_LOCAL_VALUE,bio) VALUES('Collision','Demo',?,'fake','Keep this row')")->execute([$profile['email']]);
        $id = (int)$db->lastInsertId();
        try {
            try {
                sembrarDemoMusicMad($db, DB_NAME, DB_HOST, true);
                self::fail('Collision accepted.');
            } catch (DomainException $error) {
                self::assertStringContainsString('ocupado', $error->getMessage());
            }
            $st = $db->prepare('SELECT bio FROM usuarios WHERE id=?');
            $st->execute([$id]);
            self::assertSame('Keep this row', $st->fetchColumn());
            $st = $db->prepare('SELECT COUNT(*) FROM usuarios WHERE email IN (?,?)');
            $st->execute([perfilesDemoMusicMad()[0]['email'],perfilesDemoMusicMad()[2]['email']]);
            self::assertSame(0, (int)$st->fetchColumn());
            self::assertFalse($db->inTransaction());
        } finally {
            $this->cleanup($db);
        }
    }
    public function testConcurrentSeedAndWrongConfirmationAreRejected(): void
    {
        $db = $this->qa();
        $other = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $lock = 'musicmad_demo_' . substr(hash('sha256', DB_NAME), 0, 32);
        $st = $other->prepare('SELECT GET_LOCK(?,0)');
        $st->execute([$lock]);
        self::assertSame(1, (int)$st->fetchColumn());
        try {
            try {
                sembrarDemoMusicMad($db, DB_NAME, DB_HOST, true);
                self::fail('Concurrent seed accepted.');
            } catch (RuntimeException $error) {
                self::assertStringContainsString('en curso', $error->getMessage());
            }
        } finally {
            $other->prepare('SELECT RELEASE_LOCK(?)')->execute([$lock]);
        }
        try {
            sembrarDemoMusicMad($db, 'REPLACE_WITH_LOCAL_VALUE', DB_HOST, true);
            self::fail('Wrong confirmation accepted.');
        } catch (DomainException $error) {
            self::assertStringContainsString('Solo se permiten', $error->getMessage());
        }
    }
    public function testFailureHalfwayRollsBackUsersPostsAndRelationships(): void
    {
        $db = $this->qa();
        $this->requireEmptyDemo($db);
        $trigger = 'mm_qa_seed_' . bin2hex(random_bytes(6));
        $db->exec("CREATE TRIGGER `$trigger` BEFORE INSERT ON publicaciones FOR EACH ROW BEGIN IF NEW.titulo='Mi rincón musical · Leo Demo' AND NEW.texto LIKE 'Publicación ficticia para explorar MusicMad.%' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='QA demo interruption'; END IF; END");
        try {
            try {
                sembrarDemoMusicMad($db, DB_NAME, DB_HOST, true);
                self::fail('Injected failure did not stop seed.');
            } catch (PDOException $error) {
                self::assertSame('45000', $error->getCode());
            }
            $st = $db->prepare('SELECT COUNT(*) FROM usuarios WHERE email IN (?,?,?)');
            $st->execute(array_column(perfilesDemoMusicMad(), 'email'));
            self::assertSame(0, (int)$st->fetchColumn());
            self::assertFalse($db->inTransaction());
            // Advisory lock must also have been released after the database error.
            $result = sembrarDemoMusicMad($db, DB_NAME, DB_HOST, false);
            self::fail('Opt-in was ignored.');
        } catch (DomainException $error) {
            self::assertStringContainsString('Solo se permiten', $error->getMessage());
        } finally {
            $db->exec("DROP TRIGGER IF EXISTS `$trigger`");
            $this->cleanup($db);
        }
        $lock = 'musicmad_demo_' . substr(hash('sha256',DB_NAME),0,32);
        $st = $db->prepare('SELECT IS_FREE_LOCK(?)');
        $st->execute([$lock]);
        self::assertSame(1,(int)$st->fetchColumn());
    }
}
