<?php
/**
 * Characterization tests for lib/systemRoutes/v1/haxiam.php (route closure
 * only — the operation itself is covered by OperationsHaxiamAddUserAccessTest).
 *
 * Covered: body/context-params merge into Operations->params, auth-token
 * stripping, user_token injection from getActiveUserName()/getRequestToken(),
 * the haxiamAddUserAccess() call, and the __failed tail (failed status as
 * response code, whole __failed array printed). Assertions on operation
 * messages prove the injected user_token and merged params reached it.
 *
 * DOCUMENTED NOT-IN-PROCESS-COVERED BLOCK:
 *   - The else print: a non-__failed response requires the symlink success
 *     path, whose helpers hardcode root-owned /var/www/sites (see
 *     OperationsHaxiamAddUserAccessTest scope note).
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/OperationsTestHaxcms.php';
require_once __DIR__ . '/SystemRoutesIntegrationsHaxiamFixtures.php';

class SystemRoutesHaxiamTest extends TestCase
{
    private $savedHaxcms;
    private $savedServer;
    private $haxiamPath;

    protected function setUp(): void
    {
        $this->haxiamPath = dirname(__DIR__, 2) . '/lib/systemRoutes/v1/haxiam.php';
        $this->savedHaxcms = isset($GLOBALS['HAXCMS']) ? $GLOBALS['HAXCMS'] : null;
        $this->savedServer = $_SERVER;
        $_SERVER['SERVER_SOFTWARE'] = 'phpunit-test';
        http_response_code(200);
        require_once $this->haxiamPath;
        $GLOBALS['HAXCMS'] = new RoutesIntegrationsHaxiamFakeHaxcms();
        $GLOBALS['HAXCMS']->config->iam = true;
    }

    protected function tearDown(): void
    {
        if ($this->savedHaxcms !== null) {
            $GLOBALS['HAXCMS'] = $this->savedHaxcms;
        } else {
            unset($GLOBALS['HAXCMS']);
        }
        $_SERVER = $this->savedServer;
        http_response_code(200);
    }

    private function haxcms()
    {
        return $GLOBALS['HAXCMS'];
    }

    private function runHandler($body = array(), $routeParams = array())
    {
        $context = (new ReflectionClass('SystemApiRequestContext'))
          ->newInstanceWithoutConstructor();
        $context->apiBasePath = '/system/api';
        $context->routeSuffix = 'haxiam/add-user-access';
        $context->method = 'POST';
        $context->body = $body;
        $context->params = $routeParams;
        $handler = require $this->haxiamPath;
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
    // __failed tail (whole __failed array printed, failed status as code)
    // ------------------------------------------------------------------

    public function testIamDisabledReturns400FailedEnvelope(): void
    {
        $this->haxcms()->config->iam = false;
        $result = $this->runHandler(array(
            'userName' => 'targetuser',
            'siteName' => 'mysite',
        ));
        $this->assertSame(400, $result['code']);
        $this->assertSame(
            array('status' => 400, 'message' => 'HAXIAM mode is not enabled', 'code' => 'HAXIAM_DISABLED'),
            $result['data']
        );
    }

    public function testIamUnsetReturns400FailedEnvelope(): void
    {
        unset($this->haxcms()->config->iam);
        $result = $this->runHandler(array());
        $this->assertSame(400, $result['code']);
        $this->assertSame('HAXIAM mode is not enabled', $result['data']['message']);
    }

    public function testInvalidRequestTokenReturns403FailedEnvelope(): void
    {
        // userName/siteName present so the 403 is provably the token gate.
        $this->haxcms()->validRequestToken = false;
        $result = $this->runHandler(array(
            'userName' => 'targetuser',
            'siteName' => 'mysite',
        ));
        $this->assertSame(403, $result['code']);
        $this->assertSame(
            array('status' => 403, 'message' => 'invalid request token', 'code' => 'USER_TOKEN_INVALID'),
            $result['data']
        );
    }

    public function testForgedBodyUserTokenIsStrippedAndReplaced(): void
    {
        // Token decisions are bound to getRequestToken() output: only the
        // route-injected request-token-for-testuser passes. A forged body
        // user_token must be discarded (unset) before injection, so the flow
        // proceeds past the gate to the HAXIAM user lookup.
        $GLOBALS['HAXCMS'] = new HaxiamTokenBindingFakeHaxcms();
        $GLOBALS['HAXCMS']->config->iam = true;
        $result = $this->runHandler(array(
            'user_token' => 'forged-token',
            'userName' => 'targetuser',
            'siteName' => 'mysite',
        ));
        $this->assertSame(403, $result['code']);
        $this->assertSame(
            'User not found or has not set up HAXIAM yet',
            $result['data']['message']
        );
    }

    // ------------------------------------------------------------------
    // Operation gate failures surfaced through the route
    // ------------------------------------------------------------------

    public function testMissingUserNameReturns400(): void
    {
        $result = $this->runHandler(array('siteName' => 'mysite'));
        $this->assertSame(400, $result['code']);
        $this->assertSame('userName is required', $result['data']['message']);
    }

    public function testInvalidUserNameMachineNameReturns400(): void
    {
        $result = $this->runHandler(array(
            'userName' => 'User Name With Spaces',
            'siteName' => 'mysite',
        ));
        $this->assertSame(400, $result['code']);
        $this->assertSame(
            'userName must be a valid machine name (alphanumeric, hyphens, underscores only)',
            $result['data']['message']
        );
    }

    public function testSelfAccessGrantBlocked(): void
    {
        $result = $this->runHandler(array(
            'userName' => 'testuser',
            'siteName' => 'mysite',
        ));
        $this->assertSame(400, $result['code']);
        $this->assertSame(
            'Cannot grant access to yourself',
            $result['data']['message']
        );
    }

    public function testUnprovisionedTargetUserReturns403(): void
    {
        // /var/www/sites does not exist in this environment, so the HAXIAM
        // user lookup fails naturally.
        $result = $this->runHandler(array(
            'userName' => 'targetuser',
            'siteName' => 'mysite',
        ));
        $this->assertSame(403, $result['code']);
        $this->assertSame(
            'User not found or has not set up HAXIAM yet',
            $result['data']['message']
        );
    }

    public function testContextParamsAreMergedIntoOperationParams(): void
    {
        // userName arrives via context->params (route capture) rather than
        // body; siteName via body. The machine-name rejection for userName
        // proves the context param reached the operation.
        $result = $this->runHandler(
            array('siteName' => 'mysite'),
            array('userName' => 'Invalid Name')
        );
        $this->assertSame(400, $result['code']);
        $this->assertStringContainsString(
            'userName must be a valid machine name',
            $result['data']['message']
        );
    }
}
