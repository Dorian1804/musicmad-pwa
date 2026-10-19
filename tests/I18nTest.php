<?php
use PHPUnit\Framework\TestCase;
final class I18nTest extends TestCase
{
    public function testCataloguesHaveSameKeysAndPlaceholders(): void
    {
        $base=catalogoIdiomaMusicMad('es');self::assertCount(9,MUSICMAD_LOCALES);
        foreach(array_keys(MUSICMAD_LOCALES) as $locale) {
            $catalogue=catalogoIdiomaMusicMad($locale);self::assertSame(array_keys($base),array_keys($catalogue));
            foreach($base as $key=>$text) {
                self::assertNotEmpty($catalogue[$key]);
                preg_match_all('/\{[a-z]+\}/',$text,$expected);preg_match_all('/\{[a-z]+\}/',$catalogue[$key],$actual);
                sort($expected[0]);sort($actual[0]);self::assertSame($expected[0],$actual[0],$locale.':'.$key);
            }
        }
    }
    public function testNegotiationValidationAndFallbackNeverLoadInputPaths(): void
    {
        self::assertSame('pt-BR',negociarIdiomaMusicMad('xx;q=1, pt-PT;q=0.8,en;q=0.2'));
        self::assertSame('fr',negociarIdiomaMusicMad('de;q=0,fr;q=0.5,en;q=0.2'));
        self::assertSame('es',negociarIdiomaMusicMad('fr;q=2'));
        foreach(['../en','en.php','en%00','<script>',[],false] as $value)self::assertNull(normalizarIdiomaMusicMad($value));
        self::assertSame('Iniciar sesión',mmTexto('Iniciar sesión',[],'es'));
        self::assertSame('Log in',mmTexto('Iniciar sesión',[],'en'));
        self::assertSame('設定',mmTexto('Configuración',[],'ja'));
        self::assertSame('unknown.key',mmTexto('unknown.key',[],'de'));
        self::assertSame(catalogoIdiomaMusicMad('es'),catalogoIdiomaMusicMad('../../config/local.php'));
        foreach(array_keys(MUSICMAD_LOCALES) as $locale) {
            $mail=mmTexto('mail.body',['intro'=>'INTRO','link'=>'https://example.invalid/path','expiry'=>'TIME'], $locale);
            self::assertStringContainsString('https://example.invalid/path',$mail);self::assertStringNotContainsString('{',$mail);
        }
    }
    public function testAccountPreferenceIsBoundToOwnerAndExported(): void
    {
        if(getenv('MM_RUN_QA')!=='1')$this->markTestSkipped('Solo QA aislada.');
        self::assertSame('musicmad_qa',DB_NAME);getDB()->beginTransaction();
        try {
            guardarIdiomaCuenta(1,'ja');guardarIdiomaCuenta(2,'fr');self::assertSame('ja',idiomaCuentaMusicMad(1));self::assertSame('fr',idiomaCuentaMusicMad(2));
            self::assertSame('en',idiomaCuentaMusicMad(0,'en'));
            self::assertArrayHasKey('musicmad_user_locale',coleccionesExportacionCuenta());
            try{guardarIdiomaCuenta(1,'../../config/local.php');self::fail('Invalid locale accepted');}catch(InvalidArgumentException $error){self::assertNotEmpty($error->getMessage());}
        }finally{getDB()->rollBack();}
    }
    public function testEmailJobUsesRecipientLocaleWithoutSendingMail(): void
    {
        if(getenv('MM_RUN_QA')!=='1')$this->markTestSkipped('Solo QA aislada.');
        getDB()->beginTransaction();
        try {
            guardarIdiomaCuenta(1,'fr');$email=getDB()->query('SELECT email FROM usuarios WHERE id=1')->fetchColumn();
            self::assertTrue(solicitarCorreoCuenta($email,'verificar',false));
            $job=getDB()->query("SELECT payload,dedupe FROM musicmad_jobs WHERE usuario_id=1 AND tipo='correo' ORDER BY id DESC LIMIT 1")->fetch();
            $payload=json_decode(descifrarSecretoMusicMad($job['payload'],'jobs',$job['dedupe']),true,512,JSON_THROW_ON_ERROR);
            self::assertSame('Confirmez votre e-mail MusicMad',$payload['subject']);
            self::assertStringContainsString('Ce lien à usage unique',$payload['body']);
            self::assertStringContainsString('/confirmar-correo.php?token=',$payload['body']);
        }finally{getDB()->rollBack();}
    }
    public function testDynamicArtistTitlesAreNeverTranslated(): void
    {
        self::assertSame('Crear cuenta',tituloAplicacionIdioma('Crear cuenta','/musico.php','en'));
        self::assertSame('Configuración',tituloAplicacionIdioma('Configuración','/banda.php','ja'));
        self::assertSame('Create account',tituloAplicacionIdioma('Crear cuenta','/registro.php','en'));
        self::assertSame('設定',tituloAplicacionIdioma('Configuración','/configuracion.php','ja'));
    }
}
