<?php

use PHPUnit\Framework\TestCase;

final class RequiredEmailIntegrationTest extends TestCase
{
    private array $users = [];
    private string $REPLACE_WITH_LOCAL_VALUE = 'MusicMad_Verification_QA_42!';
    private array $oldEnv = [];

    protected function setUp(): void
    {
        if (getenv('MM_RUN_QA') !== '1') {
            $this->markTestSkipped('Solo base QA aislada.');
        }
        self::assertSame('musicmad_qa', DB_NAME);
        foreach (['HOST' => 'localhost', 'FROM' => 'qa@musicmad.invalid', 'PORT' => '587', 'ENCRYPTION' => 'tls'] as $name => $value) {
            $this->oldEnv[$name] = getenv('MM_MAIL_' . $name);
            putenv('MM_MAIL_' . $name . '=' . $value);
        }
    }

    protected function tearDown(): void
    {
        if (getDB()->inTransaction()) {
            getDB()->rollBack();
        }
        foreach ($this->users as $uid) {
            getDB()->prepare('DELETE FROM usuarios WHERE id=?')->execute([$uid]);
        }
        foreach ($this->oldEnv as $name => $value) {
            putenv('MM_MAIL_' . $name . ($value === false ? '' : '=' . $value));
        }
    }

    private function account(): int
    {
        getDB()->prepare('INSERT INTO usuarios(nombre,apellido,email,REPLACE_WITH_LOCAL_VALUE) VALUES(?,?,?,?)')
            ->execute(['Verification', 'QA', 'musico-' . bin2hex(random_bytes(8)) . '@musicmad.invalid', REPLACE_WITH_LOCAL_VALUE_hash($this->REPLACE_WITH_LOCAL_VALUE, PASSWORD_BCRYPT)]);
        $this->users[] = $uid = (int) getDB()->lastInsertId();
        return $uid;
    }

    private function scalar(string $sql, array $parameters): mixed
    {
        $st = getDB()->prepare($sql);
        $st->execute($parameters);
        return $st->fetchColumn();
    }

    public function testRegistrationQueuesOneEncryptedLinkAndCannotChooseRole(): void
    {
        $email = 'cantante-' . bin2hex(random_bytes(8)) . '@musicmad-qa.net';
        $result = registrarUsuario(['nombre' => 'Registro', 'apellido' => 'QA', 'email' => $email,
            'REPLACE_WITH_LOCAL_VALUE' => $this->REPLACE_WITH_LOCAL_VALUE, 'confirm' => $this->REPLACE_WITH_LOCAL_VALUE, 'rol' => 'administrador'], static fn() => true);
        self::assertTrue($result['ok'], $result['mensaje']);
        $this->users[] = $uid = $result['id'];
        self::assertSame('cliente', $this->scalar('SELECT rol FROM usuarios WHERE id=?', [$uid]));
        self::assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM musicmad_jobs WHERE usuario_id=?', [$uid]));
        self::assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM musicmad_email_enrollment WHERE usuario_id=? AND vence_en=DATE_ADD(iniciada_en,INTERVAL 7 DAY)', [$uid]));
        self::assertSame('verificar-correo.php', destinoAccesoMusicMad($uid, 'publicacion.php?id=9'));
        self::assertFalse(correoCuentaVerificado($uid));
        $st = getDB()->prepare('SELECT payload,dedupe FROM musicmad_jobs WHERE usuario_id=?');
        $st->execute([$uid]);
        $job = $st->fetch();
        self::assertStringNotContainsString('token=', $job['payload']);
        $payload = json_decode(descifrarSecretoMusicMad($job['payload'], 'jobs', $job['dedupe']), true);
        preg_match('/token=([a-f0-9]{64})/', $payload['body'], $match);
        self::assertTrue(consumirTokenCorreo(hash('sha256', $match[1]), 'verificar'));
        self::assertFalse(consumirTokenCorreo(hash('sha256', $match[1]), 'verificar'));
        self::assertSame('bienvenida.php', destinoAccesoMusicMad($uid));
    }

    public function testLegacyDeadlineStartsOnEnrollmentAndNeverResetsOnRepeatedLogin(): void
    {
        $uid = $this->account();
        self::assertFalse($this->scalar('SELECT usuario_id FROM musicmad_email_enrollment WHERE usuario_id=?', [$uid]));
        iniciarPlazoVerificacion($uid);
        getDB()->prepare('UPDATE musicmad_email_enrollment SET vence_en=DATE_SUB(vence_en,INTERVAL 3 DAY) WHERE usuario_id=?')->execute([$uid]);
        $deadline = $this->scalar('SELECT vence_en FROM musicmad_email_enrollment WHERE usuario_id=?', [$uid]);
        iniciarPlazoVerificacion($uid);
        self::assertSame($deadline, $this->scalar('SELECT vence_en FROM musicmad_email_enrollment WHERE usuario_id=?', [$uid]));
        getDB()->prepare('UPDATE usuarios SET email=? WHERE id=?')->execute(['changed@musicmad.invalid', $uid]);
        iniciarPlazoVerificacion($uid);
        self::assertGreaterThan($deadline, $this->scalar('SELECT vence_en FROM musicmad_email_enrollment WHERE usuario_id=?', [$uid]));
    }

    public function testGoogleRequiresTrueClaimAndMatchingAccountEmail(): void
    {
        $uid = $this->account();
        $email = $this->scalar('SELECT email FROM usuarios WHERE id=?', [$uid]);
        foreach ([[false, $email], [true, 'another@musicmad.invalid']] as [$verified, $address]) {
            try {
                marcarCorreoGoogleVerificado($uid, $address, $verified);
                self::fail('Invalid Google claim accepted');
            } catch (InvalidArgumentException $error) {
                self::assertFalse(correoCuentaVerificado($uid));
            }
        }
        marcarCorreoGoogleVerificado($uid, $email, true);
        self::assertTrue(correoCuentaVerificado($uid));
    }

    public function testDeadlineOnlyRetiresExpiredUnverifiedAndUsesExistingPurge(): void
    {
        $expired = $this->account();
        $fresh = $this->account();
        $verified = $this->account();
        $legacy = $this->account();
        foreach ([$expired, $fresh, $verified] as $uid) {
            iniciarPlazoVerificacion($uid);
        }
        getDB()->prepare('UPDATE musicmad_email_enrollment SET vence_en=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 SECOND) WHERE usuario_id IN (?,?)')->execute([$expired, $verified]);
        marcarCorreoGoogleVerificado($verified, $this->scalar('SELECT email FROM usuarios WHERE id=?', [$verified]), true);
        self::assertSame(1, vencerCuentasSinVerificar(10));
        self::assertSame(0, (int) $this->scalar('SELECT activo FROM usuarios WHERE id=?', [$expired]));
        foreach ([$fresh, $verified, $legacy] as $uid) {
            self::assertSame(1, (int) $this->scalar('SELECT activo FROM usuarios WHERE id=?', [$uid]));
        }
        purgarCuentasSolicitadas(10, 5);
        self::assertFalse($this->scalar('SELECT id FROM usuarios WHERE id=?', [$expired]));
    }

    public function testMigrationCanBeImportedTwiceWithoutEnrollingOrDeletingLegacy(): void
    {
        $uid = $this->account();
        $sql = file_get_contents(dirname(__DIR__) . '/config/migrations/032_required_email.sql');
        getDB()->exec($sql);
        getDB()->exec($sql);
        self::assertSame(1, (int) $this->scalar('SELECT activo FROM usuarios WHERE id=?', [$uid]));
        self::assertFalse($this->scalar('SELECT usuario_id FROM musicmad_email_enrollment WHERE usuario_id=?', [$uid]));
    }
}
