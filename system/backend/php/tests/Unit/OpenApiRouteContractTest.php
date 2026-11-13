<?php
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Contract between the PHP route maps and the OpenAPI specs the two
 * backends share (lib/siteRoutes/openapi/site-spec.yaml,
 * lib/systemRoutes/openapi/system-spec.yaml). haxcms-nodejs keeps identical
 * specs, so every assertion here is one the Node backend must also satisfy.
 *
 *   - every routed method+path is declared in the spec, and every spec
 *     operation under the API prefix is routed (a spec that declares
 *     per-value paths, e.g. /site/import/haxcms, is matched against a
 *     parameterized route key, v1/site/import/:platform)
 *   - every handler file a route map points at exists
 *   - every operation has a unique operationId and declares responses
 *   - the set of PUBLIC system API operations is pinned: adding a public
 *     system route has to be a deliberate change to this list. In
 *     particular the installed-version and status endpoints never become
 *     public (the running HAX version must not be exposed to anonymous
 *     callers)
 */
class OpenApiRouteContractTest extends TestCase
{
    private static $methods = array('get', 'post', 'put', 'patch', 'delete');

    private static function libDir(): string
    {
        return dirname(__DIR__, 2) . '/lib';
    }

    private static function spec(string $which): array
    {
        $file = $which === 'site'
            ? self::libDir() . '/siteRoutes/openapi/site-spec.yaml'
            : self::libDir() . '/systemRoutes/openapi/system-spec.yaml';
        return Yaml::parseFile($file);
    }

    private static function routeMap(string $which): array
    {
        return $which === 'site' ? SiteRoutesMap::getRoutesMap() : SystemRoutesMap::getRoutesMap();
    }

    private static function prefix(string $which): string
    {
        return $which === 'site' ? '/x/api' : '/system/api';
    }

    /**
     * Spec operations under the API prefix as "METHOD route-key".
     */
    private static function specOperations(string $which): array
    {
        $out = array();
        $prefix = self::prefix($which);
        foreach (self::spec($which)['paths'] as $path => $config) {
            if (strpos($path, $prefix) !== 0 || !is_array($config)) {
                continue;
            }
            $route = ltrim(substr($path, strlen($prefix)), '/');
            $route = preg_replace('/\{([A-Za-z0-9_]+)\}/', ':$1', $route);
            foreach (self::$methods as $method) {
                if (isset($config[$method]) && is_array($config[$method])) {
                    $out[] = strtoupper($method) . ' ' . $route;
                }
            }
        }
        return $out;
    }

    private static function routedOperations(string $which): array
    {
        $out = array();
        foreach (self::routeMap($which) as $method => $routes) {
            foreach ($routes as $route => $handler) {
                $out[] = strtoupper($method) . ' ' . $route;
            }
        }
        return $out;
    }

    /**
     * True when a concrete spec route (v1/site/import/haxcms) is served by a
     * parameterized routed key (v1/site/import/:platform).
     */
    private static function routeServes(string $routed, string $spec): bool
    {
        if ($routed === $spec) {
            return true;
        }
        $routedParts = explode(' ', $routed, 2);
        $specParts = explode(' ', $spec, 2);
        if ($routedParts[0] !== $specParts[0]) {
            return false;
        }
        $a = explode('/', $routedParts[1]);
        $b = explode('/', $specParts[1]);
        if (count($a) !== count($b)) {
            return false;
        }
        foreach ($a as $i => $segment) {
            if ($segment !== $b[$i] && substr($segment, 0, 1) !== ':') {
                return false;
            }
        }
        return true;
    }

    public static function backends(): array
    {
        return array('site API' => array('site'), 'system API' => array('system'));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('backends')]
    public function testEveryRoutedOperationIsDeclaredInTheSpec(string $which): void
    {
        $spec = self::specOperations($which);
        $undeclared = array();
        foreach (self::routedOperations($which) as $routed) {
            $declared = false;
            foreach ($spec as $specOp) {
                if (self::routeServes($routed, $specOp)) {
                    $declared = true;
                    break;
                }
            }
            if (!$declared) {
                $undeclared[] = $routed;
            }
        }
        $this->assertSame(array(), $undeclared, 'routed but missing from the ' . $which . ' spec');
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('backends')]
    public function testEverySpecOperationIsRouted(string $which): void
    {
        $routed = self::routedOperations($which);
        $unrouted = array();
        foreach (self::specOperations($which) as $specOp) {
            $served = false;
            foreach ($routed as $route) {
                if (self::routeServes($route, $specOp)) {
                    $served = true;
                    break;
                }
            }
            if (!$served) {
                $unrouted[] = $specOp;
            }
        }
        $this->assertSame(array(), $unrouted, 'declared in the ' . $which . ' spec but not routed');
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('backends')]
    public function testEveryRouteHandlerFileExists(string $which): void
    {
        $missing = array();
        foreach (self::routeMap($which) as $method => $routes) {
            foreach ($routes as $route => $handler) {
                if (!is_string($handler) || !is_file($handler)) {
                    $missing[] = $method . ' ' . $route;
                }
            }
        }
        $this->assertSame(array(), $missing);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('backends')]
    public function testOperationsHaveUniqueIdsAndDeclareResponses(string $which): void
    {
        $seen = array();
        $problems = array();
        foreach (self::spec($which)['paths'] as $path => $config) {
            if (!is_array($config)) {
                continue;
            }
            foreach (self::$methods as $method) {
                if (!isset($config[$method]) || !is_array($config[$method])) {
                    continue;
                }
                $op = $config[$method];
                $label = strtoupper($method) . ' ' . $path;
                if (!isset($op['operationId']) || !is_string($op['operationId']) || $op['operationId'] === '') {
                    $problems[] = $label . ': no operationId';
                } elseif (isset($seen[$op['operationId']])) {
                    $problems[] = $label . ': operationId ' . $op['operationId'] . ' also used by ' . $seen[$op['operationId']];
                } else {
                    $seen[$op['operationId']] = $label;
                }
                if (!isset($op['responses']) || !is_array($op['responses']) || count($op['responses']) === 0) {
                    $problems[] = $label . ': no responses';
                }
            }
        }
        $this->assertSame(array(), $problems);
    }

    public function testPublicSystemOperationsArePinned(): void
    {
        $public = array();
        foreach (SiteRouteUtils::readSystemApiAuthPoliciesFromOpenApiSpec() as $key => $policy) {
            if ($policy === 'public') {
                $public[] = $key;
            }
        }
        sort($public);
        $expected = array(
            'get:v1',
            'get:v1/openapi',
            'get:v1/openapi.json',
            'get:v1/openapi.yaml',
            'get:v1/session',
            'get:v1/session/connection-settings',
            'get:v1/session/connection-test',
            // one-time sign-in links: GET only shows a confirm page; POST
            // redeems a token minted with filesystem access (SEC-MAGICLINK)
            'get:v1/session/magic-link',
            'get:v1/session/refresh',
            'post:v1/session',
            'post:v1/session/connection-settings',
            'post:v1/session/connection-test',
            'post:v1/session/login',
            'post:v1/session/logout',
            'post:v1/session/magic-link',
            'post:v1/session/refresh',
        );
        sort($expected);
        $this->assertSame($expected, $public);
    }

    public function testVersionAndStatusEndpointsAreNeverPublic(): void
    {
        foreach (array('v1/system/version', 'v1/status') as $route) {
            $policy = SiteRouteUtils::getSystemApiRouteAuthPolicy($route, 'GET');
            $this->assertNotSame('public', $policy, $route . ' must require authentication');
        }
        $spec = self::spec('system');
        $versionSecurity = $spec['paths']['/system/api/v1/system/version']['get']['security'];
        $this->assertNotEmpty($versionSecurity);
        foreach ($versionSecurity as $requirement) {
            $this->assertNotSame(array(), $requirement, 'an empty requirement would make the version endpoint optional-auth');
        }
    }

    public function testUndeclaredRoutesFailClosed(): void
    {
        $this->assertSame('authenticated', SiteRouteUtils::getSiteApiRouteAuthPolicy('v1/not-in-the-spec', 'GET'));
        $this->assertSame('authenticated', SiteRouteUtils::getSystemApiRouteAuthPolicy('v1/not-in-the-spec', 'GET'));
    }
}
