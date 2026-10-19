<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Tests for lib/systemRoutes/SystemApiRequestContext.php.
 *
 * The real constructor reads $_SERVER via SiteRouteUtils, so each test
 * shapes REQUEST_METHOD / REQUEST_URI / SCRIPT_NAME and asserts the derived
 * context: method, requestPath, relativePath, apiBasePath,
 * absoluteApiBasePath, routeSuffix, params/body/rawBody defaults, header
 * reads, and the isSystemApiRequest gate.
 *
 * parseBody()'s non-empty php://input branches (JSON body decode) are
 * untestable in-process: the CLI SAPI always serves an empty php://input
 * and it cannot be overwritten, so body/rawBody stay empty arrays here and
 * those lines are documented rather than covered.
 */
if (!class_exists('RequestContextFakeTrustedHaxcms')) {
    class RequestContextFakeTrustedHaxcms
    {
        public $protocol = 'https';
        public $domain = '';
        public function resolveTrustedHost()
        {
            return 'cdn.example.edu';
        }
    }
}

if (!class_exists('RequestContextFakeDomainHaxcms')) {
    // No resolveTrustedHost method -> absolute path falls back to domain.
    class RequestContextFakeDomainHaxcms
    {
        public $protocol = 'http';
        public $domain = 'legacy.example.edu';
    }
}

final class SystemApiRequestContextTest extends TestCase
{
    private $haxcmsBackup;
    private $serverBackup;

    protected function setUp(): void
    {
        $this->haxcmsBackup = isset($GLOBALS['HAXCMS']) ? $GLOBALS['HAXCMS'] : null;
        $this->serverBackup = $_SERVER;
    }

    protected function tearDown(): void
    {
        $GLOBALS['HAXCMS'] = $this->haxcmsBackup;
        $_SERVER = $this->serverBackup;
    }

    private function shapeServer(array $overrides = array())
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/system/api/v1/system/version';
        $_SERVER['SCRIPT_NAME'] = '/index.php';
        foreach ($overrides as $key => $value) {
            if ($value === null) {
                unset($_SERVER[$key]);
            } else {
                $_SERVER[$key] = $value;
            }
        }
    }

    public function testConstructorResolvesMethodPathAndRouteSuffix(): void
    {
        $this->shapeServer(array('REQUEST_METHOD' => 'get', 'REQUEST_URI' => '/system/api/v1/system/version?x=1'));
        $context = SystemApiRequestContext::create();
        $this->assertSame('GET', $context->method);
        $this->assertSame('/system/api/v1/system/version', $context->requestPath);
        $this->assertSame('/system/api/v1/system/version', $context->relativePath);
        $this->assertSame('v1/system/version', $context->routeSuffix);
        $this->assertSame('/system/api/v1', $context->apiBasePath);
        $this->assertTrue($context->isSystemApiRequest());
        $this->assertSame(array(), $context->params);
        $this->assertSame(array(), $context->body);
        $this->assertSame(array(), $context->rawBody);
    }

    public function testConstructorDefaultsMethodToGetWhenMissing(): void
    {
        $this->shapeServer(array('REQUEST_METHOD' => null));
        $context = SystemApiRequestContext::create();
        $this->assertSame('GET', $context->method);
    }

    public function testConstructorReturnsNullRouteSuffixForNonSystemApiPath(): void
    {
        $this->shapeServer(array('REQUEST_URI' => '/sites/mysite/index.html'));
        $context = SystemApiRequestContext::create();
        $this->assertFalse($context->isSystemApiRequest());
        $this->assertNull($context->routeSuffix);
    }

    public function testConstructorStripsSiteScriptDirectoryPrefix(): void
    {
        // Site-scoped router: /_sites/<name>/index.php serving /system/api.
        $this->shapeServer(array(
            'REQUEST_URI' => '/_sites/mysite/system/api/v1/sites',
            'SCRIPT_NAME' => '/_sites/mysite/index.php',
        ));
        $context = SystemApiRequestContext::create();
        $this->assertSame('/system/api/v1/sites', $context->relativePath);
        $this->assertSame('v1/sites', $context->routeSuffix);
        $this->assertSame('/_sites/mysite/system/api/v1', $context->apiBasePath);
        $this->assertTrue($context->isSystemApiRequest());
    }

    public function testConstructorAddsLeadingSlashToRelativePath(): void
    {
        $this->shapeServer(array('REQUEST_URI' => 'system/api/v1/entities', 'SCRIPT_NAME' => 'system/api/index.php'));
        // dirname('system/api/index.php') == 'system/api' -> strips that
        // prefix, leaving '/v1/entities' with a leading slash re-added.
        $context = SystemApiRequestContext::create();
        $this->assertSame('/v1/entities', $context->relativePath);
        $this->assertFalse($context->isSystemApiRequest());
    }

    public function testConstructorApiBasePathFallsBackToDefault(): void
    {
        $this->shapeServer(array('REQUEST_URI' => '/x/api/v1/whatever'));
        $context = SystemApiRequestContext::create();
        $this->assertSame('/system/api/v1', $context->apiBasePath);
    }

    public function testConstructorRouteSuffixIsEmptyForBareSystemApi(): void
    {
        $this->shapeServer(array('REQUEST_URI' => '/system/api'));
        $context = SystemApiRequestContext::create();
        $this->assertTrue($context->isSystemApiRequest());
        $this->assertSame('', $context->routeSuffix);
    }

    public function testConstructorBodyStaysEmptyForCliInput(): void
    {
        // php://input is always empty under the CLI SAPI.
        $this->shapeServer();
        $context = SystemApiRequestContext::create();
        $this->assertSame(array(), $context->body);
        $this->assertSame(array(), $context->rawBody);
    }

    public function testAbsoluteApiBasePathUsesHaxcmsTrustedHostAndProtocol(): void
    {
        $this->shapeServer();
        $GLOBALS['HAXCMS'] = new RequestContextFakeTrustedHaxcms();
        $context = SystemApiRequestContext::create();
        $this->assertSame('https://cdn.example.edu/system/api/v1', $context->absoluteApiBasePath);
    }

    public function testAbsoluteApiBasePathFallsBackToDomainProperty(): void
    {
        $this->shapeServer();
        $GLOBALS['HAXCMS'] = new RequestContextFakeDomainHaxcms();
        $context = SystemApiRequestContext::create();
        $this->assertSame('http://legacy.example.edu/system/api/v1', $context->absoluteApiBasePath);
    }

    public function testAbsoluteApiBasePathFallsBackToHttpHost(): void
    {
        $this->shapeServer(array('HTTP_HOST' => 'localhost:8080'));
        $GLOBALS['HAXCMS'] = null;
        $context = SystemApiRequestContext::create();
        $this->assertSame('http://localhost:8080/system/api/v1', $context->absoluteApiBasePath);
    }

    public function testAbsoluteApiBasePathUsesHttpsServerFlag(): void
    {
        $this->shapeServer(array('HTTPS' => 'on', 'HTTP_HOST' => 'secure.example.edu'));
        $GLOBALS['HAXCMS'] = null;
        $context = SystemApiRequestContext::create();
        $this->assertSame('https://secure.example.edu/system/api/v1', $context->absoluteApiBasePath);
    }

    public function testAbsoluteApiBasePathUsesRequestSchemeFallback(): void
    {
        $this->shapeServer(array('HTTP_HOST' => 'proxy.example.edu', 'HTTPS' => null, 'REQUEST_SCHEME' => 'https'));
        $GLOBALS['HAXCMS'] = null;
        $context = SystemApiRequestContext::create();
        $this->assertSame('https://proxy.example.edu/system/api/v1', $context->absoluteApiBasePath);
    }

    public function testAbsoluteApiBasePathUnchangedWhenNoHostAvailable(): void
    {
        $this->shapeServer(array('HTTP_HOST' => null, 'HTTPS' => null, 'REQUEST_SCHEME' => null));
        $GLOBALS['HAXCMS'] = null;
        $context = SystemApiRequestContext::create();
        $this->assertSame('/system/api/v1', $context->absoluteApiBasePath);
    }

    public function testGetHeaderReadsHttpPrefixedServerVariable(): void
    {
        $this->shapeServer(array('HTTP_X_HAXCMS_USER_TOKEN' => 'client-token'));
        $context = SystemApiRequestContext::create();
        $this->assertSame('client-token', $context->getHeader('X-HAXCMS-User-Token'));
        $this->assertNull($context->getHeader('X-HAXCMS-Site-Token'));
    }

    public function testSetRouteParamsStoresArrayAndIgnoresNonArray(): void
    {
        $this->shapeServer();
        $context = SystemApiRequestContext::create();
        $context->setRouteParams(array('siteName' => 'my-site'));
        $this->assertSame('my-site', $context->getParam('siteName'));
        $this->assertSame('fallback', $context->getParam('missing', 'fallback'));
        $context->setRouteParams('not-an-array');
        $this->assertSame(array('siteName' => 'my-site'), $context->params);
    }

    public function testGetApiBasePathFromRequestPathVariants(): void
    {
        $this->assertSame('/system/api/v1', SystemApiRequestContext::getApiBasePathFromRequestPath('/system/api/v1'));
        $this->assertSame(
            '/system/api/v1',
            SystemApiRequestContext::getApiBasePathFromRequestPath('/system/api/v1/system/version')
        );
        $this->assertSame(
            '/_sites/mysite/system/api/v1',
            SystemApiRequestContext::getApiBasePathFromRequestPath('/_sites/mysite/system/api/v1/sites')
        );
        $this->assertSame(
            '/system/api/v1',
            SystemApiRequestContext::getApiBasePathFromRequestPath('/x/api/v1/whatever')
        );
        $this->assertSame(
            '/system/api/v1',
            SystemApiRequestContext::getApiBasePathFromRequestPath('')
        );
    }

    public function testGetRouteSuffixFromRelativePathVariants(): void
    {
        $this->assertSame('v1/sites', SystemApiRequestContext::getRouteSuffixFromRelativePath('/system/api/v1/sites'));
        $this->assertSame('v1/sites', SystemApiRequestContext::getRouteSuffixFromRelativePath('/api/v1/sites'));
        $this->assertSame('sites', SystemApiRequestContext::getRouteSuffixFromRelativePath('/api/sites'));
        $this->assertSame('', SystemApiRequestContext::getRouteSuffixFromRelativePath('/api/'));
        $this->assertNull(SystemApiRequestContext::getRouteSuffixFromRelativePath('/sites/mysite'));
        // Empty input falls back to the current request path.
        $this->assertNull(SystemApiRequestContext::getRouteSuffixFromRelativePath(''));
    }

    public function testIsSystemApiPathVariants(): void
    {
        $this->assertTrue(SystemApiRequestContext::isSystemApiPath('/system/api'));
        $this->assertTrue(SystemApiRequestContext::isSystemApiPath('/system/api/v1/sites'));
        $this->assertTrue(SystemApiRequestContext::isSystemApiPath('/api'));
        $this->assertTrue(SystemApiRequestContext::isSystemApiPath('/api/v1/sites'));
        $this->assertFalse(SystemApiRequestContext::isSystemApiPath('/x/api/v1/sites'));
        $this->assertFalse(SystemApiRequestContext::isSystemApiPath('api/v1/sites'));
        $this->assertFalse(SystemApiRequestContext::isSystemApiPath(''));
    }
}
