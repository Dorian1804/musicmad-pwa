<?php
use PHPUnit\Framework\TestCase;
final class SchemaAvailabilityTest extends TestCase
{
    public function testOnlyClassicPublicSchemaCanDisableOptionalModule(): void
    {
        self::assertFalse(validarEstadoEsquemaSocial3([],false));
        self::assertTrue(validarEstadoEsquemaSocial3(TABLAS_SOCIAL3,true));
    }
    public function testPartialPrivacySchemaNeverFallsBackToPublic(): void
    {
        $this->expectException(RuntimeException::class);validarEstadoEsquemaSocial3(['perfiles_privacidad'],false);
    }
    public function testMarkerWithoutTablesCannotBypassPolicies(): void
    {
        $this->expectException(RuntimeException::class);validarEstadoEsquemaSocial3(array_diff(TABLAS_SOCIAL3,['usuarios_bloqueados']),true);
    }
}
