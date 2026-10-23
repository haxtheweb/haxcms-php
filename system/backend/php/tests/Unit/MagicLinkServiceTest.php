<?php
use PHPUnit\Framework\TestCase;

/**
 * One-time sign-in links (haxtheweb/issues#2854): MagicLinkService (the
 * _config/settings/magicLinks.json store) and the session/magic-link route.
 * Parity with haxcms-nodejs test/unit/magic-links.test.cjs.
 *
 * HAXCMS is a constructor-less subclass (the real constructor is stateful)
 * whose setRefreshTokenCookie records instead of calling setcookie(), so no
 * headers are sent. The route runs with HAXCMS_MAGIC_LINK_TEST_CAPTURE set so
 * it returns its response instead of printing + exit().
 */
class MagicLinkServiceTest extends TestCase
{
    private $haxcms;
    private $tmpConfigDir;
    private $savedServer;
    private $savedGet;
    private $savedGlobal;

    protected function setUp(): void
    {
        $this->haxcms = new class extends HAXCMS {
            public $cookies = array();
            public $cache = null;
            public function __construct() {}
            public function setRefreshTokenCookie($value, $expires = null)
            {
                $this->cookies[] = array('value' => (string) $value, 'expires' => $expires);
                return true;
            }
        };
        $this->haxcms->privateKey = 'pk';
        $this->haxcms->refreshPrivateKey = 'rpk';
        $this->haxcms->salt = 's';
        $this->haxcms->config = new stdClass();
        $this->haxcms->user = new stdClass();
        $this->haxcms->user->name = 'alice';
        $this->haxcms->superUser = new stdClass();
        $this->haxcms->superUser->name = 'admin';
        $this->haxcms->basePath = '/';
        $this->tmpConfigDir = sys_get_temp_dir() . '/haxcms_magic_' . uniqid();
        mkdir($this->tmpConfigDir . '/settings', 0777, true);
        $this->haxcms->configDirectory = $this->tmpConfigDir;
        $this->savedServer = $_SERVER;
        $this->savedGet = $_GET;
        $this->savedGlobal = isset($GLOBALS['HAXCMS']) ? $GLOBALS['HAXCMS'] : null;
        $GLOBALS['HAXCMS'] = $this->haxcms;
        $GLOBALS['HAXCMS_MAGIC_LINK_TEST_CAPTURE'] = true;
        $_SERVER['SERVER_SOFTWARE'] = 'phpunit-test';
        $_SERVER['HTTP_HOST'] = 'cms.example.org';
        $_SERVER['HTTPS'] = 'on';
        $_SERVER['REMOTE_ADDR'] = '203.0.113.' . random_int(1, 250);
        unset($_SERVER['HTTP_ORIGIN'], $_SERVER['HTTP_REFERER'], $_SERVER['HTTP_ACCEPT'], $_SERVER['CONTENT_TYPE']);
        $_GET = array();
    }

    protected function tearDown(): void
    {
        putenv('HAXCMS_MAGIC_LINK_DISABLED');
        putenv('HAXCMS_MAGIC_LINK_ENABLED');
        $_SERVER = $this->savedServer;
        $_GET = $this->savedGet;
        $GLOBALS['HAXCMS'] = $this->savedGlobal;
        unset($GLOBALS['HAXCMS_MAGIC_LINK_TEST_CAPTURE']);
        $this->rrmdir($this->tmpConfigDir);
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->rrmdir($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function store(): array
    {
        $raw = json_decode(file_get_contents(MagicLinkService::getStorePath($this->haxcms)), true);
        return $raw['links'];
    }

    // ---------- store ----------

    public function testIssueStoresOnlyAHashOfTheVerifier(): void
    {
        $issued = MagicLinkService::issue($this->haxcms);
        $this->assertSame('alice', $issued['user']);
        $this->assertTrue(MagicLinkService::isWellFormedToken($issued['token']));
        $verifier = explode('.', $issued['token'])[1];
        $raw = file_get_contents(MagicLinkService::getStorePath($this->haxcms));
        $this->assertStringNotContainsString($verifier, $raw);
        $record = $this->store()[$issued['selector']];
        $this->assertSame('cli', $record['channel']);
        $this->assertSame(MagicLinkService::DEFAULT_TTL_SECONDS, $record['expiresAt'] - $record['issuedAt']);
        $this->assertSame(0600, fileperms(MagicLinkService::getStorePath($this->haxcms)) & 0777);
    }

    public function testConsumeWorksExactlyOnce(): void
    {
        $issued = MagicLinkService::issue($this->haxcms);
        $first = MagicLinkService::consume($this->haxcms, $issued['token']);
        $this->assertTrue($first['ok']);
        $this->assertSame('alice', $first['user']);
        $replay = MagicLinkService::consume($this->haxcms, $issued['token']);
        $this->assertFalse($replay['ok']);
        $this->assertSame('not_found', $replay['reason']);
    }

    public function testExpiredLinkIsRejectedAndRemoved(): void
    {
        $nowMs = 1700000000000;
        $issued = MagicLinkService::issue($this->haxcms, array('ttlSeconds' => 60, 'now' => $nowMs));
        $result = MagicLinkService::consume($this->haxcms, $issued['token'], $nowMs + 61000);
        $this->assertSame('expired', $result['reason']);
        $this->assertArrayNotHasKey($issued['selector'], $this->store());
    }

    public function testWrongVerifierDoesNotBurnTheRealLink(): void
    {
        $issued = MagicLinkService::issue($this->haxcms);
        $forged = $issued['selector'] . '.' . str_repeat('A', 43);
        $this->assertSame('mismatch', MagicLinkService::consume($this->haxcms, $forged)['reason']);
        $this->assertTrue(MagicLinkService::consume($this->haxcms, $issued['token'])['ok']);
    }

    public function testMalformedTokensNeverTouchTheStore(): void
    {
        foreach (array('', 'nodot', 'a.b', '../../etc.passwd', str_repeat('x', 20) . '.' . str_repeat('y', 40) . '.z', null, 42) as $bad) {
            $this->assertSame('malformed', MagicLinkService::consume($this->haxcms, $bad)['reason']);
        }
        $this->assertFileDoesNotExist(MagicLinkService::getStorePath($this->haxcms));
    }

    public function testNewLinkRevokesEarlierOnesByDefault(): void
    {
        $first = MagicLinkService::issue($this->haxcms);
        $second = MagicLinkService::issue($this->haxcms);
        $this->assertFalse(MagicLinkService::consume($this->haxcms, $first['token'])['ok']);
        $this->assertTrue(MagicLinkService::consume($this->haxcms, $second['token'])['ok']);
    }

    public function testMaxOutstandingWhenNotRevoking(): void
    {
        $this->haxcms->config->security = (object) array('magicLink' => (object) array('maxOutstanding' => 2));
        $a = MagicLinkService::issue($this->haxcms, array('revokeOutstanding' => false, 'now' => 1700000000000));
        $b = MagicLinkService::issue($this->haxcms, array('revokeOutstanding' => false, 'now' => 1700000001000));
        $c = MagicLinkService::issue($this->haxcms, array('revokeOutstanding' => false, 'now' => 1700000002000));
        $links = $this->store();
        $this->assertArrayNotHasKey($a['selector'], $links);
        $this->assertArrayHasKey($b['selector'], $links);
        $this->assertArrayHasKey($c['selector'], $links);
    }

    public function testRefusesUnknownUser(): void
    {
        $this->expectException(RuntimeException::class);
        MagicLinkService::issue($this->haxcms, array('user' => 'mallory'));
    }

    public function testSuperUserCanBeIssuedExplicitly(): void
    {
        $issued = MagicLinkService::issue($this->haxcms, array('user' => 'admin'));
        $this->assertSame('admin', MagicLinkService::consume($this->haxcms, $issued['token'])['user']);
    }

    public function testDisabledPolicyBlocksIssueAndConsume(): void
    {
        $issued = MagicLinkService::issue($this->haxcms);
        $this->haxcms->config->security = (object) array('magicLink' => (object) array('enabled' => false));
        $this->assertSame('disabled', MagicLinkService::consume($this->haxcms, $issued['token'])['reason']);
        $this->expectException(RuntimeException::class);
        MagicLinkService::issue($this->haxcms);
    }

    public function testEnvKillSwitchBeatsConfigInEveryForm(): void
    {
        $this->haxcms->config->security = (object) array('magicLink' => (object) array('enabled' => true));
        $issued = MagicLinkService::issue($this->haxcms);
        // process env (docker / php-fpm)
        putenv('HAXCMS_MAGIC_LINK_DISABLED=true');
        $this->assertFalse(MagicLinkService::isEnabled($this->haxcms));
        putenv('HAXCMS_MAGIC_LINK_DISABLED');
        // Apache SetEnv / nginx fastcgi_param
        $_SERVER['HAXCMS_MAGIC_LINK_DISABLED'] = 'yes';
        $this->assertFalse(MagicLinkService::isEnabled($this->haxcms));
        unset($_SERVER['HAXCMS_MAGIC_LINK_DISABLED']);
        // Apache SetEnv after the system/api rewrite
        $_SERVER['REDIRECT_HAXCMS_MAGIC_LINK_DISABLED'] = '1';
        $this->assertFalse(MagicLinkService::isEnabled($this->haxcms));
        $this->assertSame('disabled', MagicLinkService::consume($this->haxcms, $issued['token'])['reason']);
        unset($_SERVER['REDIRECT_HAXCMS_MAGIC_LINK_DISABLED']);
        $_SERVER['HAXCMS_MAGIC_LINK_DISABLED'] = '0';
        $this->assertTrue(MagicLinkService::isEnabled($this->haxcms), '"0" leaves it on');
    }

    public function testHaxiamIsOffByDefaultAndTenantConfigCannotEnableIt(): void
    {
        $this->haxcms->config->security = (object) array('magicLink' => (object) array('enabled' => true));
        $this->haxcms->config->iam = true;
        $this->assertTrue(MagicLinkService::isHaxiamDeployment($this->haxcms));
        $this->assertFalse(MagicLinkService::isEnabled($this->haxcms));
        unset($this->haxcms->config->iam);
        $this->haxcms->config->deploymentProfile = 'haxiam-managed';
        $this->assertFalse(MagicLinkService::isEnabled($this->haxcms));
        $this->expectException(RuntimeException::class);
        MagicLinkService::issue($this->haxcms);
    }

    public function testOperatorEnvEnablesHaxiamAndDisabledStillWins(): void
    {
        $this->haxcms->config->iam = true;
        // Apache SetEnv after the system/api rewrite
        $_SERVER['REDIRECT_HAXCMS_MAGIC_LINK_ENABLED'] = 'true';
        $this->assertTrue(MagicLinkService::isEnabled($this->haxcms));
        $issued = MagicLinkService::issue($this->haxcms);
        $this->assertTrue(MagicLinkService::consume($this->haxcms, $issued['token'])['ok']);
        putenv('HAXCMS_MAGIC_LINK_DISABLED=1');
        $this->assertFalse(MagicLinkService::isEnabled($this->haxcms));
    }

    public function testSingleAndSelfHostedMultisiteStayOn(): void
    {
        foreach (array(null, 'single-site', 'self-hosted-multi-site') as $profile) {
            if ($profile === null) {
                unset($this->haxcms->config->deploymentProfile);
            }
            else {
                $this->haxcms->config->deploymentProfile = $profile;
            }
            $this->assertTrue(MagicLinkService::isEnabled($this->haxcms), (string) $profile);
        }
    }

    public function testTtlIsClamped(): void
    {
        $this->assertSame(60, MagicLinkService::issue($this->haxcms, array('ttlSeconds' => 1))['ttlSeconds']);
        $this->assertSame(86400, MagicLinkService::issue($this->haxcms, array('ttlSeconds' => 999999))['ttlSeconds']);
    }

    public function testRevokeAndCorruptStoreFailClosed(): void
    {
        $issued = MagicLinkService::issue($this->haxcms);
        $this->assertSame(1, MagicLinkService::revoke($this->haxcms));
        $this->assertFalse(MagicLinkService::consume($this->haxcms, $issued['token'])['ok']);
        $again = MagicLinkService::issue($this->haxcms);
        file_put_contents(MagicLinkService::getStorePath($this->haxcms), '{not json');
        $this->assertFalse(MagicLinkService::consume($this->haxcms, $again['token'])['ok']);
    }

    public function testStoreFormatMatchesTheNodeBackend(): void
    {
        // a record shaped exactly like haxcms-nodejs writes it must redeem here
        $selector = 'abcdefghijklmnop';
        $verifier = str_repeat('Z', 43);
        file_put_contents(MagicLinkService::getStorePath($this->haxcms), json_encode(array('links' => array(
            $selector => array(
                'selector' => $selector,
                'verifierHash' => hash('sha256', $verifier),
                'user' => 'alice',
                'channel' => 'cli',
                'issuedAt' => time(),
                'expiresAt' => time() + 600,
                'usedAt' => null,
                'requestIp' => null,
                'requestUserAgent' => null,
            ),
        ))));
        $this->assertTrue(MagicLinkService::consume($this->haxcms, $selector . '.' . $verifier)['ok']);
    }

    // ---------- route ----------

    private function route($method, $body = array(), $rawBody = '', $apiBasePath = '/system/api/v1')
    {
        $handler = include dirname(__DIR__, 2) . '/lib/systemRoutes/v1/magicLink.php';
        $context = new stdClass();
        $context->method = $method;
        $context->body = $body;
        $context->rawBody = $rawBody;
        $context->routeSuffix = 'v1/session/magic-link';
        $context->apiBasePath = $apiBasePath;
        return $handler($context);
    }

    public function testRouteIsPublicInTheSpec(): void
    {
        $this->assertSame('public', SiteRouteUtils::getSystemApiRouteAuthPolicy('v1/session/magic-link', 'GET'));
        $this->assertSame('public', SiteRouteUtils::getSystemApiRouteAuthPolicy('v1/session/magic-link', 'POST'));
    }

    public function testGetRendersAFormAndDoesNotConsume(): void
    {
        $issued = MagicLinkService::issue($this->haxcms);
        $_GET['token'] = $issued['token'];
        $res = $this->route('GET');
        $this->assertSame(200, $res['status']);
        $this->assertStringContainsString('<form method="post">', $res['body']);
        $this->assertSame('no-store', $res['headers']['Cache-Control']);
        $this->assertSame('no-referrer', $res['headers']['Referrer-Policy']);
        $this->assertTrue(MagicLinkService::consume($this->haxcms, $issued['token'])['ok']);
    }

    public function testGetWithMalformedTokenIsGeneric400(): void
    {
        $_GET['token'] = '<script>';
        $res = $this->route('GET');
        $this->assertSame(400, $res['status']);
        $this->assertStringNotContainsString('<script>', $res['body']);
    }

    public function testFormPostSetsCookieAndRedirectsToTheAppRoot(): void
    {
        $issued = MagicLinkService::issue($this->haxcms);
        $res = $this->route('POST', array(), 'token=' . rawurlencode($issued['token']));
        $this->assertSame(303, $res['status']);
        $this->assertSame('/', $res['headers']['Location']);
        $cookie = end($this->haxcms->cookies);
        $this->assertNotSame('', $cookie['value']);
        $this->assertNotFalse($this->haxcms->decodeRefreshToken($cookie['value']));
    }

    public function testRedirectKeepsAHaxiamTenantPrefix(): void
    {
        $issued = MagicLinkService::issue($this->haxcms);
        $res = $this->route('POST', array(), 'token=' . rawurlencode($issued['token']), '/bto108/system/api/v1');
        $this->assertSame('/bto108/', $res['headers']['Location']);
    }

    public function testJsonPostReturnsAJwt(): void
    {
        $issued = MagicLinkService::issue($this->haxcms);
        $_SERVER['HTTP_ACCEPT'] = 'application/json';
        $res = $this->route('POST', array('token' => $issued['token']));
        $this->assertSame(200, $res['status']);
        $body = json_decode($res['body'], true);
        $decoded = $this->haxcms->decodeJWT($body['jwt']);
        $this->assertSame('alice', $decoded->user);
        $this->assertIsString($body['userToken'], 'request token returned like session/login');
    }

    public function testReplayIsGeneric401AndClearsTheCookie(): void
    {
        $issued = MagicLinkService::issue($this->haxcms);
        $_SERVER['HTTP_ACCEPT'] = 'application/json';
        $this->route('POST', array('token' => $issued['token']));
        $res = $this->route('POST', array('token' => $issued['token']));
        $this->assertSame(401, $res['status']);
        $this->assertSame('AUTH_TOKEN_INVALID', json_decode($res['body'], true)['data']['code']);
        $this->assertSame('', end($this->haxcms->cookies)['value']);
    }

    public function testDisabledRouteIs404ForBothMethodsAndConsumesNothing(): void
    {
        $issued = MagicLinkService::issue($this->haxcms);
        $_SERVER['REDIRECT_HAXCMS_MAGIC_LINK_DISABLED'] = '1';
        $_GET['token'] = $issued['token'];
        $get = $this->route('GET');
        $this->assertSame(404, $get['status']);
        $this->assertStringNotContainsString('<form', $get['body']);
        $post = $this->route('POST', array('token' => $issued['token']));
        $this->assertSame(404, $post['status']);
        $this->assertSame(array(), $this->haxcms->cookies);
        unset($_SERVER['REDIRECT_HAXCMS_MAGIC_LINK_DISABLED']);
        // a HAXiam tenant without the operator opt-in is the same 404
        $this->haxcms->config->iam = true;
        $this->assertSame(404, $this->route('POST', array('token' => $issued['token']))['status']);
        unset($this->haxcms->config->iam);
        $this->assertTrue(MagicLinkService::consume($this->haxcms, $issued['token'])['ok']);
    }

    public function testCrossOriginPostIsRefusedWithoutConsuming(): void
    {
        $issued = MagicLinkService::issue($this->haxcms);
        $_SERVER['HTTP_ORIGIN'] = 'https://evil.example';
        $_SERVER['HTTP_ACCEPT'] = 'application/json';
        $res = $this->route('POST', array('token' => $issued['token']));
        $this->assertSame(403, $res['status']);
        $this->assertTrue(MagicLinkService::consume($this->haxcms, $issued['token'])['ok']);
    }

    public function testCrossSiteSecFetchSiteIsRefusedWithoutConsuming(): void
    {
        $issued = MagicLinkService::issue($this->haxcms);
        $_SERVER['HTTP_SEC_FETCH_SITE'] = 'cross-site';
        $_SERVER['HTTP_ACCEPT'] = 'application/json';
        $this->assertSame(403, $this->route('POST', array('token' => $issued['token']))['status']);
        unset($_SERVER['HTTP_SEC_FETCH_SITE']);
        $this->assertTrue(MagicLinkService::consume($this->haxcms, $issued['token'])['ok']);
    }

    public function testHttpsBrowserBehindPlainHttpProxyIsAccepted(): void
    {
        // TLS terminated upstream: browser Origin is https, server sees http.
        // The shared origin check compares host only, so this must not 403.
        $issued = MagicLinkService::issue($this->haxcms);
        unset($_SERVER['HTTPS']);
        $_SERVER['HTTP_ORIGIN'] = 'https://cms.example.org';
        $_SERVER['HTTP_ACCEPT'] = 'application/json';
        $this->assertSame(200, $this->route('POST', array('token' => $issued['token']))['status']);
    }

    public function testSameOriginPostIsAccepted(): void
    {
        $issued = MagicLinkService::issue($this->haxcms);
        $_SERVER['HTTP_ORIGIN'] = 'https://cms.example.org';
        $_SERVER['HTTP_ACCEPT'] = 'application/json';
        $this->assertSame(200, $this->route('POST', array('token' => $issued['token']))['status']);
    }
}
