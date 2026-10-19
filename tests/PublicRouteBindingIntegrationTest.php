<?php

declare(strict_types=1);

use MusicMad\Routing\PublicRouteBinding;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/includes/routing/public_binding.php';

final class PublicRouteBindingIntegrationTest extends TestCase
{
    private PDO $db;
    private int $user;
    private string $alias;

    protected function setUp(): void
    {
        if (getenv('MM_RUN_QA') !== '1') {
            $this->markTestSkipped('Solo QA aislada.');
        }
        self::assertSame('musicmad_qa', DB_NAME);
        $this->db = getDB();
        self::assertSame('musicmad_qa', $this->db->query('SELECT DATABASE()')->fetchColumn());
        $this->db->exec(file_get_contents(dirname(__DIR__) . '/config/migrations/036_public_routes.sql'));
        $this->db->beginTransaction();
        $this->alias = 'qa_' . bin2hex(random_bytes(6));
        $this->db->prepare('INSERT INTO usuarios(nombre,apellido,email,REPLACE_WITH_LOCAL_VALUE) VALUES(?,?,?,?)')->execute(['Public routing', 'QA', $this->alias . '@musicmad.invalid', 'synthetic-fixture-not-a-login-hash']);
        $this->user = (int) $this->db->lastInsertId();
        $this->db->prepare('INSERT INTO perfiles_publicos(usuario_id,slug,redes) VALUES(?,?,?)')->execute([$this->user, $this->alias, '{}']);
        $this->db->prepare("INSERT INTO musicmad_epk_options(usuario_id,habilitado,color) VALUES(?,1,'azul')")->execute([$this->user]);
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->inTransaction()) {
            $this->db->rollBack();
        }
    }

    public function testLegacyProfileQrAlwaysRedirectsToAnAnonymousEligibleAlias(): void
    {
        $binding = new PublicRouteBinding($this->db);
        foreach (['/', '/MusicMad_PWA/'] as $base) {
            $old = $binding->bind($base . 'vista-publica.php?tipo=perfil&id=' . $this->user, 'GET', $base);
            self::assertSame(200, $old['status']);
            self::assertSame(301, $old['redirect']);
            self::assertSame($base . '@' . $this->alias, $old['canonical']);
            $clean = $binding->bind($old['canonical'], 'GET', $base);
            self::assertSame(200, $clean['status']);
            self::assertNull($clean['redirect']);
            self::assertSame('portafolio', $clean['handler']);
            self::assertSame($this->alias, $clean['query']['usuario']);
            self::assertSame(['status' => 404], $binding->bind($old['canonical'] . '?usuario=otro_alias', 'GET', $base));
        }
        $this->db->prepare("INSERT INTO perfiles_privacidad(usuario_id,visibilidad) VALUES(?,'privado')")->execute([$this->user]);
        self::assertSame(['status' => 404], $binding->bind('/@' . $this->alias, 'GET'));
        self::assertSame(['status' => 404], $binding->bind('/portafolio.php?usuario=' . $this->alias, 'GET'));
        self::assertSame(['status' => 404], $binding->bind('/@no_existe_qa', 'GET'));
    }

    public function testBandQrUsesOneOpaqueAddressAndStopsWorkingWhenBandBecomesPrivate(): void
    {
        $band = crearBanda($this->user, ['nombre' => 'QR routing QA', 'genero' => 'Jazz', 'ciudad' => 'Guadalajara', 'instrumento' => 'Guitarra']);
        self::assertTrue($band['ok']);
        $id = (int) $band['id'];
        $binding = new PublicRouteBinding($this->db);
        $old = $binding->bind('/vista-publica.php?tipo=banda&id=' . $id, 'GET');
        self::assertSame(301, $old['redirect']);
        self::assertMatchesRegularExpression('~^/banda/[a-f0-9]{32}$~D', $old['canonical']);
        self::assertSame($old['canonical'], $binding->bind('/banda.php?id=' . $id, 'GET')['canonical']);
        $clean = $binding->bind($old['canonical'], 'GET');
        self::assertSame('vista-publica', $clean['handler']);
        self::assertSame(['tipo' => 'banda', 'id' => (string) $id], $clean['query']);
        self::assertNull($clean['redirect']);
        $this->db->prepare("INSERT INTO musicmad_band_settings(banda_id,ingreso) VALUES(?,'invitacion') ON DUPLICATE KEY UPDATE ingreso='invitacion'")->execute([$id]);
        self::assertSame(['status' => 404], $binding->bind($old['canonical'], 'GET'));
        self::assertSame(['status' => 404], $binding->bind('/banda.php?id=' . $id, 'GET'));
        self::assertSame(['status' => 404], $binding->bind('/banda/' . str_repeat('0', 32), 'GET'));
    }

    public function testEmbedIsOptInAndItsLegacyAliasDoesNotBypassRevocation(): void
    {
        $binding = new PublicRouteBinding($this->db);
        $old = '/embed.php?usuario=' . $this->alias;
        self::assertSame(['status' => 404], $binding->bind($old, 'GET'));
        $this->db->prepare('INSERT INTO musicmad_epk_embed(usuario_id,permitido) VALUES(?,1)')->execute([$this->user]);
        $allowed = $binding->bind($old, 'GET');
        self::assertSame(301, $allowed['redirect']);
        self::assertSame('/embed/@' . $this->alias, $allowed['canonical']);
        self::assertSame('embed', $binding->bind($allowed['canonical'], 'GET')['handler']);
        $this->db->prepare('UPDATE musicmad_epk_embed SET permitido=0 WHERE usuario_id=?')->execute([$this->user]);
        self::assertSame(['status' => 404], $binding->bind($allowed['canonical'], 'GET'));
    }

    public function testPublicTrackAddressCannotExposeAHiddenOrUnfeaturedTrack(): void
    {
        $this->db->prepare("INSERT INTO publicaciones(usuario_id,tipo,titulo) VALUES(?,'cancion','Routing audio QA')")->execute([$this->user]);
        $post = (int) $this->db->lastInsertId();
        $this->db->prepare('INSERT INTO publicacion_audio(publicacion_id,archivo,bytes) VALUES(?,?,1)')->execute([$post, 'routing_qa.wav']);
        $binding = new PublicRouteBinding($this->db);
        $old = '/pista-publica.php?id=' . $post;
        self::assertSame(['status' => 404], $binding->bind($old, 'GET'));
        $this->db->prepare('INSERT INTO musicmad_epk_featured(usuario_id,publicacion_id,orden) VALUES(?,?,1)')->execute([$this->user, $post]);
        $allowed = $binding->bind($old, 'GET');
        self::assertSame(301, $allowed['redirect']);
        self::assertMatchesRegularExpression('~^/medios/pista-publica/[a-f0-9]{32}$~D', $allowed['canonical']);
        $head = $binding->bind($allowed['canonical'], 'HEAD');
        self::assertSame('pista-publica', $head['handler']);
        self::assertSame((string) $post, $head['query']['id']);
        $this->db->prepare('UPDATE musicmad_epk_options SET habilitado=0 WHERE usuario_id=?')->execute([$this->user]);
        self::assertSame(['status' => 404], $binding->bind($allowed['canonical'], 'GET'));
    }
}
