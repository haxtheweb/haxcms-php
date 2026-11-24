<?php
use PHPUnit\Framework\TestCase;

/**
 * The magic script loaders (build.js, build-haxcms.js) come from the
 * webcomponents build, which writes them to exactly two places here: the
 * server's own copy (the install root) and the copy stamped into every site
 * (system/boilerplate/site). Any other copy is never rebuilt, so it goes
 * stale while still being served (haxtheweb/issues#3337). Mirrors
 * haxcms-nodejs test/unit/loader-copies.test.cjs.
 */
class LoaderCopiesTest extends TestCase
{
    private const LOADERS = array('build.js', 'build-haxcms.js');

    private static function root(): string
    {
        return dirname(__DIR__, 5);
    }

    private static function read(string $relativePath): string
    {
        $contents = file_get_contents(self::root() . '/' . $relativePath);
        self::assertNotFalse($contents, $relativePath . ' exists');
        return $contents;
    }

    public function testNoLoaderCopyUnderAssets(): void
    {
        foreach (self::LOADERS as $loader) {
            $this->assertFileDoesNotExist(self::root() . '/assets/' . $loader);
        }
    }

    public function testOnlyTheSiteCopyIsInTheBoilerplate(): void
    {
        $found = array();
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(self::root() . '/system/boilerplate', FilesystemIterator::SKIP_DOTS)
        );
        foreach ($files as $file) {
            if (in_array($file->getFilename(), self::LOADERS, true)) {
                $found[] = str_replace('\\', '/', substr($file->getPathname(), strlen(self::root()) + 1));
            }
        }
        sort($found);
        $this->assertSame(
            array('system/boilerplate/site/build-haxcms.js', 'system/boilerplate/site/build.js'),
            $found
        );
    }

    public function testBothPlacesHoldTheSameFile(): void
    {
        foreach (self::LOADERS as $loader) {
            $this->assertSame(
                self::read($loader),
                self::read('system/boilerplate/site/' . $loader),
                $loader
            );
        }
    }

    public function testBuildJsMovesToTheNextCdnOnATimeout(): void
    {
        $this->assertStringContainsString('WCGlobalCDNTimeout', self::read('build.js'));
    }

    public function testBuildHaxcmsJsDoesNotAddThemeCss(): void
    {
        $this->assertStringNotContainsString('theme/theme.css', self::read('build-haxcms.js'));
    }
}
