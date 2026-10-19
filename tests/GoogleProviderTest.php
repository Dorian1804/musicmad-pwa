<?php

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/includes/google_provider.php';
require_once dirname(__DIR__) . '/includes/google_oauth_state.php';
final class GoogleProviderTest extends TestCase
{
    public function testValidCallbackSendsRestoredVerifierToTokenExchange(): void
    {
        $history = [];
        $stack = GuzzleHttp\HandlerStack::create(new GuzzleHttp\Handler\MockHandler([new GuzzleHttp\Psr7\Response(200, ['Content-Type' => 'application/json'], json_encode(['access_token' => 'fictitious-token','token_type' => 'Bearer','expires_in' => 3600]))]));
        $stack->push(GuzzleHttp\Middleware::history($history));
        $provider = new MusicMadGoogleProvider(['clientId' => 'qa-client.apps.invalid','clientSecret' => 'qa-placeholder','redirectUri' => 'https://fixture.invalid/google-regreso.php']);
        $provider->setHttpClient(new GuzzleHttp\Client(['handler' => $stack]));
        $provider->getAuthorizationUrl();
        $session = ['oauth2state' => $provider->getState(),'oauth_pkce' => $provider->getPkceCode(),'oauth_state_expires' => 2000];
        $expected = $session['oauth_pkce'];
        $verifier = consumirRetornoGoogle($session, ['state' => $session['oauth2state'],'code' => 'qa-code'], 1500);
        $provider->setPkceCode($verifier);
        $token = $provider->getAccessToken('authorization_code', ['code' => 'qa-code']);
        parse_str((string)$history[0]['request']->getBody(), $posted);
        self::assertSame($expected, $posted['code_verifier']);
        self::assertSame('qa-code', $posted['code']);
        self::assertSame('fictitious-token', $token->getToken());
        self::assertArrayNotHasKey('oauth_pkce', $session);
    }
    public function testAuthorizationUsesS256WithVerifierAndState(): void
    {
        $provider = new MusicMadGoogleProvider(['clientId' => 'qa-client.apps.invalid','clientSecret' => 'qa-placeholder','redirectUri' => 'https://fixture.invalid/google-regreso.php']);
        $url = $provider->getAuthorizationUrl(['scope' => ['openid','email','profile']]);
        parse_str(parse_url($url, PHP_URL_QUERY), $params);
        self::assertSame('S256', $params['code_challenge_method']);
        self::assertNotEmpty($params['state']);
        $verifier = $provider->getPkceCode();
        self::assertGreaterThanOrEqual(43, strlen($verifier));
        self::assertSame(rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='), $params['code_challenge']);
    }
}
