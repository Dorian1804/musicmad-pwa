<?php
use PHPUnit\Framework\TestCase;
require_once dirname(__DIR__).'/includes/navigation.php';
final class NavigationCatalogTest extends TestCase
{
    public function testCatalogueHasEveryReleasedSectionAndExactlySixDockItems(): void
    {
        $items = catalogoNavegacion(42);
        $ids = array_column($items,'id');
        self::assertCount(count(array_unique($ids)),$ids);
        foreach (['salas','clips','colabs','eventos','mercado','retos','bandas','configuracion','menu'] as $id) self::assertContains($id,$ids);
        self::assertSame(['feed','musicos','subir','mensajes','perfil','menu'],array_column(array_values(array_filter($items,static fn($item)=>!empty($item['dock']))),'id'));
        foreach ($items as $item) {
            self::assertFileExists(dirname(__DIR__).'/'.strtok($item['href'],'?#'));
            self::assertNotEmpty($item['label']);
            self::assertStringNotContainsString('<script',iconoNavegacion($item['icon']));
        }
    }
    public function testRoleFilterAndActiveChildSections(): void
    {
        self::assertNotContains('moderacion',array_column(navegacionPermitida(42,'usuario'),'id'));
        self::assertContains('moderacion',array_column(navegacionPermitida(42,'moderador'),'id'));
        self::assertNotContains('verificaciones',array_column(navegacionPermitida(42,'moderador'),'id'));
        self::assertContains('verificaciones',array_column(navegacionPermitida(42,'administrador'),'id'));
        foreach (['sala.php'=>'salas','evento.php'=>'eventos','banda.php'=>'bandas','colab.php'=>'colabs','clip.php'=>'clips','publicacion.php'=>'feed'] as $file=>$section) self::assertSame($section,seccionNavegacionActual('/MusicMad_PWA/'.$file));
        self::assertSame('',seccionNavegacionActual('inexistente.php'));
    }
    public function testReleasedSectionsIgnoreOldFeatureRolloutsWithoutEnablingUnknownFeatures(): void
    {
        $old=getenv('MM_FEATURE_FLAGS');
        putenv('MM_FEATURE_FLAGS={"salas":false,"clips":false,"estudio":false}');
        try {
            foreach (['salas','clips','estudio'] as $key) foreach ([0,42] as $uid) self::assertTrue(funcionalidadMusicMad($key,$uid));
            self::assertFalse(funcionalidadMusicMad('admin',42));
        } finally { if($old===false) putenv('MM_FEATURE_FLAGS'); else putenv('MM_FEATURE_FLAGS='.$old); }
    }
}
