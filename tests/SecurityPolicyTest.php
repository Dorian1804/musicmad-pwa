<?php
use PHPUnit\Framework\TestCase;

final class SecurityPolicyTest extends TestCase
{
    private function fixture(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec('CREATE TABLE perfiles_privacidad(usuario_id INTEGER,visibilidad TEXT);
            CREATE TABLE seguidores(seguidor_id INTEGER,seguido_id INTEGER);
            CREATE TABLE usuarios_bloqueados(usuario_id INTEGER,objetivo_id INTEGER,modo TEXT);
            CREATE TABLE publicaciones(id INTEGER,usuario_id INTEGER);
            CREATE TABLE reposts(publicacion_id INTEGER,usuario_id INTEGER);
            INSERT INTO publicaciones VALUES(1,1),(2,2),(3,3),(4,4),(5,5),(6,6),(7,7),(8,8),(9,9);
            INSERT INTO perfiles_privacidad VALUES(2,"privado"),(3,"privado"),(4,"seguidores"),(5,"seguidores");
            INSERT INTO seguidores VALUES(1,2),(1,4);
            INSERT INTO usuarios_bloqueados VALUES(1,6,"silenciar"),(1,7,"bloquear"),(8,1,"bloquear");
            INSERT INTO reposts VALUES(2,9),(3,9),(9,8);');
        return $db;
    }
    private function visible(PDO $db, bool $muted = true): array
    {
        [$sql,$params] = filtroAccesoContenido(1,'p.usuario_id','test',$muted);
        $st = $db->prepare('SELECT p.id FROM publicaciones p WHERE ' . $sql . ' ORDER BY p.id');
        $st->execute($params);
        return array_map('intval',$st->fetchAll(PDO::FETCH_COLUMN));
    }
    public function testApprovedPrivateProfilesAreVisibleAndBlocksAreBidirectional(): void
    {
        self::assertSame([1,2,4,9], $this->visible($this->fixture()));
    }
    public function testMuteCanBeExcludedForDirectAccessButBlockCannot(): void
    {
        self::assertSame([1,2,4,6,9], $this->visible($this->fixture(),false));
    }
    public function testRemovingFollowerRevokesPrivateAccess(): void
    {
        $db=$this->fixture(); $db->exec('DELETE FROM seguidores WHERE seguido_id=2');
        self::assertSame([1,4,9],$this->visible($db));
    }
    public function testRepostRequiresAccessToAuthorAndActor(): void
    {
        $db=$this->fixture();
        [$a,$ap]=filtroAccesoContenido(1,'p.usuario_id','original');
        [$b,$bp]=filtroAccesoContenido(1,'r.usuario_id','actor');
        $st=$db->prepare('SELECT p.id FROM reposts r JOIN publicaciones p ON p.id=r.publicacion_id WHERE (' . $a . ') AND (' . $b . ')');
        $st->execute($ap+$bp);
        self::assertSame([2],array_map('intval',$st->fetchAll(PDO::FETCH_COLUMN)));
    }
    public function testPolicyRejectsInjectedIdentifiers(): void
    {
        $this->expectException(InvalidArgumentException::class);
        filtroAccesoContenido(1,'p.usuario_id OR 1=1','test');
    }
    public function testFingerprintMigrationWritesDistinctKeysAndExpires(): void
    {
        $identity='test@example.invalid' . "\0" . '127.0.0.1';
        $new=str_repeat('n',64); $old=str_repeat('o',32);
        $during=huellasConClaves($identity,$new,$old);
        self::assertCount(2,$during);
        self::assertSame(hash_hmac('sha256',$identity,$new),$during[0]);
        self::assertSame(hash_hmac('sha256',$identity,$old),$during[1]);
        self::assertSame([$during[0]],huellasConClaves($identity,$new));
    }
    public function testLoginFingerprintNormalizesEmailAndDoesNotUseDbPassword(): void
    {
        $_SERVER['REMOTE_ADDR']='127.0.0.1';
        self::assertSame(huellasLogin(' USER@example.invalid '),huellasLogin('user@example.invalid'));
        self::assertSame(hash_hmac('sha256','user@example.invalid' . "\0" . '127.0.0.1',(string)getenv('MM_ENC_KEY')),huellasLogin('user@example.invalid')[0]);
    }
    public function testCspForbidsInlineAndRemoteWildcardImages(): void
    {
        self::assertStringContainsString("style-src 'self';",musicmadCsp());
        self::assertStringNotContainsString('unsafe-inline',musicmadCsp());
        self::assertStringContainsString("img-src 'self' data: blob: https://tile.openstreetmap.org;",musicmadCsp());
        self::assertStringNotContainsString('https:;',musicmadCsp());
    }
    public function testPrivateMediaAndSupportFoldersAreDeniedByApache(): void
    {
        $root=dirname(__DIR__);
        foreach(['audio','videos','partituras'] as $folder) {
            self::assertStringContainsString('Require all denied',file_get_contents($root.'/uploads/'.$folder.'/.htaccess'));
        }
        $apache=file_get_contents($root.'/.htaccess');
        self::assertStringContainsString('includes|storage|bin|tests|', $apache);
        self::assertStringContainsString('[.]github', $apache);
        self::assertStringNotContainsString('unsafe-inline',$apache);
    }
    public function testApplicationTemplatesContainNoInlineStylesOrScripts(): void
    {
        $root=dirname(__DIR__);
        $paths=array_merge(glob($root.'/*.php'),glob($root.'/includes/*.php'),[$root.'/offline.html',$root.'/config/database.php']);
        foreach($paths as $path) {
            $source=file_get_contents($path);
            self::assertDoesNotMatchRegularExpression('/\\sstyle\\s*=/i',$source,basename($path));
            self::assertDoesNotMatchRegularExpression('/<script(?![^>]*\\bsrc=)[^>]*>/i',$source,basename($path));
            self::assertDoesNotMatchRegularExpression('/<style\\b/i',$source,basename($path));
        }
    }
    public function testPublicMapLibrariesAreOutsidePrivateApacheRules():void
    {
        $root=dirname(__DIR__);$apache=file_get_contents($root.'/.htaccess');
        self::assertStringContainsString('RewriteRule ^(config|vendor|includes|storage|bin|tests|',$apache);
        self::assertStringNotContainsString('RedirectMatch 404 /(config|vendor',$apache);
        foreach(['config','vendor','includes','bin'] as $folder)self::assertStringContainsString('Require all denied',file_get_contents($root.'/'.$folder.'/.htaccess'));
        self::assertFileExists($root.'/assets/vendor/leaflet/leaflet.js');self::assertFileExists($root.'/assets/vendor/leaflet/leaflet.css');
    }
}
