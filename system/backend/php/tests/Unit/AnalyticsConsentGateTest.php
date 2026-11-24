<?php
use PHPUnit\Framework\TestCase;

/**
 * Google Analytics consent gate (haxtheweb/issues#2955). Whenever a gaID is
 * set, getGaCode() prints a loader that fetches nothing from Google until the
 * HaxConsent store (localStorage "hax-consent") says "allow" for service
 * "google-analytics". There is no setting that turns the gate off.
 * The fallback HAXSiteConfig in boilerplate/site/config.php prints the same.
 */
class AnalyticsConsentGateTest extends TestCase
{
    private function siteWithGaID($gaID)
    {
        $site = new HAXCMSSite();
        $site->manifest = new stdClass();
        $site->manifest->metadata = new stdClass();
        $site->manifest->metadata->site = new stdClass();
        $site->manifest->metadata->site->settings = new stdClass();
        $site->manifest->metadata->site->settings->gaID = $gaID;
        return $site;
    }

    public function testNoGaIDPrintsNothing(): void
    {
        $this->assertSame('', $this->siteWithGaID('')->getGaCode());
        $this->assertSame('', $this->siteWithGaID(null)->getGaCode());
    }

    public function testMalformedGaIDPrintsNothing(): void
    {
        $this->assertSame('', $this->siteWithGaID("G-1');alert(1);//")->getGaCode());
        $this->assertSame('', $this->siteWithGaID('<script>')->getGaCode());
    }

    public function testGaIDIsGatedOnConsent(): void
    {
        $code = $this->siteWithGaID('G-ABC123')->getGaCode();
        // nothing eager: no script tag with a Google src, no config call outside load()
        $this->assertStringNotContainsString('<script async src=', $code);
        $this->assertDoesNotMatchRegularExpression('/<script[^>]*src=/', $code);
        // reads the one consent store and only loads on "allow"
        $this->assertStringContainsString("localStorage.getItem('hax-consent')", $code);
        $this->assertStringContainsString("choices['google-analytics']", $code);
        $this->assertStringContainsString("consent() !== 'allow'", $code);
        $this->assertStringContainsString('hax-consent-changed', $code);
        // withdrawing turns measurement off again
        $this->assertStringContainsString("window['ga-disable-' + gaid]", $code);
        $this->assertStringContainsString("var gaid = 'G-ABC123';", $code);
    }

    /**
     * The static shells (index.html, ghpages.html) load Google Analytics in
     * the browser; they apply the same gate (see haxcms-nodejs
     * test/unit/boilerplate-analytics-consent.test.cjs, which runs it).
     */
    public function testSiteShellsGateAnalyticsOnConsent(): void
    {
        foreach (['index.html', 'ghpages.html'] as $shell) {
            $html = file_get_contents(__DIR__ . '/../../../../boilerplate/site/' . $shell);
            $this->assertStringContainsString("localStorage.getItem('hax-consent')", $html, $shell);
            $this->assertStringContainsString("choices['google-analytics']", $html, $shell);
            $this->assertStringContainsString("analyticsConsent() !== 'allow'", $html, $shell);
            $this->assertStringContainsString('hax-consent-changed', $html, $shell);
            $this->assertDoesNotMatchRegularExpression('/<script[^>]*googletagmanager/', $html, $shell);
        }
    }

    public function testUniversalAnalyticsIDIsGatedToo(): void
    {
        $code = $this->siteWithGaID('UA-12345-1')->getGaCode();
        $this->assertStringContainsString("var gaid = 'UA-12345-1';", $code);
        $this->assertStringContainsString("consent() !== 'allow'", $code);
    }
}
