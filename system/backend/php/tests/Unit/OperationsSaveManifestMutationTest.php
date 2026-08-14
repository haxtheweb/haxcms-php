<?php
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/OperationsTestHaxcms.php';

/**
 * Happy-path characterization test for Operations::saveManifest.
 *
 * Slice: a scoped Details payload (title present, no haxcms_form_id/token)
 * bypasses the form-token check, so with a valid site_token + platform
 * allowed the method mutates the manifest and persists it. The git/twig
 * collaborators (gitCommit, rebuildManagedFiles, updateAlternateFormats) are
 * stubbed via a HAXCMSSite test subclass so the REAL mutation logic in
 * Operations::saveManifest + the REAL JSONOutlineSchema::save run against a
 * temp site.json fixture. This pins the actual persistence behavior, not a
 * simulation.
 *
 * Expected: the returned manifest carries the new title AND the on-disk
 * site.json reflects the new title (independent source of truth: read the
 * file back and decode it).
 */
class OperationsSaveManifestMutationTest extends TestCase
{
    private $haxcms;
    private $ops;
    private $savedHaxcms;
    private $tmpRoot;
    private $siteName = 'my-site';

    protected function setUp(): void
    {
        if (isset($GLOBALS['HAXCMS'])) {
            $this->savedHaxcms = $GLOBALS['HAXCMS'];
        }
        $this->tmpRoot = sys_get_temp_dir() . '/haxcms_ops_manifest_' . uniqid();
        $this->buildMinimalSiteFixture();
        $this->haxcms = new OperationsTestHaxcms();
        $this->haxcms->configDirectory = $this->tmpRoot . '/_config';
        mkdir($this->haxcms->configDirectory, 0777, true);
        // install the mock as $GLOBALS['HAXCMS'] BEFORE loading the site,
        // because HAXCMSSite::load() calls $GLOBALS['HAXCMS']->cleanTitle().
        $GLOBALS['HAXCMS'] = $this->haxcms;
        $site = new OperationsTestSite();
        $site->load($this->tmpRoot, '/', $this->siteName);
        $this->haxcms->loadedSite = $site;
        $this->ops = new Operations();
        $this->ops->params = array();
        $this->ops->rawParams = array();
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

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                $this->rrmdir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    private function buildMinimalSiteFixture(): void
    {
        $siteDir = $this->tmpRoot . '/' . $this->siteName;
        mkdir($siteDir . '/pages', 0777, true);
        $manifest = (object)array(
            'id' => 'site-uuid',
            'title' => 'Old Title',
            'author' => '',
            'description' => '',
            'license' => 'by-sa',
            'metadata' => (object)array('site' => (object)array('name' => $this->siteName)),
            'items' => array(),
        );
        file_put_contents($siteDir . '/site.json', json_encode($manifest, JSON_PRETTY_PRINT));
    }

    private function siteJsonPath(): string
    {
        return $this->tmpRoot . '/' . $this->siteName . '/site.json';
    }

    public function testSaveManifestScopedPayloadUpdatesTitleAndPersists(): void
    {
        $this->haxcms->validRequestToken = true;
        // scoped Details payload: manifest array present + title (details
        // field) + no form tokens -> bypasses the form-token check.
        $this->ops->params = array(
            'site_token' => 'good',
            'site' => array('name' => $this->siteName),
            'manifest' => array('site' => array()),
            'title' => 'New Title',
        );
        $result = $this->ops->saveManifest();
        // success returns the manifest object
        $this->assertTrue(property_exists($result, 'title'));
        $this->assertSame('New Title', $result->title);
        // independent source of truth: read the persisted file back
        $persisted = json_decode(file_get_contents($this->siteJsonPath()));
        $this->assertSame('New Title', $persisted->title);
        // updated timestamp was set
        $this->assertTrue(isset($persisted->metadata->site->updated));
        $this->assertIsInt($persisted->metadata->site->updated);
    }

    public function testSaveManifestScopedPayloadStripsTagsFromTitle(): void
    {
        // strip_tags removes the <script> tags but keeps their text content
        // ("alert(1)") — that is correct PHP strip_tags behavior, not a bug:
        // the title is a plain string escaped on render, so retained text is
        // not an XSS vector. Characterized here as the actual behavior.
        $this->haxcms->validRequestToken = true;
        $this->ops->params = array(
            'site_token' => 'good',
            'site' => array('name' => $this->siteName),
            'manifest' => array('site' => array()),
            'title' => '<script>alert(1)</script>Safe',
        );
        $result = $this->ops->saveManifest();
        $this->assertSame('alert(1)Safe', $result->title);
        $persisted = json_decode(file_get_contents($this->siteJsonPath()));
        $this->assertSame('alert(1)Safe', $persisted->title);
        // the script TAG never reaches the persisted file (only its text)
        $this->assertStringNotContainsString('<script', file_get_contents($this->siteJsonPath()));
    }
}

/**
 * HAXCMSSite test subclass that no-ops the git/twig collaborators while
 * keeping the real manifest load + JSONOutlineSchema::save. This lets
 * Operations::saveManifest's mutation+persistence logic run for real against
 * a temp fixture without a git binary or twig template tree.
 */
class OperationsTestSite extends HAXCMSSite
{
    public $gitCommits = array();
    public $rebuiltManagedFiles = false;
    public $updatedAlternateFormats = false;

    public function gitCommit($msg = 'Committed changes')
    {
        $this->gitCommits[] = $msg;
        return true;
    }

    public function rebuildManagedFiles($templates = array())
    {
        $this->rebuiltManagedFiles = true;
        return null;
    }

    public function updateAlternateFormats($format = null)
    {
        $this->updatedAlternateFormats = true;
        return null;
    }
}
