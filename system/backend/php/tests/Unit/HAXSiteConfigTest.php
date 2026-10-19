<?php
use PHPUnit\Framework\TestCase;

/**
 * Tests for lib/HAXSiteConfig.php, the bridge index.php uses to render a
 * site shell. Under the CLI there is no REQUEST_URI, so SiteApiRouter's
 * dispatch() declines and the constructor proceeds; the two exit() branches
 * (API dispatch, page-variant response) are not exercised because they end
 * the process.
 */
require_once __DIR__ . '/../../lib/HAXSiteConfig.php';

class HAXSiteConfigTestSite
{
    public $name = 'cfg-site';
    public $manifest;
    public $lastPathLookupMiss = false;
    public $alternateHeadersFor = 'unset';
    public $calls = array();

    public function __construct()
    {
        $this->manifest = json_decode('{"metadata":{"theme":{"variables":{"cssVariable":"--simple-colors-default-theme-blue-7"}}}}');
    }

    public function loadNodeByLocation()
    {
        return (object) array('id' => 'page-1');
    }

    public function sendPageAlternateHeaders($page)
    {
        $this->alternateHeadersFor = $page;
    }

    public function __call($name, $args)
    {
        $this->calls[] = array($name, $args);
        return 'site:' . $name;
    }
}

class HAXSiteConfigTestHaxcms
{
    public function cacheBusterHash()
    {
        return 'hash123';
    }

    public function getCDNForDynamic($site)
    {
        return 'cdn:' . $site->name;
    }
}

class HAXSiteConfigTest extends TestCase
{
    private $savedHaxcms;
    private $savedUri;

    protected function setUp(): void
    {
        $this->savedHaxcms = isset($GLOBALS['HAXCMS']) ? $GLOBALS['HAXCMS'] : null;
        $GLOBALS['HAXCMS'] = new HAXSiteConfigTestHaxcms();
        $this->savedUri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : null;
        unset($_SERVER['REQUEST_URI']);
    }

    protected function tearDown(): void
    {
        if ($this->savedHaxcms !== null) {
            $GLOBALS['HAXCMS'] = $this->savedHaxcms;
        } else {
            unset($GLOBALS['HAXCMS']);
        }
        if ($this->savedUri !== null) {
            $_SERVER['REQUEST_URI'] = $this->savedUri;
        }
        http_response_code(200);
    }

    public function testConstructorResolvesPageColorAndName(): void
    {
        $site = new HAXSiteConfigTestSite();
        $config = new HAXSiteConfig($site);
        $this->assertSame('page-1', $config->page->id);
        $this->assertSame($config->page, $site->alternateHeadersFor);
        $this->assertSame('var(--simple-colors-default-theme-blue-7, #FF2222)', $config->color);
        $this->assertSame('cfg-site', $config->name);
        $this->assertFalse($config->isPageNotFound());
    }

    public function testPathLookupMissFlagsNotFound(): void
    {
        $site = new HAXSiteConfigTestSite();
        $site->lastPathLookupMiss = true;
        $config = new HAXSiteConfig($site);
        $this->assertTrue($config->isPageNotFound());
        $this->assertSame(404, http_response_code());
    }

    public function testDelegatesToSiteAndHaxcms(): void
    {
        $site = new HAXSiteConfigTestSite();
        $config = new HAXSiteConfig($site);
        $this->assertSame('site:getLanguage', $config->getLanguage());
        $this->assertSame('site:getBaseTag', $config->getBaseTag());
        $this->assertSame('site:getSiteMetadata', $config->getSiteMetadata('p'));
        $this->assertSame('site:getServiceWorkerStatus', $config->getServiceWorkerStatus());
        $this->assertSame('site:getServiceWorkerScript', $config->getServiceWorkerScript());
        $this->assertSame('site:getSitePageAttributes', $config->getSitePageAttributes());
        $this->assertSame('site:getPageContent', $config->getPageContent('p'));
        $this->assertSame('site:getGaCode', $config->getGaCode());
        $this->assertSame('site:getGaID', $config->getGaID());
        $this->assertSame('hash123', $config->cacheBusterHash());
        $this->assertSame('cdn:cfg-site', $config->getCDNForDynamic());
        // service worker script is built with the site's own SW status
        $swCall = null;
        foreach ($site->calls as $call) {
            if ($call[0] === 'getServiceWorkerScript') {
                $swCall = $call[1];
            }
        }
        $this->assertSame(array(null, false, 'site:getServiceWorkerStatus'), $swCall);
    }

    public function testPageMissShellMarkupIsAnAccessibleAlert(): void
    {
        $config = new HAXSiteConfig(new HAXSiteConfigTestSite());
        $markup = $config->getPageMissShellMarkup();
        $this->assertStringContainsString('role="alert"', $markup);
        $this->assertStringContainsString('The page miss, it burns!', $markup);
    }
}
