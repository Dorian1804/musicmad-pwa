<?php
use PHPUnit\Framework\TestCase;
require_once dirname(__DIR__).'/includes/local_destination.php';
final class LocalDestinationTest extends TestCase
{
    public function testPreservesConversationPostAndFilters(): void
    {
        self::assertSame('mensajes.php?con=42&txt=Hola%20m%C3%BAsico',destinoLocalSeguro('mensajes.php?con=42&txt=Hola músico'));
        self::assertSame('publicacion.php?id=5#comentario-form-5',destinoLocalSeguro('publicacion.php?id=5#comentario-form-5'));
        self::assertSame('feed.php?genero=Rock&modo=siguiendo',destinoLocalSeguro('feed.php?genero=Rock&modo=siguiendo'));
    }
    public function testRejectsRemoteURLsPathsSecretsAndArrays(): void
    {
        foreach(['https://example.invalid','//example.invalid','../feed.php','logout.php','google-regreso.php?code=secret','feed.php\r\nLocation: elsewhere'] as $value)self::assertSame('feed.php',destinoLocalSeguro($value));
        self::assertSame('feed.php?q=solo',destinoLocalSeguro('feed.php?q=solo&csrf=secret&id[]=3&resource=users'));
    }
}
