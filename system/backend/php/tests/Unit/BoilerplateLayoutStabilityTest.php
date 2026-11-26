<?php
use PHPUnit\Framework\TestCase;

/**
 * The site shells keep server-rendered content from shifting before the site
 * builder loads: every haxcms-site-builder rule is a block formatting context
 * (display: flow-root) so child margins can't collapse through it, and
 * images rendered before the builder loads keep their aspect ratio. Mirrors
 * haxcms-nodejs test/unit/boilerplate-site-shell-load.test.cjs.
 */
class BoilerplateLayoutStabilityTest extends TestCase
{
    public static function files(): array
    {
        return array(
            array('site/index.html'),
            array('site/ghpages.html'),
            array('site/index.php'),
        );
    }

    /**
     * @dataProvider files
     */
    public function testServerRenderedContentDoesNotShift(string $file): void
    {
        $html = file_get_contents(__DIR__ . '/../../../../boilerplate/' . $file);
        $this->assertNotFalse($html, $file . ' exists');
        preg_match_all('/haxcms-site-builder \{[^}]*\}/', $html, $rules);
        $this->assertNotEmpty($rules[0], 'haxcms-site-builder rule found');
        // rules that only toggle visibility (hidden under the loading screen,
        // shown without JS, haxtheweb/issues#3428) don't set display
        $displayRules = array_filter($rules[0], function ($rule) {
            return strpos($rule, 'display') !== false;
        });
        $this->assertNotEmpty($displayRules, 'haxcms-site-builder display rule found');
        foreach ($displayRules as $rule) {
            $this->assertStringContainsString('display: flow-root', $rule);
            $this->assertStringNotContainsString('display: block', $rule);
        }
        $this->assertSame(
            1,
            preg_match('/haxcms-site-builder:not\(:defined\) img \{([^}]*)\}/', $html, $img),
            'pre-render image rule found'
        );
        $this->assertStringContainsString('max-width: 100%', $img[1]);
        $this->assertStringContainsString('height: auto', $img[1]);
    }
}
