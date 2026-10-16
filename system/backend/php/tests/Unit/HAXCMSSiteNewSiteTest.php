<?php
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/OperationsTestHaxcms.php';

/**
 * Characterization tests for HAXCMSSite::newSite() and its addPage()
 * integration during site creation (lib/HAXCMSSite.php).
 *
 * Unlike the mutation-seam tests, these exercise the REAL implementation
 * end to end against the real system/boilerplate tree (symlinked into
 * HAXCMS_ROOT by tests/phpunit-bootstrap.php): boilerplate site copy,
 * manifest scaffolding, real addPage() page creation with template copy +
 * alternate-format sidecar writes, and a real `git init` via Git::create.
 * Only the terminal collaborators (gitCommit, updateAlternateFormats) are
 * stubbed with call recorders, since those are covered by GitWrapperTest /
 * RSSFeedTest and are irrelevant to the newSite contract.
 *
 * Note: for 'html' template pages (from-skeleton / import builds) the
 * in-content writeLocation() targets HAXCMS_ROOT/_sites/... which does not
 * exist in the fixture, so its @-suppressed write is expected to no-op;
 * assertions for those pages are made at the manifest level only.
 */
class HAXCMSSiteNewSiteTest extends TestCase
{
    private $haxcms;
    private $savedHaxcms;
    private $tmpRoot;
    private $site;

    protected function setUp(): void
    {
        if (isset($GLOBALS['HAXCMS'])) {
            $this->savedHaxcms = $GLOBALS['HAXCMS'];
        }
        $this->tmpRoot = sys_get_temp_dir() . '/haxcms_new_site_' . uniqid();
        mkdir($this->tmpRoot);
        $this->haxcms = new HAXCMSNewSiteTestHaxcms();
        $GLOBALS['HAXCMS'] = $this->haxcms;
        $this->site = new HAXCMSNewSiteTestSite();
    }

    protected function tearDown(): void
    {
        if (isset($this->savedHaxcms)) {
            $GLOBALS['HAXCMS'] = $this->savedHaxcms;
            $this->savedHaxcms = null;
        } else {
            unset($GLOBALS['HAXCMS']);
        }
        $this->rrmdir($this->tmpRoot);
    }

    private function rrmdir($dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_link($path)) {
                @unlink($path);
            } elseif (is_dir($path)) {
                $this->rrmdir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    /**
     * Run newSite with the default single-site scenario ('My Site' ->
     * cleaned to 'my-site' by the OperationsTestHaxcms::cleanTitle mock).
     */
    private function createSite($name = 'My Site', $gitDetails = null, $domain = null, $build = null)
    {
        if ($gitDetails === null) {
            $gitDetails = new stdClass();
        }
        return $this->site->newSite($this->tmpRoot, '/', $name, $gitDetails, $domain, $build);
    }

    private function siteDir($name = 'my-site'): string
    {
        return $this->tmpRoot . '/' . $name;
    }

    private function manifestJson($name = 'my-site'): object
    {
        $raw = file_get_contents($this->siteDir($name) . '/site.json');
        $this->assertIsString($raw);
        return json_decode($raw);
    }

    // ---- happy path: directory + boilerplate + managed files ----

    public function testNewSiteReturnsSiteInstanceAndCreatesDirectoryWithBoilerplate(): void
    {
        $result = $this->createSite();
        // newSite returns $this so callers can chain loadSite-style usage
        $this->assertSame($this->site, $result);
        $this->assertDirectoryExists($this->siteDir());
        // boilerplate site tree was copied in
        $this->assertFileExists($this->siteDir() . '/index.html');
        $this->assertFileExists($this->siteDir() . '/index.php');
        $this->assertFileExists($this->siteDir() . '/config.php');
        $this->assertFileExists($this->siteDir() . '/site.json');
        $this->assertFileExists($this->siteDir() . '/robots.txt');
        $this->assertFileExists($this->siteDir() . '/service-worker.js');
        // and the managed-file rebuild landed its templates on top
        $this->assertFileExists($this->siteDir() . '/manifest.json');
        $this->assertFileExists($this->siteDir() . '/404.html');
        $this->assertFileExists($this->siteDir() . '/files/.htaccess');
    }

    // ---- manifest core metadata scaffolding ----

    public function testNewSiteScaffoldsManifestCoreMetadata(): void
    {
        $this->createSite('My Site');
        $manifest = $this->manifestJson();
        $this->assertSame('My Site', $manifest->title);
        // location = basePath + tmpname + /index.html
        $this->assertSame('/my-site/index.html', $manifest->location);
        $this->assertSame('/', $this->site->basePath);
        // directory holds the PARENT directory; the site subdirectory is the name
        $this->assertSame($this->tmpRoot, $this->site->directory);
        $this->assertSame('My Site', $this->site->name);
        // site block
        $this->assertSame('my-site', $manifest->metadata->site->name);
        // settings defaults
        $this->assertSame('en', $manifest->metadata->site->settings->lang);
        $this->assertFalse($manifest->metadata->site->settings->private);
        $this->assertTrue($manifest->metadata->site->settings->canonical);
        $this->assertTrue($manifest->metadata->site->settings->pathauto);
        // author / theme / node scaffolds
        $this->assertEquals(new stdClass(), $manifest->metadata->author);
        $this->assertEquals(new stdClass(), $manifest->metadata->theme->variables);
        $this->assertEquals(new stdClass(), $manifest->metadata->node->fields);
        // platform scaffold
        $this->assertSame('expert', $manifest->metadata->platform->audience);
        $this->assertEquals(new stdClass(), $manifest->metadata->platform->features);
        $this->assertSame(array(), $manifest->metadata->platform->allowedBlocks);
        // created / updated stamped with a plausible time
        $this->assertGreaterThan(0, $manifest->metadata->site->created);
        $this->assertGreaterThan(0, $manifest->metadata->site->updated);
    }

    // ---- default build: welcome page ----

    public function testNewSiteAddsWelcomePageByDefault(): void
    {
        $this->createSite();
        $manifest = $this->manifestJson();
        $this->assertCount(1, $manifest->items);
        $page = $manifest->items[0];
        $this->assertSame('Welcome', $page->title);
        $this->assertSame('welcome', $page->slug);
        $this->assertSame(0, $page->order);
        $this->assertNull($page->parent);
        $this->assertSame(0, $page->indent);
        // location reflects the generated machine-name id
        $this->assertStringStartsWith('pages/', $page->location);
        $this->assertStringEndsWith('/index.html', $page->location);
        $this->assertSame('pages/' . $page->id . '/index.html', $page->location);
        // the 'init' template boilerplate page was copied to disk...
        $this->assertFileExists($this->siteDir() . '/' . $page->location);
        // ...and the alternate-format sidecars were written beside it
        $this->assertFileExists($this->siteDir() . '/pages/' . $page->id . '/index.md');
        $this->assertFileExists($this->siteDir() . '/pages/' . $page->id . '/index.json');
    }

    // ---- name collision handling ----

    public function testNewSiteAppendsNumericSuffixOnNameCollision(): void
    {
        // pre-create the directory the cleaned name would occupy
        mkdir($this->siteDir('my-site'));
        $this->createSite('My Site');
        // the site lands in my-site-1 instead
        $this->assertDirectoryExists($this->siteDir('my-site-1'));
        $this->assertFileExists($this->siteDir('my-site-1') . '/site.json');
        $manifest = $this->manifestJson('my-site-1');
        $this->assertSame('my-site-1', $manifest->metadata->site->name);
        $this->assertSame('/my-site-1/index.html', $manifest->location);
    }

    // ---- domain resolution: github.io default vs CNAME ----

    public function testNewSiteDefaultsDomainToGithubPagesFromGitUser(): void
    {
        $gitDetails = new stdClass();
        $gitDetails->user = 'btopro';
        $this->createSite('My Site', $gitDetails);
        $manifest = $this->manifestJson();
        $this->assertSame('https://btopro.github.io/my-site', $manifest->metadata->site->domain);
        // github.io mode: the CNAME from the boilerplate copy is left untouched
        // (the else-branch writer never ran) — it must not gain the github.io url
        $this->assertStringNotContainsString('btopro.github.io', (string) file_get_contents($this->siteDir() . '/CNAME'));
        // and no remote is configured (gitDetails->url not set)
        $this->assertSame('', trim((string) shell_exec('git -C ' . escapeshellarg($this->siteDir()) . ' remote')));
    }

    public function testNewSiteExplicitDomainWritesCnameFile(): void
    {
        $gitDetails = new stdClass();
        $gitDetails->user = 'btopro';
        $this->createSite('My Site', $gitDetails, 'https://my.example.com');
        $this->assertFileExists($this->siteDir() . '/CNAME');
        $this->assertSame('https://my.example.com', file_get_contents($this->siteDir() . '/CNAME'));
        $manifest = $this->manifestJson();
        $this->assertSame('https://my.example.com', $manifest->metadata->site->domain);
    }

    public function testNewSiteWithoutUserOrDomainWritesEmptyCname(): void
    {
        // no gitDetails->user and no explicit domain: the else branch writes
        // an (empty) CNAME and the manifest domain stays null
        $this->createSite('My Site', new stdClass(), null);
        $this->assertFileExists($this->siteDir() . '/CNAME');
        $this->assertSame('', file_get_contents($this->siteDir() . '/CNAME'));
        $manifest = $this->manifestJson();
        $this->assertNull($manifest->metadata->site->domain);
    }

    // ---- build structures ----

    public function testNewSiteFromSkeletonBuildCreatesPagesFromItems(): void
    {
        $build = new stdClass();
        $build->structure = 'from-skeleton';
        $build->items = array(
            array('title' => 'One', 'slug' => 'one', 'content' => '<p>One</p>'),
            array('title' => 'Two', 'slug' => 'two', 'content' => '<p>Two</p>'),
            // 'contents' (plural) is the accepted alternate content key
            array('title' => 'Three', 'slug' => 'three', 'contents' => '<p>Three</p>'),
        );
        $this->createSite('My Site', new stdClass(), null, $build);
        $manifest = $this->manifestJson();
        $this->assertCount(3, $manifest->items);
        $this->assertSame('one', $manifest->items[0]->slug);
        $this->assertSame('One', $manifest->items[0]->title);
        $this->assertSame('two', $manifest->items[1]->slug);
        $this->assertSame('three', $manifest->items[2]->slug);
        // orders default to the schema index
        $this->assertSame(0, $manifest->items[0]->order);
        $this->assertSame(1, $manifest->items[1]->order);
        $this->assertSame(2, $manifest->items[2]->order);
        // no default welcome page is added for explicit builds
        $slugs = array_map(function ($i) { return $i->slug; }, $manifest->items);
        $this->assertNotContains('welcome', $slugs);
        // html-template pages: metadata->files is assigned AFTER the item's own
        // manifest->save(), so it only reaches disk when a LATER page re-saves
        // the manifest. With no inline images the value is always empty, meaning
        // every stored item has the key either absent (last page) or [] (earlier
        // pages captured by subsequent saves) — never a non-empty list.
        foreach ($manifest->items as $item) {
            $this->assertTrue(
                !isset($item->metadata->files) || $item->metadata->files === array(),
                'metadata->files must be empty or absent when no inline images were materialized'
            );
        }
        // lifecycle: one updateAlternateFormats per addPage (3x null), one
        // 'llms' from the full managed-file rebuild, one final null from newSite
        $this->assertSame(array(null, null, null, 'llms', null), $this->site->updateAlternateFormatsCalls);
    }

    public function testNewSiteBlogBuildCreatesThreeArticles(): void
    {
        $build = new stdClass();
        $build->structure = 'blog';
        $build->type = 'default';
        $this->createSite('My Site', new stdClass(), null, $build);
        $manifest = $this->manifestJson();
        $this->assertCount(3, $manifest->items);
        $this->assertSame('Article 1', $manifest->items[0]->title);
        $this->assertSame('article-1', $manifest->items[0]->slug);
        $this->assertSame('Article 2', $manifest->items[1]->title);
        $this->assertSame('article-2', $manifest->items[1]->slug);
        $this->assertSame('Meet the author', $manifest->items[2]->title);
        $this->assertSame('meet-the-author', $manifest->items[2]->slug);
    }

    public function testNewSiteWebsiteBuildCreatesSingleHomePage(): void
    {
        $build = new stdClass();
        $build->structure = 'website';
        $build->type = 'default';
        $this->createSite('My Site', new stdClass(), null, $build);
        $manifest = $this->manifestJson();
        $this->assertCount(1, $manifest->items);
        $this->assertSame('Home', $manifest->items[0]->title);
        $this->assertSame('home', $manifest->items[0]->slug);
    }

    public function testNewSiteCourseBuildSixWeekAddsWelcomeAndSixLessons(): void
    {
        $build = new stdClass();
        $build->structure = 'course';
        $build->type = '6w';
        $this->createSite('My Site', new stdClass(), null, $build);
        $manifest = $this->manifestJson();
        $this->assertCount(7, $manifest->items);
        // the course welcome uses the raw (uncleaned) site name
        $this->assertSame('Welcome to My Site', $manifest->items[0]->title);
        $this->assertSame('welcome', $manifest->items[0]->slug);
        for ($i = 1; $i <= 6; $i++) {
            $this->assertSame('Lesson ' . $i, $manifest->items[$i]->title);
            $this->assertSame('lesson-' . $i, $manifest->items[$i]->slug);
        }
    }

    // ---- git lifecycle ----

    public function testNewSiteCreatesGitRepositoryAndCommitsManagedFiles(): void
    {
        $this->createSite('My Site');
        // Git::create ran a real `git init` in the new site directory
        $this->assertDirectoryExists($this->siteDir() . '/.git');
        // terminal gitCommit call recorded exactly once with the canned message
        $this->assertSame(1, $this->site->gitCommitCallCount);
        $this->assertSame('Managed files updated', $this->site->lastGitCommitMsg);
    }

    public function testNewSiteSetsGitRemoteWhenUrlProvided(): void
    {
        $gitDetails = new stdClass();
        $gitDetails->user = 'btopro';
        $gitDetails->url = 'https://github.com/btopro/my-site';
        $this->createSite('My Site', $gitDetails);
        $remotes = trim((string) shell_exec('git -C ' . escapeshellarg($this->siteDir()) . ' remote'));
        $this->assertSame('origin', $remotes);
        $remoteUrl = trim((string) shell_exec('git -C ' . escapeshellarg($this->siteDir()) . ' remote get-url origin'));
        $this->assertSame('https://github.com/btopro/my-site', $remoteUrl);
    }
}

/**
 * HAXCMSSite test subclass: keeps newSite/addPage/rebuildManagedFiles and
 * everything else REAL, stubbing only the terminal collaborators
 * gitCommit/updateAlternateFormats with call recorders.
 */
class HAXCMSNewSiteTestSite extends HAXCMSSite
{
    public $gitCommitCallCount = 0;
    public $lastGitCommitMsg = null;
    public $updateAlternateFormatsCalls = array();

    public function gitCommit($msg = 'Committed changes')
    {
        $this->gitCommitCallCount++;
        $this->lastGitCommitMsg = $msg;
        return true;
    }

    public function updateAlternateFormats($format = null)
    {
        $this->updateAlternateFormatsCalls[] = $format;
        return null;
    }
}

/**
 * HAXCMS collaborator mock providing every method newSite / addPage /
 * rebuildManagedFiles / getSiteMetadata read off $GLOBALS['HAXCMS'].
 */
class HAXCMSNewSiteTestHaxcms extends OperationsTestHaxcms
{
    public $cdn = './';
    public $developerMode = false;
    private $cacheData = array();

    public function getURI()
    {
        return null;
    }

    public function buildShellModulepreloadPaths($site, $base = './', $themePath = '', $cap = 12)
    {
        return array();
    }

    public function getContentTagPath($site, $base, $tag)
    {
        return false;
    }

    public function dispatchEvent($event, &$value = null)
    {
        return $value;
    }

    public function generateMachineName($name)
    {
        $name = str_replace(chr(0), '', $name);
        $name = urldecode($name);
        $name = preg_replace('/\.{2,}/', '', $name);
        $name = preg_replace('/[\\\\\/]/', '', $name);
        $name = preg_replace('/[^a-zA-Z0-9_-]+/', '-', $name);
        $name = preg_replace('/[-_]{2,}/', '-', $name);
        $name = trim($name, '-_');
        $name = strtolower($name);
        if (empty($name)) {
            $name = 'default';
        }
        return $name;
    }

    public function &staticCache($name, $default_value = null, $reset = false)
    {
        if (!array_key_exists($name, $this->cacheData)) {
            $this->cacheData[$name] = $default_value;
        }
        return $this->cacheData[$name];
    }

    public function getDomain()
    {
        return null;
    }
}
