<?php

use PHPUnit\Framework\TestCase;

final class AccountVerificationIntegrationTest extends TestCase
{
    private array $ids = [];
    private int $admin = 0;
    private int $artist = 0;
    private int $viewer = 0;
    private string $REPLACE_WITH_LOCAL_VALUE = 'MusicMad_Verification_QA_42!';
    private string $email = '';
    protected function setUp(): void
    {
        if (getenv('MM_RUN_QA') !== '1') {
            $this->markTestSkipped('Solo QA aislada.');
        }
        self::assertSame('musicmad_qa', DB_NAME);
        foreach (['administrador','cliente','cliente'] as $role) {
            $email = 'verification-' . bin2hex(random_bytes(8)) . '@musicmad.invalid';
            getDB()->prepare('INSERT INTO usuarios(nombre,apellido,email,REPLACE_WITH_LOCAL_VALUE,rol,bio) VALUES(?,?,?,?,?,?)')->execute(['Identidad','QA',$email,REPLACE_WITH_LOCAL_VALUE_hash($this->REPLACE_WITH_LOCAL_VALUE, PASSWORD_BCRYPT),$role,'Músico ficticio para revisar identidad de cuenta.']);
            $this->ids[] = (int)getDB()->lastInsertId();
            if (count($this->ids) === 2) {
                $this->email = $email;
            }
        }
        [$this->admin,$this->artist,$this->viewer] = $this->ids;
        getDB()->prepare('INSERT INTO perfiles_publicos(usuario_id,slug) VALUES(?,?)')->execute([$this->artist,'verified_' . bin2hex(random_bytes(6))]);
        getDB()->prepare('INSERT INTO perfiles_musicales(usuario_id,instrumento) VALUES(?,?)')->execute([$this->artist,'Guitarra']);
        $this->confirmEmail();
    }
    private function confirmEmail(): void
    {
        getDB()->prepare('INSERT INTO musicmad_email_verified(usuario_id,email_hash,verificado_en) VALUES(?,?,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE email_hash=VALUES(email_hash)')->execute([$this->artist,hashCorreoCuenta($this->email)]);
    }
    protected function tearDown(): void
    {
        if (getDB()->inTransaction()) {
            getDB()->rollBack();
        }
        foreach (array_reverse($this->ids) as $id) {
            getDB()->prepare('DELETE FROM usuarios WHERE id=?')->execute([$id]);
        }
    }
    private function approve(int $actor = 0, string $proof = 'https://artist.example/music'): void
    {
        revisarVerificacionArtistica($actor ?: $this->admin, $this->artist, 'conceder', $proof, 'identidad_artistica', true, $this->REPLACE_WITH_LOCAL_VALUE, '');
    }
    public function testExplicitStateRepeatAuditRevocationAndOwnExport(): void
    {
        self::assertFalse(cuentaConIdentidadRevisada($this->artist, $this->viewer));
        $this->approve();
        self::assertTrue(cuentaConIdentidadRevisada($this->artist, $this->viewer));
        $st = getDB()->prepare("SELECT COUNT(*) FROM auditoria_acciones WHERE usuario_id=? AND accion='verificacion_otorgada'");
        $st->execute([$this->admin]);
        self::assertSame(1, (int)$st->fetchColumn());
        $this->approve();
        $st->execute([$this->admin]);
        self::assertSame(1, (int)$st->fetchColumn());
        ob_start();
        renderInsigniaIdentidad($this->artist, $this->viewer);
        $html = ob_get_clean();
        self::assertStringContainsString('Identidad artística revisada manualmente', $html);
        self::assertStringContainsString('aria-hidden="true"', $html);
        $export = '';
        escribirExportacionCuenta($this->artist, function ($part) use (&$export) {
            $export .= $part;
        });
        $data = json_decode($export, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('concedida', $data['colecciones']['musicmad_verification_review'][0]['estado']);
        self::assertArrayNotHasKey('email_hash', $data['colecciones']['musicmad_verification_review'][0]);
        revisarVerificacionArtistica($this->admin, $this->artist, 'retirar', '', 'revision_pendiente', false, $this->REPLACE_WITH_LOCAL_VALUE, '');
        self::assertFalse(cuentaConIdentidadRevisada($this->artist, $this->viewer));
        $st = getDB()->prepare('SELECT verificado FROM perfiles_publicos WHERE usuario_id=?');
        $st->execute([$this->artist]);
        self::assertSame(0, (int)$st->fetchColumn());
    }
    public function testRoleFreshPasswordSelfAndProofAreRequired(): void
    {
        try {
            $this->approve($this->viewer);
            self::fail('Ordinary user approved artist.');
        } catch (OutOfBoundsException $e) {
            self::assertStringContainsString('Solo administración', $e->getMessage());
        }
        try {
            revisarVerificacionArtistica($this->admin, $this->artist, 'conceder', 'https://artist.example', 'identidad_artistica', true, 'wrong-REPLACE_WITH_LOCAL_VALUE', '');
            self::fail('No fresh credential check.');
        } catch (DomainException $e) {
            self::assertStringContainsString('confirmar tu acceso', $e->getMessage());
        }
        try {
            revisarVerificacionArtistica($this->admin, $this->admin, 'conceder', 'https://artist.example', 'identidad_artistica', true, $this->REPLACE_WITH_LOCAL_VALUE, '');
            self::fail('Self verification accepted.');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('otra cuenta', $e->getMessage());
        }
        foreach (['javascript:alert(1)','http://artist.example','https://secret:REPLACE_WITH_LOCAL_VALUE@artist.example','https://artist.example/with space'] as $url) {
            self::assertFalse(evidenciaVerificacionSegura($url));
            try {
                $this->approve(0, $url);
                self::fail('Unsafe proof accepted.');
            } catch (InvalidArgumentException $e) {
                self::assertStringContainsString('HTTPS', $e->getMessage());
            }
        }
        self::assertFalse(cuentaConIdentidadRevisada($this->artist, $this->viewer));
    }
    public function testEmailAliasPrivacyAndBlockAreRevalidatedAtReadTime(): void
    {
        $this->approve();
        self::assertTrue(cuentaConIdentidadRevisada($this->artist, $this->viewer));
        getDB()->prepare("INSERT INTO perfiles_privacidad(usuario_id,visibilidad) VALUES(?,'privado') ON DUPLICATE KEY UPDATE visibilidad='privado'")->execute([$this->artist]);
        self::assertFalse(cuentaConIdentidadRevisada($this->artist, $this->viewer));
        self::assertTrue(cuentaConIdentidadRevisada($this->artist, $this->artist));
        getDB()->prepare('INSERT INTO seguidores(seguidor_id,seguido_id) VALUES(?,?)')->execute([$this->viewer,$this->artist]);
        self::assertTrue(cuentaConIdentidadRevisada($this->artist, $this->viewer));
        getDB()->prepare("INSERT INTO usuarios_bloqueados(usuario_id,objetivo_id,modo) VALUES(?,?,'bloquear')")->execute([$this->artist,$this->viewer]);
        self::assertFalse(cuentaConIdentidadRevisada($this->artist, $this->viewer));
        getDB()->prepare('UPDATE perfiles_publicos SET slug=? WHERE usuario_id=?')->execute(['changed_' . bin2hex(random_bytes(6)),$this->artist]);
        self::assertFalse(cuentaConIdentidadRevisada($this->artist, $this->artist));
        $this->approve();
        self::assertTrue(cuentaConIdentidadRevisada($this->artist, $this->artist));
        getDB()->prepare('UPDATE usuarios SET email=? WHERE id=?')->execute(['changed-' . bin2hex(random_bytes(6)) . '@musicmad.invalid',$this->artist]);
        self::assertFalse(cuentaConIdentidadRevisada($this->artist, $this->artist));
    }
    public function testEmailConfirmationAndLegacyFlagDoNotAutomaticallyGrantIdentityBadge(): void
    {
        getDB()->prepare('UPDATE perfiles_publicos SET verificado=1 WHERE usuario_id=?')->execute([$this->artist]);
        self::assertFalse(cuentaConIdentidadRevisada($this->artist, $this->viewer));
        getDB()->prepare('DELETE FROM musicmad_email_verified WHERE usuario_id=?')->execute([$this->artist]);
        try {
            $this->approve();
            self::fail('Unconfirmed email approved.');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('confirmar su correo', $e->getMessage());
        }
        $this->confirmEmail();
        $this->approve();
        self::assertTrue(cuentaConIdentidadRevisada($this->artist, $this->viewer));
        getDB()->prepare('UPDATE usuarios SET activo=0 WHERE id=?')->execute([$this->artist]);
        self::assertFalse(cuentaConIdentidadRevisada($this->artist, $this->artist));
    }
    public function testVerificationBindingsArePresentInEveryDeliveryLayer(): void
    {
        $root = dirname(__DIR__);
        foreach (['includes/social.php' => 'account_verification.php','includes/account_controls.php' => "'verificacion_otorgada'",'includes/account_export.php' => "'musicmad_verification_review'",'includes/local_destination.php' => "'verificaciones.php'",'musico.php' => 'renderInsigniaIdentidad','js/navigation.js' => '|verificaciones)','sw.js' => 'CSS/verification.css','CSS/app.css' => '.identity-badge'] as $file => $binding) {
            self::assertStringContainsString($binding, file_get_contents($root . '/' . $file), $file);
        }
        preg_match("/assetVersion = '([0-9]+)'/",file_get_contents($root . '/includes/header.php'),$header);
        preg_match("/const VERSION = 'v([0-9]+)'/",file_get_contents($root . '/sw.js'),$worker);
        self::assertSame($header[1],$worker[1]);
    }
}
