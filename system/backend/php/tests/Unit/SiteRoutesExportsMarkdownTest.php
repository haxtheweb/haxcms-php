<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/SiteRoutesTestHelpers.php';

/**
 * GET x/api/v1/site/export/md: the whole site as one markdown document in
 * outline order (also served as llms-full.txt).
 */
class SiteRoutesExportsMarkdownSidecarSite extends SiteRoutesFakeSite
{
    public $directory;
    public function getPageAlternateLocation($location = '', $extension = 'json')
    {
        return preg_replace('/\.html?$/i', '.' . $extension, (string) $location);
    }
}

class SiteRoutesExportsMarkdownTest extends TestCase
{
    private $tmpDir;

    protected function setUp(): void
    {
        unset($_GET);
        $_GET = array();
        unset($GLOBALS['HAXCMS'], $_SERVER['HTTP_IF_NONE_MATCH']);
    }

    protected function tearDown(): void
    {
        unset($_SERVER['HTTP_IF_NONE_MATCH']);
        if ($this->tmpDir && is_dir($this->tmpDir)) {
            exec('rm -rf ' . escapeshellarg($this->tmpDir));
        }
    }

    private function buildSite(): SiteRoutesFakeSite
    {
        $site = new SiteRoutesFakeSite();
        $site->manifest->title = 'Demo Course';
        $site->manifest->description = 'A short course';
        $site->manifest->items = array(
            makeSiteRouteItem('a', 'intro', 'Introduction', '', 1),
            makeSiteRouteItem('b', 'draft', 'Draft Page', '', 2, 0, '', '', array('published' => false)),
            makeSiteRouteItem('c', 'wrap-up', 'Wrap Up', '', 3),
        );
        $site->pageContentMap = array(
            'a' => '<p>Welcome <strong>learners</strong></p>',
            'b' => '<p>secret-draft</p>',
            'c' => '<p>Goodbye</p>',
        );
        return $site;
    }

    private function exportMd($site, $authenticated)
    {
        $context = makeSiteRouteContext($site, array('format' => 'md'), 'v1/site/export/md', '/x/api', $authenticated);
        return invokeSiteRouteHandler('exports.php', $context)['raw'];
    }

    public function testAnonymousMarkdownIsOrderedAndExcludesDrafts(): void
    {
        $md = $this->exportMd($this->buildSite(), false);
        $this->assertStringStartsWith("# Demo Course\n\n> A short course\n", $md);
        $this->assertStringContainsString('## Introduction', $md);
        $this->assertStringContainsString('Welcome **learners**', $md);
        $this->assertStringContainsString('Source: /testsite/intro', $md);
        $this->assertStringNotContainsString('secret-draft', $md);
        $this->assertLessThan(strpos($md, '## Wrap Up'), strpos($md, '## Introduction'));
    }

    public function testAuthenticatedMarkdownIncludesDrafts(): void
    {
        $md = $this->exportMd($this->buildSite(), true);
        $this->assertStringContainsString('secret-draft', $md);
    }

    public function testAbsoluteSourceUrlsWhenSiteHasDomain(): void
    {
        $site = $this->buildSite();
        $site->manifest->metadata->site->domain = 'https://example.org/course/';
        $md = $this->exportMd($site, false);
        $this->assertStringContainsString('Source: https://example.org/course/wrap-up', $md);
    }

    public function testMatchingIfNoneMatchSendsNoBody(): void
    {
        $site = $this->buildSite();
        $md = $this->exportMd($site, false);
        $_SERVER['HTTP_IF_NONE_MATCH'] = '"' . sha1($md) . '"';
        $this->assertSame('', $this->exportMd($site, false));
    }

    public function testSavedMarkdownSidecarIsUsedWhenPresent(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/haxcms-md-export-' . getmypid();
        @mkdir($this->tmpDir . '/testsite/pages/a', 0777, true);
        file_put_contents($this->tmpDir . '/testsite/pages/a/index.md', "Saved sidecar markdown\n");
        $site = new SiteRoutesExportsMarkdownSidecarSite();
        $site->directory = $this->tmpDir;
        $site->manifest->items = array(makeSiteRouteItem('a', 'intro', 'Introduction', '', 1));
        $site->pageContentMap = array('a' => '<p>html version</p>');
        $md = $this->exportMd($site, false);
        $this->assertStringContainsString('Saved sidecar markdown', $md);
        $this->assertStringNotContainsString('html version', $md);
    }
}
