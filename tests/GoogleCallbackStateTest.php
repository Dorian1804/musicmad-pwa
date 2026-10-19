<?php

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/includes/google_oauth_state.php';
final class GoogleCallbackStateTest extends TestCase
{
    private function session(): array
    {
        return ['oauth2state' => str_repeat('a', 64),'oauth_pkce' => str_repeat('b', 64),'oauth_state_expires' => 1200,'recordar_sesion_oauth' => true,'other' => 'keep'];
    }
    public function testValidCallbackRestoresVerifierAndConsumesTheAttempt(): void
    {
        $session = $this->session();
        self::assertSame(str_repeat('b', 64), consumirRetornoGoogle($session, ['state' => str_repeat('a', 64),'code' => 'fictitious-code'], 1100));
        self::assertSame(['recordar_sesion_oauth' => true,'other' => 'keep'], $session);
        $this->expectException(DomainException::class);
        consumirRetornoGoogle($session, ['state' => str_repeat('a', 64),'code' => 'fictitious-code'], 1100);
    }
    public function testInvalidStateExpiredMissingCodeAndInvalidVerifierFailClosed(): void
    {
        foreach ([['state' => 'other','code' => 'x'],['state' => ['a'],'code' => 'x'],['state' => str_repeat('a', 64)],['state' => str_repeat('a', 64),'code' => ['x']],['state' => str_repeat('a', 64),'code' => str_repeat('x', 2049)]] as $query) {
            $session = $this->session();
            try {
                consumirRetornoGoogle($session, $query, 1100);
                self::fail('Invalid callback accepted');
            } catch (DomainException $error) {
                self::assertArrayNotHasKey('oauth_pkce', $session);
                self::assertArrayNotHasKey('oauth2state', $session);
            }
        }
        foreach (['expired','verifier'] as $case) {
            $session = $this->session();
            if ($case === 'expired') {
                $session['oauth_state_expires'] = 1000;
            } else {
                $session['oauth_pkce'] = 'bad';
            }
            try {
                consumirRetornoGoogle($session, ['state' => str_repeat('a', 64),'code' => 'x'], 1100);
                self::fail('Unsafe callback accepted');
            } catch (DomainException $error) {
                self::assertArrayNotHasKey('oauth_pkce', $session);
            }
        }
    }
    public function testCspAllowsOnlyGoogleAsAdditionalFormDestination(): void
    {
        self::assertStringContainsString("form-action 'self' https://accounts.google.com;", musicmadCsp());
        self::assertStringNotContainsString('https://*.google.com', musicmadCsp());
        self::assertStringContainsString(musicmadCsp(),file_get_contents(dirname(__DIR__) . '/.htaccess'));
    }
}
