<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/SiteRoutesTestHelpers.php';

/**
 * Route-level characterization tests for lib/siteRoutes/v1/themes.php.
 * These tests deliberately avoid setting $GLOBALS['HAXCMS'], which exercises
 * the route's fallback path: a single theme record derived from
 * site->manifest->metadata->theme, always enabled and active. Discovering
 * multiple themes via HAXCMSThemeSettingsService requires a real HAXCMS
 * instance and is out of scope for this characterization layer.
 * See SiteRoutesItemsTest.php for the shared invocation pattern rationale.
 */
class SiteRoutesThemesTest extends TestCase
{
    protected function setUp(): void
    {
        unset($_GET);
        $_GET = array();
        unset($GLOBALS['HAXCMS']);
    }

    private function buildSiteWithTheme($themeElement)
    {
        $site = new SiteRoutesFakeSite();
        $site->manifest->metadata->theme = new stdClass();
        $site->manifest->metadata->theme->element = $themeElement;
        return $site;
    }

    public function testMissingSiteReturns404(): void
    {
        $context = makeSiteRouteContext(null, array(), 'v1/themes');
        $result = invokeSiteRouteHandler('themes.php', $context);
        $this->assertSame(404, $result['data']['status']);
    }

    public function testListReturnsFallbackThemeRecordWhenNoHaxcmsGlobal(): void
    {
        $site = $this->buildSiteWithTheme('clean-two');
        $context = makeSiteRouteContext($site, array(), 'v1/themes');
        $result = invokeSiteRouteHandler('themes.php', $context);
        $data = $result['data']['data'];
        $this->assertSame(1, $data['count']);
        $record = $data['themes'][0];
        $this->assertSame('clean-two', $record['machineName']);
        $this->assertTrue($record['enabled']);
        $this->assertTrue($record['active']);
        $this->assertSame('/x/api/v1/themes/clean-two', $record['links']['self']);
    }

    public function testActiveThemeRouteReturnsFallbackRecord(): void
    {
        $site = $this->buildSiteWithTheme('clean-two');
        $context = makeSiteRouteContext($site, array(), 'v1/themes/active');
        $result = invokeSiteRouteHandler('themes.php', $context);
        $data = $result['data']['data'];
        $this->assertSame('clean-two', $data['machineName']);
        $this->assertTrue($data['active']);
    }

    public function testThemeDetailByMachineNameIsCaseInsensitive(): void
    {
        $site = $this->buildSiteWithTheme('clean-two');
        $context = makeSiteRouteContext($site, array('themeName' => 'CLEAN-TWO'), 'v1/themes/CLEAN-TWO');
        $result = invokeSiteRouteHandler('themes.php', $context);
        $data = $result['data']['data'];
        $this->assertSame('clean-two', $data['machineName']);
    }

    public function testThemeDetailUnknownNameReturns404(): void
    {
        $site = $this->buildSiteWithTheme('clean-two');
        $context = makeSiteRouteContext($site, array('themeName' => 'nope'), 'v1/themes/nope');
        $result = invokeSiteRouteHandler('themes.php', $context);
        $this->assertSame(404, $result['data']['status']);
    }

    public function testFieldsProjectionAppliesToDetail(): void
    {
        $site = $this->buildSiteWithTheme('clean-two');
        $_GET['fields'] = 'machineName,enabled';
        $context = makeSiteRouteContext($site, array('themeName' => 'clean-two'), 'v1/themes/clean-two');
        $result = invokeSiteRouteHandler('themes.php', $context);
        $data = $result['data']['data'];
        $this->assertArrayHasKey('machineName', $data);
        $this->assertArrayHasKey('enabled', $data);
        $this->assertArrayNotHasKey('active', $data);
    }
}
