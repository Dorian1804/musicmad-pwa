<?php

use PHPUnit\Framework\TestCase;

final class EmailPolicyTest extends TestCase
{
    public function testRejectsFakeReservedDisposableAndMalformedWithoutDns(): void
    {
        $dns = static function (): never {
            throw new LogicException('No DNS allowed for rejected addresses');
        };
        foreach (['bad', "a\r\nb@gmail.com", 'music@example.com', 'music@sub.example.org',
            'music@ejemplo.com', 'music@local.invalid', 'music@localhost', 'asdf@gmail.com',
            'aaaa@gmail.com', 'qwer12@gmail.com', 'music@mailinator.com', 'music@sub.yopmail.fr'] as $email) {
            self::assertFalse(evaluarCorreoRegistro($email, $dns)['ok'], $email);
        }
    }

    public function testTypoSuggestsCorrectionAndPreservesValidAddress(): void
    {
        self::assertStringContainsString('cantante@gmail.com', evaluarCorreoRegistro('cantante@gmial.com')['mensaje']);
        $calls = [];
        $result = evaluarCorreoRegistro('  Cantante+escena@Gmail.com  ', static function ($domain) use (&$calls) {
            $calls[] = $domain;
            return true;
        });
        self::assertTrue($result['ok']);
        self::assertSame('Cantante+escena@Gmail.com', $result['email']);
        self::assertSame(['gmail.com'], $calls);
    }

    public function testDnsRejectsNullMxNxDomainAndTransientFailure(): void
    {
        self::assertFalse(dominioCorreoRecibeMensajes('musicmad.net', static fn () => ['Status' => 3]));
        self::assertNull(dominioCorreoRecibeMensajes('musicmad.net', static fn () => null));
        self::assertNull(dominioCorreoRecibeMensajes('musicmad.net', static fn () => ['Status' => 2]));
        $count = 0;
        self::assertFalse(dominioCorreoRecibeMensajes('musicmad.net', static function () use (&$count) {
            $count++;
            return ['Status' => 0, 'Answer' => [['type' => 15, 'data' => '0 .']]];
        }));
        self::assertSame(1, $count);
    }

    public function testMxAndAFallbackAreBoundedAndTyped(): void
    {
        self::assertTrue(dominioCorreoRecibeMensajes('musicmad.net', static fn () => ['Status' => 0,
            'Answer' => [['type' => 15, 'data' => '10 mail.musicmad.net.']]]));
        $types = [];
        self::assertTrue(dominioCorreoRecibeMensajes('musicmad.net', static function ($domain, $type) use (&$types) {
            $types[] = $type;
            return ['Status' => 0, 'Answer' => $type === 'A' ? [['type' => 1, 'data' => '192.0.2.1']] : []];
        }));
        self::assertSame(['MX', 'A'], $types);
        self::assertFalse(dominioCorreoRecibeMensajes('musicmad.net', static fn () => ['Status' => 0, 'Answer' => []]));
        self::assertFalse(evaluarCorreoRegistro('musico@gmail.com', static fn () => false)['ok']);
        self::assertFalse(evaluarCorreoRegistro('musico@gmail.com', static fn () => null)['ok']);
    }
}
