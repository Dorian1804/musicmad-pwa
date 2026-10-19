<?php

require_once dirname(__DIR__) . '/vendor/autoload.php';
/** PKCE binds the authorization code to the browser that started this login. */
class MusicMadGoogleProvider extends League\OAuth2\Client\Provider\Google
{
    protected function getPkceMethod()
    {
        return self::PKCE_METHOD_S256;
    }
}
