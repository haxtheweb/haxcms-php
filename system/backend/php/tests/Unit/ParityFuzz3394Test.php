<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/SiteRoutesTestHelpers.php';
require_once __DIR__ . '/OperationsTestHaxcms.php';
require_once __DIR__ . '/OperationsNodeOpsTest.php';

/**
 * Request context with an injected body, raw body and headers: php://input
 * is empty under the PHPUnit CLI.
 */
class ParityFuzz3394Context extends SiteApiRequestContext
{
    public $injectedBody = array();
    public $injectedRawBody = '';
    public $injectedHeaders = array();

    public function getBody()
    {
        return is_array($this->injectedBody) ? $this->injectedBody : array();
    }

    public function getRawBody()
    {
        return $this->injectedRawBody;
    }

    public function getHeader($name)
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        if (array_key_exists($key, $this->injectedHeaders)) {
            return $this->injectedHeaders[$key];
        }
        return parent::getHeader($name);
    }
}

/**
 * Regression tests for the ten haxcms-nodejs / haxcms-php divergences the
 * parity fuzzer in create found (https://github.com/haxtheweb/issues/issues/3394,
 * seed 3386). Each sends the request the fuzzer shrank the finding to and
 * checks the answer both backends now give. The nodejs twin is
 * haxcms-nodejs test/unit/parity-fuzz-3394.test.cjs: keep the two in step.
 */
class ParityFuzz3394Test extends TestCase
{
    private $savedHaxcms;
    private $savedServerSoftware;
    private $tmpRoot;
    private $siteName = 'parity3394';
    private $haxcms;
    private $site;

    protected function setUp(): void
    {
        if (isset($GLOBALS['HAXCMS'])) {
            $this->savedHaxcms = $GLOBALS['HAXCMS'];
        }
        if (isset($_SERVER['SERVER_SOFTWARE'])) {
            $this->savedServerSoftware = $_SERVER['SERVER_SOFTWARE'];
        }
        $_SERVER['SERVER_SOFTWARE'] = 'phpunit-test';
        if (!defined('HAXCMS_ROOT')) {
            define('HAXCMS_ROOT', sys_get_temp_dir() . '/haxcms_ops_node_root');
        }
        $this->tmpRoot = HAXCMS_ROOT . '/parity3394_' . uniqid();
        $this->buildSiteFixture();
        $this->haxcms = new OperationsNodeTestHaxcms();
        $this->haxcms->configDirectory = $this->tmpRoot . '/_config';
        mkdir($this->haxcms->configDirectory, 0777, true);
        $this->haxcms->sitesDirectory = basename($this->tmpRoot);
        $GLOBALS['HAXCMS'] = $this->haxcms;
        $site = new OperationsNodeTestSite();
        $site->load($this->tmpRoot, '/', $this->siteName);
        $this->haxcms->loadedSite = $site;
        $this->site = $site;
    }

    protected function tearDown(): void
    {
        if (isset($this->savedHaxcms)) {
            $GLOBALS['HAXCMS'] = $this->savedHaxcms;
        } else {
            unset($GLOBALS['HAXCMS']);
        }
        if (isset($this->savedServerSoftware)) {
            $_SERVER['SERVER_SOFTWARE'] = $this->savedServerSoftware;
        } else {
            unset($_SERVER['SERVER_SOFTWARE']);
        }
        $this->rrmdir($this->tmpRoot);
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
            if (is_link($path)) {
                @unlink($path);
            } elseif (is_dir($path)) {
                $this->rrmdir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    private function buildSiteFixture(): void
    {
        $siteDir = $this->tmpRoot . '/' . $this->siteName;
        mkdir($siteDir . '/pages/item-a', 0777, true);
        mkdir($siteDir . '/pages/item-b', 0777, true);
        file_put_contents($siteDir . '/pages/item-a/index.html', '<p>Content A</p>');
        file_put_contents($siteDir . '/pages/item-b/index.html', '<p>Content B</p>');
        $manifest = (object) array(
            'id' => 'site-uuid',
            'title' => 'Parity Site',
            'author' => '',
            'description' => '',
            'license' => 'by-sa',
            'metadata' => (object) array(
                'site' => (object) array('name' => $this->siteName),
                'platform' => (object) array('features' => (object) array()),
                'theme' => (object) array(
                    'element' => 'haxcms-demo-theme',
                    'variables' => (object) array(),
                    'regions' => (object) array(),
                ),
            ),
            'items' => array(
                (object) array(
                    'id' => 'item-a',
                    'indent' => 0,
                    'location' => 'pages/item-a/index.html',
                    'slug' => 'item-a',
                    'order' => 0,
                    'parent' => null,
                    'title' => 'Item A',
                    'description' => '',
                    'metadata' => (object) array('created' => 1760000000, 'updated' => 1760095000),
                ),
                (object) array(
                    'id' => 'item-b',
                    'indent' => 0,
                    'location' => 'pages/item-b/index.html',
                    'slug' => 'item-b',
                    'order' => 1,
                    'parent' => null,
                    'title' => 'Item B',
                    'description' => '',
                    'metadata' => (object) array('created' => 1760000000, 'updated' => 1760095000),
                ),
            ),
        );
        file_put_contents($siteDir . '/site.json', json_encode($manifest, JSON_PRETTY_PRINT));
    }

    private function siteJson(): string
    {
        return (string) file_get_contents($this->tmpRoot . '/' . $this->siteName . '/site.json');
    }

    /**
     * Send one request to a site route handler. $rawBody is the JSON text the
     * client sent; the decoded body is made from it the way getBody() does.
     */
    private function send(string $handlerFile, string $method, string $routeSuffix, $rawBody = '', array $params = array()): array
    {
        $context = new ParityFuzz3394Context($this->site);
        $context->method = $method;
        $context->routeSuffix = $routeSuffix;
        $context->apiBasePath = '/x/api';
        $context->setRouteParams($params);
        $context->injectedRawBody = $rawBody;
        $decoded = $rawBody === '' ? array() : json_decode($rawBody, true);
        $context->injectedBody = is_array($decoded) ? $decoded : array();
        $context->injectedHeaders = array('HTTP_X_HAXCMS_SITE_TOKEN' => 'tok123');
        $handler = include dirname(__DIR__, 2) . '/lib/siteRoutes/v1/' . $handlerFile;
        ob_start();
        try {
            $handler($context);
        } finally {
            $raw = ob_get_clean();
        }
        return array('raw' => $raw, 'json' => json_decode($raw, true));
    }

    private function assertError(array $res, int $status, string $message, string $code, $params = null): void
    {
        $this->assertSame($status, $res['json']['status'], $res['raw']);
        $this->assertSame($message, $res['json']['data']['message'], $res['raw']);
        $this->assertSame($code, $res['json']['data']['code'], $res['raw']);
        if ($params === null) {
            $this->assertArrayNotHasKey('params', $res['json']['data'], $res['raw']);
        } else {
            $this->assertSame($params, $res['json']['data']['params'], $res['raw']);
        }
    }

    private function nameOnly(): string
    {
        return json_encode(array('site' => array('name' => $this->siteName)));
    }

    public function test01UpdateSiteAppearanceWithoutManifest(): void
    {
        $before = $this->siteJson();
        $res = $this->send('siteMutation.php', 'PATCH', 'v1/site/appearance', $this->nameOnly());
        $this->assertError($res, 400, 'invalid manifest payload', 'PARAM_INVALID', array('param' => 'manifest'));
        $this->assertSame($before, $this->siteJson());
    }

    public function test02UpdateSitePlatformWithoutSettings(): void
    {
        $before = $this->siteJson();
        $res = $this->send('siteMutation.php', 'PATCH', 'v1/site/platform', $this->nameOnly());
        $this->assertError($res, 400, 'missing platform settings', 'PLATFORM_SETTINGS_MISSING');
        $this->assertSame($before, $this->siteJson());
    }

    public function test03DeleteFileByUuidBlank(): void
    {
        $res = $this->send('filesMutation.php', 'DELETE', 'v1/files/%20%20', '', array('fileUuid' => '  '));
        $this->assertError($res, 400, 'File uuid is required and must be a valid UUID', 'FILE_UUID_INVALID');
        $empty = $this->send('filesMutation.php', 'DELETE', 'v1/files/', '', array('fileUuid' => ''));
        $this->assertError($empty, 400, 'File uuid is required and must be a valid UUID', 'FILE_UUID_INVALID');
    }

    public function test04UpdateSiteAllowedBlocksWithoutSettings(): void
    {
        $before = $this->siteJson();
        $res = $this->send('siteMutation.php', 'PATCH', 'v1/site/blocks', $this->nameOnly());
        $this->assertError($res, 400, 'missing platform settings', 'PLATFORM_SETTINGS_MISSING');
        $this->assertSame($before, $this->siteJson());
    }

    public function test05UpdateSiteOutlineEmptyObjectItem(): void
    {
        $before = $this->siteJson();
        $res = $this->send(
            'siteMutation.php',
            'PATCH',
            'v1/site/outline',
            json_encode(array('site' => array('name' => $this->siteName), 'items' => array(new stdClass())))
        );
        $this->assertError($res, 400, 'Each outline item requires an id', 'OUTLINE_INVALID');
        // a list is still not an item
        $list = $this->send(
            'siteMutation.php',
            'PATCH',
            'v1/site/outline',
            '{"site":{"name":"' . $this->siteName . '"},"items":[[]]}'
        );
        $this->assertError($list, 400, 'Each outline item must be an object', 'OUTLINE_INVALID');
        $this->assertSame($before, $this->siteJson());
    }

    public function test06UpdateSiteSummaryWithoutFormToken(): void
    {
        $before = $this->siteJson();
        // the site token is valid; there is no valid form token
        $this->haxcms->requestTokenSequence = array(true, false);
        $res = $this->send('siteMutation.php', 'PATCH', 'v1/site', $this->nameOnly());
        $this->assertError($res, 403, 'invalid request token', 'USER_TOKEN_INVALID');
        $this->assertSame($before, $this->siteJson());
    }

    public function test07ProjectionToNoFieldIsAnObject(): void
    {
        $projected = SiteRouteUtils::projectRecord(array('tag' => 'x-el', 'properties' => array()), array('!'));
        $this->assertSame('{}', json_encode($projected));
        $collection = SiteRouteUtils::projectCollection(
            array(array('tag' => 'a-el'), array('tag' => 'b-el')),
            array('!')
        );
        $this->assertSame('[{},{}]', json_encode($collection));
        // a field that is there with a null value is kept, as haxcms-nodejs does
        $withNull = SiteRouteUtils::projectRecord(array('tag' => 'x-el', 'parent' => null), array('parent'));
        $this->assertSame('{"parent":null}', json_encode($withNull));
    }

    public function test08ReplaceContentWithNonStringSearch(): void
    {
        $before = $this->siteJson();
        $res = $this->send(
            'contentMutation.php',
            'PATCH',
            'v1/content',
            json_encode(array(
                'site' => array('name' => $this->siteName),
                'search' => 0,
                'replace' => '',
                'replaceConfirm' => false,
            ))
        );
        $this->assertError($res, 400, 'Search query is required', 'PARAM_REQUIRED', array('param' => 'Search query'));
        // one character counted in characters, not bytes: é is too short to replace
        $short = $this->send(
            'contentMutation.php',
            'PATCH',
            'v1/content',
            json_encode(array('site' => array('name' => $this->siteName), 'search' => 'é', 'replace' => 'ab'))
        );
        $this->assertError($short, 400, 'Search text must be more than 1 character for replacement operations', 'SEARCH_TEXT_TOO_SHORT');
        $this->assertSame($before, $this->siteJson());
    }

    public function test09UpdateSiteEditorSettingsWithoutSettings(): void
    {
        $before = $this->siteJson();
        $res = $this->send('siteMutation.php', 'PATCH', 'v1/site/editor', $this->nameOnly());
        $this->assertError($res, 400, 'missing platform settings', 'PLATFORM_SETTINGS_MISSING');
        $this->assertSame($before, $this->siteJson());
    }

    public function test10ItemLinksAndDates(): void
    {
        $this->assertSame('2025-10-09T08:53:20.000Z', SiteRouteUtils::toIsoDateFromUnixTime(1760000000));
        $this->assertSame('1970-01-01T00:00:01.500Z', SiteRouteUtils::toIsoDateFromUnixTime(1.5));
        $this->assertNull(SiteRouteUtils::toIsoDateFromUnixTime(0));
        $res = $this->send('items.php', 'GET', 'v1/items/item-a', '', array('idOrSlug' => 'item-a'));
        $this->assertSame(200, $res['json']['status'], $res['raw']);
        $links = $res['json']['data']['links'];
        // the first, top-level page: no previous page, no parent
        $this->assertArrayNotHasKey('previous', $links);
        $this->assertArrayNotHasKey('parent', $links);
        $this->assertSame('/x/api/v1/items/item-b', $links['next']);
        $this->assertArrayHasKey('children', $links);
        $record = array('links' => array('previous' => 'stale'));
        SiteRouteUtils::applyItemNavigationLinks($record, array('previous' => null, 'next' => '/n', 'parent' => '', 'children' => '/c'));
        $this->assertSame(array('next' => '/n', 'children' => '/c'), $record['links']);
    }
}
