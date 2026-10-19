<?php

use PHPUnit\Framework\TestCase;

final class PjaxFailureContractTest extends TestCase
{
    public function testUnexpectedFailuresCannotReturnSuccessfulPjaxResponses(): void
    {
        $oldSession = $_SESSION;
        try {
            foreach (['clip-accion.php', 'colab-accion.php', 'sala-accion.php'] as $file) {
                $source = file_get_contents(dirname(__DIR__) . '/' . $file);
                self::assertSame(1, preg_match('/catch\s*\(\s*Throwable\s+\$(\w+)\s*\)\s*\{([^}]+)\}/s', $source, $catch), $file);
                self::assertSame(1, preg_match('/echo\s+json_encode\((\[.*?\])\);/s', $source, $response), $file);
                $flash = null;
                $target = $destination = 'feed.php';
                $e = $error = new RuntimeException('Private diagnostic must not reach clients');
                // Execute only trusted source fragments from this repository, without booting an endpoint.
                eval($catch[2]);
                $payload = eval('return ' . $response[1] . ';');
                self::assertFalse($payload['ok'], $file);
                self::assertSame('error', $payload['flash'][0], $file);
                self::assertSame($_SESSION['flash'], $payload['flash'], $file);
                self::assertStringNotContainsString('Private diagnostic', json_encode($payload));
            }
        } finally {
            $_SESSION = $oldSession;
        }
    }
}

