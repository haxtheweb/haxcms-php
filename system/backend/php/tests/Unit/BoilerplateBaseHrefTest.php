<?php
use PHPUnit\Framework\TestCase;

/**
 * haxtheweb/issues#3202: on *.github.io the site boilerplate takes its
 * <base href> from the URL's own folder, so a site at any depth
 * (org.github.io/, /repo/ or /repo/folder/) loads. The behavior itself is
 * exercised in haxcms-nodejs test/unit/boilerplate-base-href.test.cjs
 * against the identical script; this guards that both PHP copies carry it.
 */
class BoilerplateBaseHrefTest extends TestCase
{
    public static function files(): array
    {
        return array(array('index.html'), array('ghpages.html'));
    }

    /**
     * @dataProvider files
     */
    public function testGithubIoBaseUsesTheUrlFolder(string $file): void
    {
        $html = file_get_contents(__DIR__ . '/../../../../boilerplate/site/' . $file);
        $this->assertNotFalse($html, $file . ' exists');
        $this->assertStringContainsString(
            "document.location.pathname.substring(0, document.location.pathname.lastIndexOf('/') + 1)",
            $html
        );
        $this->assertStringNotContainsString("pathname.split('/')[1]", $html, 'first-segment rule removed');
    }
}
