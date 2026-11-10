<?php
use PHPUnit\Framework\TestCase;

/**
 * Cache-Control for responses that must come fresh from the server
 * (haxtheweb/issues#3244). Credentialed or token-bearing answers stay
 * no-store; answers to requests with no credentials use `private, no-cache`
 * so Chrome can keep the page in the back/forward cache. Same values as
 * NodeJS src/lib/responseCache.js.
 */
class HAXCMSResponseCacheTest extends TestCase
{
    private const NO_STORE = 'no-store, no-cache, must-revalidate, proxy-revalidate';
    private const REVALIDATE = 'private, no-cache, must-revalidate';
    private const SERVER_KEYS = array(
        'HTTP_AUTHORIZATION',
        'REDIRECT_HTTP_AUTHORIZATION',
        'HTTP_X_HAXCMS_USER_TOKEN',
        'HTTP_X_HAXCMS_SITE_TOKEN',
    );

    private $savedServer = array();
    private $savedCookie;

    protected function setUp(): void
    {
        foreach (self::SERVER_KEYS as $key) {
            $this->savedServer[$key] = array_key_exists($key, $_SERVER) ? $_SERVER[$key] : null;
            unset($_SERVER[$key]);
        }
        $this->savedCookie = $_COOKIE;
        $_COOKIE = array();
    }

    protected function tearDown(): void
    {
        foreach (self::SERVER_KEYS as $key) {
            if ($this->savedServer[$key] === null) {
                unset($_SERVER[$key]);
            } else {
                $_SERVER[$key] = $this->savedServer[$key];
            }
        }
        $_COOKIE = $this->savedCookie;
    }

    public function testAnonymousRequestIsRevalidatedNotNoStore(): void
    {
        $this->assertFalse(HAXCMS::requestCarriesCredentials());
        $this->assertSame(self::REVALIDATE, HAXCMS::uncachedCacheControl());
    }

    public function testSensitiveBodyIsAlwaysNoStore(): void
    {
        $this->assertSame(self::NO_STORE, HAXCMS::uncachedCacheControl(true));
    }

    public function testEachCredentialHeaderCounts(): void
    {
        foreach (self::SERVER_KEYS as $key) {
            $_SERVER[$key] = 'value';
            $this->assertTrue(HAXCMS::requestCarriesCredentials(), $key);
            $this->assertSame(self::NO_STORE, HAXCMS::uncachedCacheControl(), $key);
            unset($_SERVER[$key]);
        }
    }

    public function testBlankHeadersDoNotCount(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = '   ';
        $this->assertFalse(HAXCMS::requestCarriesCredentials());
    }

    public function testRefreshCookieCountsOtherCookiesDoNot(): void
    {
        $_COOKIE = array('theme' => 'dark');
        $this->assertFalse(HAXCMS::requestCarriesCredentials());
        $_COOKIE = array('haxcms_refresh_token' => '');
        $this->assertTrue(HAXCMS::requestCarriesCredentials());
        $this->assertSame(self::NO_STORE, HAXCMS::uncachedCacheControl());
    }
}
