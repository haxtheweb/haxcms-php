<?php
/**
 * Characterization tests for lib/systemRoutes/v1/integrations.php.
 *
 * Two layers:
 *   1. haxcmsResolveIntegrationsSiteToken(): $_SERVER header first, then a
 *      case-insensitive getallheaders() scan, then ''. Under plain CLI the
 *      composer getallheaders polyfill mirrors $_SERVER HTTP_* keys, so the
 *      loop is exercised by shaping $_SERVER directly.
 *   2. The route closure: body/$_GET/context-params merging into
 *      Operations->params/rawParams, auth-token stripping, site-token
 *      injection from the header, the three route branches (app-store
 *      literals, provider-search literal + regex, unknown 404) and the
 *      response tail (numeric status extraction, __failed message print,
 *      plain print). The real generateAppStore()/appStoreSearch() run against
 *      a configurable fake HAXCMS; assertions on their messages prove the
 *      merge + token-injection wiring actually reached the operation.
 *
 * DOCUMENTED NOT-IN-PROCESS-COVERED BLOCKS:
 *   - `print json_encode($response['__failed'])` (the __failed-without-message
 *     defensive rewrap): every real Operations __failed array carries a
 *     'message', so it is unreachable via the public seam.
 *   - appStoreSearch's SSRF + curl upstream path (live network); the route
 *     tests stop at the token/provider/API-key gates.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/OperationsTestHaxcms.php';
require_once __DIR__ . '/SystemRoutesIntegrationsHaxiamFixtures.php';

class SystemRoutesIntegrationsTest extends TestCase
{
    private $savedHaxcms;
    private $savedServer;
    private $savedGet;
    private $integrationsPath;
    private $tempConfigDir;

    protected function setUp(): void
    {
        $this->integrationsPath =
          dirname(__DIR__, 2) . '/lib/systemRoutes/v1/integrations.php';
        $this->savedHaxcms = isset($GLOBALS['HAXCMS']) ? $GLOBALS['HAXCMS'] : null;
        $this->savedServer = $_SERVER;
        $this->savedGet = $_GET;
        unset($_SERVER['HTTP_X_HAXCMS_SITE_TOKEN']);
        $_SERVER['SERVER_SOFTWARE'] = 'phpunit-test';
        $_GET = array();
        http_response_code(200);
        // Define the file-scoped helper; the closure is re-required per run.
        require_once $this->integrationsPath;
        $GLOBALS['HAXCMS'] = new RoutesIntegrationsHaxiamFakeHaxcms();
    }

    protected function tearDown(): void
    {
        if ($this->savedHaxcms !== null) {
            $GLOBALS['HAXCMS'] = $this->savedHaxcms;
        } else {
            unset($GLOBALS['HAXCMS']);
        }
        $_SERVER = $this->savedServer;
        $_GET = $this->savedGet;
        http_response_code(200);
        if ($this->tempConfigDir !== null && is_dir($this->tempConfigDir)) {
            $this->deleteRecursive($this->tempConfigDir);
            $this->tempConfigDir = null;
        }
    }

    private function deleteRecursive($dir)
    {
        foreach (glob($dir . '/*') as $entry) {
            if (is_dir($entry)) {
                $this->deleteRecursive($entry);
            } else {
                unlink($entry);
            }
        }
        rmdir($dir);
    }

    private function haxcms()
    {
        return $GLOBALS['HAXCMS'];
    }

    private function setSiteTokenHeader($value)
    {
        if ($value === null) {
            unset($_SERVER['HTTP_X_HAXCMS_SITE_TOKEN']);
        } else {
            $_SERVER['HTTP_X_HAXCMS_SITE_TOKEN'] = $value;
        }
    }

    private function runHandler(
        $routeSuffix,
        $body = array(),
        $get = array(),
        $routeParams = array()
    ) {
        $context = (new ReflectionClass('SystemApiRequestContext'))
          ->newInstanceWithoutConstructor();
        $context->apiBasePath = '/system/api';
        $context->routeSuffix = $routeSuffix;
        $context->method = 'GET';
        $context->body = $body;
        $context->params = $routeParams;
        $_GET = $get;
        $handler = require $this->integrationsPath;
        ob_start();
        $handler($context);
        $raw = ob_get_clean();
        return array(
            'raw' => $raw,
            'data' => json_decode($raw, true),
            'code' => http_response_code(),
        );
    }

    // ------------------------------------------------------------------
    // haxcmsResolveIntegrationsSiteToken
    // ------------------------------------------------------------------

    public function testResolveSiteTokenReturnsEmptyWithoutHeaderOrHeaders(): void
    {
        $this->assertSame('', haxcmsResolveIntegrationsSiteToken());
    }

    public function testResolveSiteTokenTrimsServerHeader(): void
    {
        $_SERVER['HTTP_X_HAXCMS_SITE_TOKEN'] = "  hdr-token \n";
        $this->assertSame('hdr-token', haxcmsResolveIntegrationsSiteToken());
    }

    public function testResolveSiteTokenStringCastsNonStringHeaderFromGetallheaders(): void
    {
        // A non-string $_SERVER value skips the first branch, so the
        // getallheaders() fallback (composer polyfill mirroring $_SERVER)
        // finds the header case-insensitively and the helper string-casts it.
        // The polyfill passes arrays through untouched, so the (string) cast
        // in the loop yields PHP's canonical 'Array'.
        $_SERVER['HTTP_X_HAXCMS_SITE_TOKEN'] = array('not', 'a', 'string');
        $this->assertSame('Array', haxcmsResolveIntegrationsSiteToken());
    }

    public function testResolveSiteTokenIgnoresNonMatchingGetallheadersEntries(): void
    {
        // getallheaders() scans run without a matching site-token entry, so
        // the loop body executes and the final '' return is reached.
        $_SERVER['HTTP_X_OTHER_HEADER'] = 'other-value';
        $this->assertSame('', haxcmsResolveIntegrationsSiteToken());
    }

    // ------------------------------------------------------------------
    // Route dispatch + response tail
    // ------------------------------------------------------------------

    public function testUnknownRouteReturns404Json(): void
    {
        $result = $this->runHandler('v1/integrations/bogus');
        $this->assertSame(404, $result['code']);
        $this->assertSame(
            array('status' => 404, 'message' => 'Unknown integrations route'),
            $result['data']
        );
    }

    public function testAppStoreRouteFailsWithoutSiteToken(): void
    {
        $result = $this->runHandler('v1/integrations/app-store');
        $this->assertSame(403, $result['code']);
        $this->assertSame(
            array(
                'status' => 403,
                'data' => array('message' => 'invalid request token'),
            ),
            $result['data']
        );
    }

    public function testAppStoreNonV1LiteralRouteAlsoDispatches(): void
    {
        $result = $this->runHandler('integrations/app-store');
        $this->assertSame(403, $result['code']);
        $this->assertSame(
            array(
                'status' => 403,
                'data' => array('message' => 'invalid request token'),
            ),
            $result['data']
        );
    }

    public function testProviderSearchLiteralRouteDispatchesAppStoreSearch(): void
    {
        $result = $this->runHandler(
            'v1/integrations/app-store/providers/:provider/search',
            array(),
            array(),
            array('provider' => 'nope')
        );
        $this->assertSame(400, $result['code']);
        $this->assertSame(
            array(
                'status' => 400,
                'message' => 'Unsupported app store provider',
            ),
            $result['data']
        );
    }

    public function testProviderSearchRegexRouteDispatchesAppStoreSearch(): void
    {
        // Non-v1 concrete form only matches the regex branch; provider comes
        // from the merged body params.
        $result = $this->runHandler(
            'integrations/app-store/providers/giphy/search',
            array('provider' => 'giphy')
        );
        $this->assertSame(403, $result['code']);
        $this->assertSame(
            array(
                'status' => 403,
                'message' => 'invalid request token',
            ),
            $result['data']
        );
    }

    public function testProviderSearchMergesGetBodyAndHeaderToken(): void
    {
        // provider via $_GET, siteName via body, site token via header: the
        // 'Missing API key' 400 proves all three reached appStoreSearch and
        // the token gate passed.
        $this->setSiteTokenHeader('site-token-123');
        $result = $this->runHandler(
            'v1/integrations/app-store/providers/youtube/search',
            array('siteName' => 'mysite'),
            array('provider' => 'youtube')
        );
        $this->assertSame(400, $result['code']);
        $this->assertSame(
            array(
                'status' => 400,
                'message' => 'Missing API key for youtube',
            ),
            $result['data']
        );
    }

    public function testWhitespaceSiteTokenHeaderIsNotInjected(): void
    {
        // Trimmed to '' -> not injected -> appStoreSearch fails the token gate.
        $this->setSiteTokenHeader('   ');
        $result = $this->runHandler(
            'v1/integrations/app-store/providers/youtube/search',
            array('siteName' => 'mysite', 'provider' => 'youtube')
        );
        $this->assertSame(403, $result['code']);
        $this->assertSame(
            array(
                'status' => 403,
                'message' => 'invalid request token',
            ),
            $result['data']
        );
    }

    // ------------------------------------------------------------------
    // generateAppStore success path through the route
    // ------------------------------------------------------------------

    public function testAppStoreSuccessRewritesConnectionsAndAppendsSiteConnection(): void
    {
        $this->haxcms()->config->appStore = (object) array(
            'apiKeys' => (object) array('youtube' => 'yt-key-123'),
            'stax' => array((object) array('title' => 'cfg-stax')),
            'autoloader' => array('x-foo'),
        );
        $this->setSiteTokenHeader('site-token-123');
        $result = $this->runHandler(
            'v1/integrations/app-store',
            array('siteName' => 'mysite')
        );
        $this->assertSame(200, $result['code']);
        $this->assertSame(200, $result['data']['status']);
        // loadBaseAppStore: the keyed YouTube app plus the five unconditional
        // keyless apps (NASA, Sketchfab, Dailymotion, CC Mixter, Wikipedia),
        // then the site connection JSON appended last.
        $apps = $result['data']['apps'];
        $this->assertCount(7, $apps);
        // The YouTube app's connection was rewritten to the on-prem broker.
        $connection = $apps[0]['connection'];
        $this->assertSame('Youtube', $apps[0]['details']['title']);
        $this->assertSame('https', $connection['protocol']);
        $this->assertSame('localhost:3000', $connection['url']);
        $this->assertSame(array(), $connection['data']);
        $this->assertSame(
            'site-token-123',
            $connection['headers']['X-HAXCMS-Site-Token']
        );
        $this->assertSame(
            '/system/api/v1/integrations/app-store/providers/youtube/search',
            $connection['operations']['browse']['endPoint']
        );
        // The site connection JSON was decoded and appended.
        $this->assertSame('conn-jwt', $apps[6]['jwt']);
        // Stax/autoloader came from config when provided.
        $this->assertSame(
            array('title' => 'cfg-stax'),
            $result['data']['stax'][0]
        );
        $this->assertSame(array('x-foo'), $result['data']['autoloader']);
    }

    public function testAppStoreSuccessFallsBackToBaseStaxAndFiltersAutoloader(): void
    {
        // No config appStore at all: no API-key apps, base stax, giant
        // fallback autoloader filtered by enabledBlocks.json from a temp
        // config directory.
        $this->tempConfigDir = sys_get_temp_dir() . '/haxcms-integrations-' . uniqid();
        mkdir($this->tempConfigDir . '/settings', 0777, true);
        file_put_contents(
            $this->tempConfigDir . '/settings/enabledBlocks.json',
            '["multiple-choice", "grid-plate", "9lives", ""]'
        );
        $this->haxcms()->configDirectory = $this->tempConfigDir;
        $this->setSiteTokenHeader('site-token-123');
        $result = $this->runHandler(
            'v1/integrations/app-store',
            array('siteName' => 'mysite')
        );
        $this->assertSame(200, $result['code']);
        $this->assertSame(200, $result['data']['status']);
        // Only the five unconditional keyless apps plus the appended site
        // connection JSON (no API keys configured).
        $this->assertCount(6, $result['data']['apps']);
        $this->assertSame('conn-jwt', $result['data']['apps'][5]['jwt']);
        // Base stax fallback.
        $this->assertSame(
            'Two column Article',
            $result['data']['stax'][0]['details']['title']
        );
        // Autoloader filtered to tags enabled in enabledBlocks.json,
        // normalized and deduplicated, order preserved from the base list.
        $this->assertSame(
            array('grid-plate', 'multiple-choice'),
            $result['data']['autoloader']
        );
    }
}
