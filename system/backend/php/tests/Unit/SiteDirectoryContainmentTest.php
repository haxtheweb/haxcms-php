<?php
use PHPUnit\Framework\TestCase;

/**
 * Security (code-scanning js/path-injection, haxcms-nodejs parity): a site
 * name that collapses to the sites directory itself ("/", "//") must never
 * load that directory as a site. Mirrors haxcms-nodejs
 * test/unit/site-directory-containment.test.cjs.
 *
 * The real HAXCMS::cleanTitle() keeps "/" (it only maps characters outside
 * [\w-/] to "-"), so a passthrough stand-in reproduces what load() receives.
 * The suite's shared OperationsTestHaxcms mock trims slashes and cannot.
 */
class SiteDirectoryContainmentTest extends TestCase
{
    private $savedHaxcms;
    private $sitesDir;

    protected function setUp(): void
    {
        if (isset($GLOBALS['HAXCMS'])) {
            $this->savedHaxcms = $GLOBALS['HAXCMS'];
        }
        $GLOBALS['HAXCMS'] = new class {
            public function cleanTitle($value, $stripPage = true)
            {
                return trim((string) $value);
            }
        };
        $this->sitesDir = sys_get_temp_dir() . '/haxcms-site-contain-' . bin2hex(random_bytes(6));
        mkdir($this->sitesDir . '/childsite', 0777, true);
        // a site.json at the sites root must never be loaded as a site
        file_put_contents($this->sitesDir . '/site.json', '{"items":[]}');
        file_put_contents($this->sitesDir . '/childsite/site.json', '{"items":[]}');
    }

    protected function tearDown(): void
    {
        if ($this->savedHaxcms !== null) {
            $GLOBALS['HAXCMS'] = $this->savedHaxcms;
        } else {
            unset($GLOBALS['HAXCMS']);
        }
        @unlink($this->sitesDir . '/childsite/site.json');
        @rmdir($this->sitesDir . '/childsite');
        @unlink($this->sitesDir . '/site.json');
        @rmdir($this->sitesDir);
    }

    public function testLoadRefusesNamesThatCollapseToTheSitesDirectory()
    {
        foreach (array('/', '%2F', '//') as $name) {
            $site = new HAXCMSSite();
            try {
                $site->load($this->sitesDir, '/sites/', $name);
                $this->fail('load() accepted ' . $name);
            } catch (\Exception $e) {
                $this->assertStringContainsString('outside the sites directory', $e->getMessage());
            }
            $this->assertNull($site->manifest, $name);
        }
    }

    public function testLeadingSlashStillNamesAChildSite()
    {
        $site = new HAXCMSSite();
        $site->load($this->sitesDir, '/sites/', '/childsite');
        $this->assertNotNull($site->manifest);
    }
}
