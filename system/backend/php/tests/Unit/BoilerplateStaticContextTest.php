<?php
use PHPUnit\Framework\TestCase;

/**
 * index.html serves the live site, where HAXcms asks the backend for its
 * connection settings ("php" context). ghpages.html is the static copy (the
 * HAX CLI swaps it in as index.html for surge / netlify / vercel, and it is
 * what GitHub Pages publishing uses), where no backend exists: it says
 * "published", so HAXcms neither asks for one (a request that can only 404)
 * nor loads the editor. haxcms-nodejs keeps the same rule
 * (test/unit/boilerplate-static-context.test.cjs).
 */
class BoilerplateStaticContextTest extends TestCase
{
    private function contexts(string $file): array
    {
        $html = file_get_contents(__DIR__ . '/../../../../boilerplate/site/' . $file);
        $this->assertNotFalse($html, $file . ' exists');
        preg_match_all('/globalThis\.HAXCMSContext\s*=\s*"([^"]*)"/', $html, $m);
        return $m[1];
    }

    public function testTheLivePageAsksTheBackend(): void
    {
        $this->assertSame(array('php'), $this->contexts('index.html'));
    }

    public function testTheStaticCopyNeverAsksForABackend(): void
    {
        $this->assertSame(array('published'), $this->contexts('ghpages.html'));
    }
}
