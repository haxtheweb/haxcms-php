<?php
/**
 * Language registry + /system/api/v1/languages routes (parity with the
 * haxcms-nodejs test/unit/languages-routes.test.cjs).
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/OperationsTestHaxcms.php';
require_once dirname(__DIR__, 2) . '/lib/LanguageRegistry.php';
require_once dirname(__DIR__, 2) . '/lib/LocalizationSettingsService.php';

class LanguagesRouteFakeHaxcms extends OperationsTestHaxcms
{
    public $systemRequestBase = 'system/api/';
    public $safeGet = null;
    public $tokenCalls = array();

    public function getRequestTokenUserName()
    {
        return 'testuser';
    }

    public function getRequestToken($name)
    {
        return 'server-token-for-' . $name;
    }

    public function dispatchEvent($eventName, $context = null)
    {
        return null;
    }
}

class SystemRoutesLanguagesTest extends TestCase
{
    private $savedHaxcms;
    private $savedGet;
    private $savedServer;
    private $settingsPath;

    protected function setUp(): void
    {
        $this->settingsPath = dirname(__DIR__, 2) . '/lib/systemRoutes/v1/settings.php';
        $this->savedHaxcms = isset($GLOBALS['HAXCMS']) ? $GLOBALS['HAXCMS'] : null;
        $this->savedGet = $_GET;
        $this->savedServer = $_SERVER;
        $_GET = array();
        unset($_SERVER['QUERY_STRING']);
        $GLOBALS['HAXCMS'] = new LanguagesRouteFakeHaxcms();
        HAXCMSLanguageRegistry::reset();
    }

    protected function tearDown(): void
    {
        if ($this->savedHaxcms !== null) {
            $GLOBALS['HAXCMS'] = $this->savedHaxcms;
        } else {
            unset($GLOBALS['HAXCMS']);
        }
        $_GET = $this->savedGet;
        $_SERVER = $this->savedServer;
        http_response_code(200);
    }

    private function runHandler($routeSuffix, $query = array(), $params = array(), $method = 'GET')
    {
        $_SERVER['REQUEST_METHOD'] = $method;
        $_GET = $query;
        $GLOBALS['HAXCMS']->safeGet = $query;
        $context = (new ReflectionClass('SystemApiRequestContext'))->newInstanceWithoutConstructor();
        $context->apiBasePath = '/system/api';
        $context->routeSuffix = $routeSuffix;
        $context->method = $method;
        $context->body = array();
        $context->params = $params;
        $handler = require $this->settingsPath;
        ob_start();
        $handler($context);
        $raw = ob_get_clean();
        return array('data' => json_decode($raw, true), 'code' => http_response_code());
    }

    // registry helpers

    public function testMatchesCodesAliasesAndUnderscoreForms(): void
    {
        $this->assertSame('en', HAXCMSLanguageRegistry::matchLanguageCode('en'));
        $this->assertSame('en-US', HAXCMSLanguageRegistry::matchLanguageCode('EN_us'));
        $this->assertSame('zh-CN', HAXCMSLanguageRegistry::matchLanguageCode('zh_CN'));
        $this->assertSame('zh-CN', HAXCMSLanguageRegistry::matchLanguageCode('zh'));
        $this->assertSame('nb', HAXCMSLanguageRegistry::matchLanguageCode('no'));
        $this->assertSame('tl', HAXCMSLanguageRegistry::matchLanguageCode('fil'));
        $this->assertSame('he', HAXCMSLanguageRegistry::matchLanguageCode('iw'));
    }

    public function testNeverDropsARegionAndRejectsUnknownValues(): void
    {
        $this->assertNull(HAXCMSLanguageRegistry::matchLanguageCode('fr-CA'));
        $this->assertNull(HAXCMSLanguageRegistry::matchLanguageCode('xx'));
        $this->assertNull(HAXCMSLanguageRegistry::matchLanguageCode(''));
        $this->assertNull(HAXCMSLanguageRegistry::matchLanguageCode(null));
        $this->assertNull(HAXCMSLanguageRegistry::matchLanguageCode(5));
    }

    public function testGetLanguageCarriesCanonicalCodeAndLocale(): void
    {
        $zh = HAXCMSLanguageRegistry::getLanguage('zh_CN');
        $this->assertSame('zh-CN', $zh['code']);
        $this->assertSame('zh', $zh['locale']);
        $this->assertNull(HAXCMSLanguageRegistry::getLanguage('nope'));
    }

    public function testListLanguagesFiltersRegionsAndRanksTheTopTen(): void
    {
        $all = HAXCMSLanguageRegistry::listLanguages('all');
        $none = HAXCMSLanguageRegistry::listLanguages('none');
        $featured = HAXCMSLanguageRegistry::listLanguages('featured');
        $this->assertGreaterThan(100, count($none));
        foreach ($none as $entry) {
            $this->assertArrayNotHasKey('parent', $entry);
        }
        $this->assertGreaterThanOrEqual(count($none), count($featured));
        $this->assertLessThanOrEqual(count($all), count($featured));
        $codes = array_column($all, 'code');
        $this->assertContains('en-GB', $codes);
        $this->assertNotContains('en-GB', array_column($none, 'code'));
        $ranked = array_filter($none, function ($l) {
            return isset($l['popular']) && is_int($l['popular']);
        });
        usort($ranked, function ($a, $b) {
            return $a['popular'] - $b['popular'];
        });
        $this->assertSame(
            array('en', 'zh-CN', 'hi', 'es', 'fr', 'ar', 'pt', 'bn', 'ru', 'ja'),
            array_values(array_column($ranked, 'code'))
        );
    }

    public function testRegistryIsByteIdenticalToTheFrontEndCopy(): void
    {
        $built = dirname(__DIR__, 5) . '/build/es6/node_modules/@haxtheweb/i18n-manager/lib/languages.json';
        if (!file_exists($built)) {
            $this->markTestSkipped('front end build not present');
        }
        $this->assertSame(file_get_contents($built), file_get_contents(HAXCMSLanguageRegistry::registryPath()));
    }

    // localization normalization

    public function testDefaultLanguageResolvesThroughTheRegistryFirst(): void
    {
        $n = function ($v) {
            return HAXCMSLocalizationSettingsService::normalizeDefaultLanguageValue($v);
        };
        $this->assertSame('en-US', $n('en_Us'));
        $this->assertSame('zh-CN', $n('zh'));
        $this->assertSame('zh-CN', $n('zh_CN'));
        $this->assertSame('tl', $n('fil'));
        $this->assertSame('he', $n('iw'));
        $this->assertSame('nb', $n('no'));
        // unknown but well formed codes still pass, malformed ones do not
        $this->assertSame('fr-CA', $n('fr-ca'));
        $this->assertNull($n('english'));
        $this->assertNull($n(''));
        $this->assertNull($n(null));
    }

    public function testSupportedLanguagesComeFromTheRegistry(): void
    {
        $map = HAXCMSLocalizationSettingsService::getSupportedLanguages();
        $this->assertGreaterThan(100, count($map));
        $this->assertSame('Swahili', $map['sw']);
    }

    // routes

    public function testListRouteHonorsRegions(): void
    {
        $result = $this->runHandler('v1/languages');
        $this->assertSame(200, $result['data']['status']);
        $codes = array_column($result['data']['data'], 'code');
        $this->assertContains('pt-BR', $codes);
        $none = $this->runHandler('v1/languages', array('regions' => 'none'));
        $this->assertNotContains('pt-BR', array_column($none['data']['data'], 'code'));
        $bogus = $this->runHandler('v1/languages', array('regions' => 'bogus'));
        $this->assertCount(count($result['data']['data']), $bogus['data']['data']);
    }

    public function testListRouteIsGetOnly(): void
    {
        $result = $this->runHandler('v1/languages', array(), array(), 'POST');
        $this->assertSame(405, $result['data']['status']);
    }

    public function testSingleRouteResolvesAliasesAnd404sUnknown(): void
    {
        $result = $this->runHandler('v1/languages/iw', array(), array('code' => 'iw'));
        $this->assertSame(200, $result['data']['status']);
        $this->assertSame('he', $result['data']['data']['code']);
        $this->assertSame('rtl', $result['data']['data']['dir']);
        $missing = $this->runHandler('v1/languages/xx', array(), array('code' => 'xx'));
        $this->assertSame(404, $missing['code']);
        $this->assertSame('Language not found', $missing['data']['data']['message']);
        $this->assertSame('LANGUAGE_NOT_FOUND', $missing['data']['data']['code']);
    }

    public function testTranslationsRouteValidatesInputs(): void
    {
        $missing = $this->runHandler('v1/languages/xx/translations', array(), array('code' => 'xx'));
        $this->assertSame(404, $missing['code']);
        $badNs = $this->runHandler('v1/languages/es/translations', array('namespace' => '../x'), array('code' => 'es'));
        $this->assertSame(400, $badNs['code']);
        $badType = $this->runHandler('v1/languages/es/translations', array('type' => 'zzz'), array('code' => 'es'));
        $this->assertSame(400, $badType['code']);
    }

    public function testTranslationsRouteReturnsLanguageLocaleAndNamespaces(): void
    {
        $root = dirname(__DIR__, 5) . '/build/es6/node_modules/@haxtheweb';
        if (!is_dir($root)) {
            $this->markTestSkipped('front end build not present');
        }
        $result = $this->runHandler(
            'v1/languages/zh_CN/translations',
            array('namespace' => 'video-player'),
            array('code' => 'zh_CN')
        );
        $this->assertSame(200, $result['data']['status']);
        $data = $result['data']['data'];
        $this->assertSame('zh-CN', $data['language']['code']);
        $this->assertSame('zh', $data['locale']);
        $this->assertGreaterThanOrEqual(1, $data['count']);
        $this->assertArrayHasKey('video-player', $data['namespaces']);
    }

    public function testTranslationCollectorFiltersByNamespaceAndType(): void
    {
        $root = sys_get_temp_dir() . '/lang_trans_' . uniqid();
        $put = function ($pkg, $file, $contents) use ($root) {
            @mkdir($root . '/' . $pkg . '/locales', 0777, true);
            file_put_contents($root . '/' . $pkg . '/locales/' . $file, $contents);
        };
        $put('video-player', 'video-player.es.json', json_encode(array('play' => 'Reproducir')));
        $put('video-player', 'video-player.haxProperties.es.json', json_encode(array('title' => 'Video')));
        $put('video-player', 'video-player.fr.json', json_encode(array('play' => 'Lire')));
        $put('a11y-tabs', 'a11y-tabs.es.json', json_encode(array('tab' => 'Pestaña')));
        $put('a11y-tabs', 'broken.es.json', '{not json');
        $put('a11y-tabs', 'list.es.json', '[1,2]');

        $ops = new Operations();
        $call = new ReflectionMethod($ops, 'collectLanguageTranslations');
        $call->setAccessible(true);
        $all = $call->invoke($ops, 'es', '', 'all', $root);
        $this->assertSame(3, $all['count']);
        $keys = array_keys($all['namespaces']);
        sort($keys);
        $this->assertSame(array('a11y-tabs', 'video-player', 'video-player.haxProperties'), $keys);
        $this->assertSame('ui', $all['namespaces']['video-player']['type']);
        $this->assertSame('@haxtheweb/video-player', $all['namespaces']['video-player']['package']);
        $this->assertSame('schema', $all['namespaces']['video-player.haxProperties']['type']);

        $one = $call->invoke($ops, 'es', 'video-player', 'all', $root);
        $this->assertSame(2, $one['count']);
        $ui = $call->invoke($ops, 'es', '', 'ui', $root);
        $this->assertSame(2, $ui['count']);
        $schema = $call->invoke($ops, 'es', '', 'schema', $root);
        $this->assertSame(array('video-player.haxProperties'), array_keys($schema['namespaces']));
        $this->assertSame(0, $call->invoke($ops, '../x', '', 'all', $root)['count']);
        $this->assertSame(0, $call->invoke($ops, 'es', '', 'all', $root . '/missing')['count']);
    }
}
