<?php
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/HAXCMSDashboardRoutes.php';

/**
 * lib/HAXCMSDashboardRoutes.php: which paths the dashboard answers as pages.
 * haxcms-nodejs keeps the same list (src/lib/dashboardRoutes.js); the cases
 * here match its test/unit/dashboard-routes.test.cjs.
 */
class HAXCMSDashboardRoutesTest extends TestCase
{
    public function testDashboardPagesAreRoutes()
    {
        foreach (array(
            '/', '', '/home', '/home/', '/index.html', '/index.php',
            '/search', '/search?q=x', '/createSite-step-1', '/createSite-step-3',
        ) as $path) {
            $this->assertTrue(HAXCMSDashboardRoutes::isDashboardRoute($path), $path);
        }
    }

    public function testAnythingElseIsNotADashboardRoute()
    {
        foreach (array(
            '/totally-random-page', '/homework', '/search/deeper',
            '/createSite', '/x/api', '/index.htm',
        ) as $path) {
            $this->assertFalse(HAXCMSDashboardRoutes::isDashboardRoute($path), $path);
        }
    }

    public function testPathsAreReadRelativeToTheBasePath()
    {
        $this->assertSame('search', HAXCMSDashboardRoutes::relativePath('/hax/search', '/hax/'));
        $this->assertSame('', HAXCMSDashboardRoutes::relativePath('/hax/', '/hax/'));
        $this->assertSame('', HAXCMSDashboardRoutes::relativePath('/hax', '/hax/'));
        $this->assertTrue(HAXCMSDashboardRoutes::isDashboardRoute('/hax/home', '/hax/'));
        $this->assertFalse(HAXCMSDashboardRoutes::isDashboardRoute('/hax/nope', '/hax/'));
        // a path outside the base path is read as given
        $this->assertSame('other/home', HAXCMSDashboardRoutes::relativePath('/other/home', '/hax/'));
    }
}
