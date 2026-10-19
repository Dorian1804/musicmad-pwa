<?php

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/includes/email_account.php';
final class EmailIntegrationTest extends TestCase
{
    private int $uid = 0;
    private string $email = '';
    private string $base = '';
    protected function setUp(): void
    {
        if (getenv('MM_RUN_QA') !== '1') {
            $this->markTestSkipped('Solo QA aislada.');
        }
        self::assertSame('musicmad_qa', DB_NAME);
        $this->base = (string)getenv('MM_APP_URL');
        putenv('MM_APP_URL=http://127.0.0.1:8765');
        $this->email = 'mail-' . bin2hex(random_bytes(6)) . '@musicmad.invalid';
        getDB()->prepare('INSERT INTO usuarios(nombre,apellido,email,REPLACE_WITH_LOCAL_VALUE,rol) VALUES(?,?,?,?,?)')->execute(['Mail','QA',$this->email,REPLACE_WITH_LOCAL_VALUE_hash('MusicMad_mail_QA_42!', PASSWORD_BCRYPT),'cliente']);
        $this->uid = (int)getDB()->lastInsertId();
    }
    protected function tearDown(): void
    {
        if ($this->uid) {
            getDB()->prepare('DELETE FROM usuarios WHERE id=?')->execute([$this->uid]);
        }
        putenv('MM_APP_URL=' . $this->base);
    }
    private function queuedToken(string $type): array
    {
        self::assertTrue(solicitarCorreoCuenta($this->email, $type, false));
        $st = getDB()->prepare('SELECT dedupe,payload FROM musicmad_jobs WHERE usuario_id=? ORDER BY id DESC LIMIT 1');
        $st->execute([$this->uid]);
        $job = $st->fetch();
        $payload = json_decode(descifrarSecretoMusicMad($job['payload'], 'jobs', $job['dedupe']), true);
        self::assertStringNotContainsString('token=', (string)$job['payload']);
        preg_match('/token=([a-f0-9]{64})/', $payload['body'], $match);
        self::assertCount(2, $match);
        return [hash('sha256', $match[1]),$job['dedupe']];
    }
    public function testVerificationTokenSingleUseAndEmailBound(): void
    {
        [$hash] = $this->queuedToken('verificar');
        self::assertFalse(correoCuentaVerificado($this->uid));
        self::assertTrue(consumirTokenCorreo($hash, 'verificar'));
        self::assertTrue(correoCuentaVerificado($this->uid));
        self::assertFalse(consumirTokenCorreo($hash, 'verificar'));
        getDB()->prepare('UPDATE usuarios SET email=? WHERE id=?')->execute(['changed-' . $this->email,$this->uid]);
        self::assertFalse(correoCuentaVerificado($this->uid));
    }
    public function testResetRevokesTokensAndChangesPassword(): void
    {
        getDB()->prepare('INSERT INTO tokens_api(usuario_id,jti_hash,expira_en) VALUES(?,?,DATE_ADD(NOW(),INTERVAL 1 HOUR))')->execute([$this->uid,hash('sha256', 'mail QA token')]);
        $id = (int)getDB()->lastInsertId();
        [$hash] = $this->queuedToken('recuperar');
        self::assertTrue(consumirTokenCorreo($hash, 'recuperar', 'MusicMad_New_mail_QA_42!'));
        self::assertFalse(consumirTokenCorreo($hash, 'recuperar', 'MusicMad_Another_QA_42!'));
        $st = getDB()->prepare('SELECT REPLACE_WITH_LOCAL_VALUE FROM usuarios WHERE id=?');
        $st->execute([$this->uid]);
        self::assertTrue(REPLACE_WITH_LOCAL_VALUE_verify('MusicMad_New_mail_QA_42!', $st->fetchColumn()));
        $st = getDB()->prepare('SELECT revocado_en FROM tokens_api WHERE id=?');
        $st->execute([$id]);
        self::assertNotEmpty($st->fetchColumn());
    }
    public function testExpiredAndSupersededTokensDoNotWork(): void
    {
        [$old] = $this->queuedToken('recuperar');
        [$new] = $this->queuedToken('recuperar');
        self::assertNull(contextoTokenCorreo($old, 'recuperar'));
        getDB()->prepare('UPDATE email_tokens SET expira_en=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 SECOND) WHERE token_hash=?')->execute([$new]);
        self::assertNull(contextoTokenCorreo($new, 'recuperar'));
    }
    public function testChangingEmailInvalidatesOutstandingToken(): void
    {
        [$hash] = $this->queuedToken('verificar');
        getDB()->prepare('UPDATE usuarios SET email=? WHERE id=?')->execute(['changed-' . $this->email,$this->uid]);
        self::assertNull(contextoTokenCorreo($hash, 'verificar'));
    }
    public function testUnknownAccountDoesNotCreateAJob(): void
    {
        self::assertFalse(solicitarCorreoCuenta('not-present@musicmad.invalid','recuperar',false));
    }
}
