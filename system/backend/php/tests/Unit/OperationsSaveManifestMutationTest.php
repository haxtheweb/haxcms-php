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
            // is_link BEFORE is_dir: is_dir() follows symlinks, so checking it
            // first would recurse INTO the symlink target and delete real repo
            // dirs (e.g. <repoRoot>/build via a newSite ../../build symlink).
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
            'metadata' => (object)array(
                'site' => (object)array('name' => $this->siteName),
                // PHP 8.3 refuses to auto-vivify through an UNDEFINED
                // metadata->theme ("Attempt to assign property on null"), so
                // the legacy branch ships an empty theme object for
                // saveManifest to create variables/regions on.
                'theme' => (object)array(),
            ),
            // one valid item so the homePageId validation can pass
            'items' => array(
                (object)array(
                    'id' => 'item-uuid-1',
                    'indent' => 0,
                    'location' => 'pages/item-uuid-1/index.html',
                    'slug' => 'item-uuid-1',
                    'order' => 0,
                    'parent' => null,
                    'title' => 'Home Page',
                    'description' => '',
                    'metadata' => (object)array('created' => 1000000, 'updated' => 1000000),
                ),
            ),
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
    public function testSaveManifestLegacyFormPayloadAppliesFullManifestAndPersists(): void
    {
        $this->haxcms->validRequestToken = true;
        // Legacy form payload: haxcms_form_id + haxcms_form_token are present
        // so isScopedDetailsManifestPayload() returns false even though details
        // fields like manifest-title are included (the scoped detector refuses
        // anything carrying form tokens). validateRequestToken is the mock
        // (always true), so the NON-scoped else-branch runs and applies the
        // full legacy manifest schema.
        $this->ops->params = array(
            'site_token' => 'good',
            'site' => array('name' => $this->siteName),
            'haxcms_form_id' => 'site-manifest',
            'haxcms_form_token' => 'form-token',
            'manifest' => array(
                'site' => array(
                    'manifest-title' => 'Legacy Form Title',
                    'manifest-description' => 'Legacy description',
                    'manifest-metadata-site-domain' => 'https://new.example.com',
                    'manifest-metadata-site-logo' => 'files/logo.png',
                    'manifest-metadata-site-tags' => 'hax,test',
                    'manifest-metadata-site-homePageId' => 'item-uuid-1',
                ),
                // deliberately NO manifest-metadata-theme-element: with an
                // empty themes registry the match loop is skipped entirely
                'theme' => array(
                    'manifest-metadata-theme-variables-cssVariable' => 'grey',
                    'manifest-metadata-theme-variables-icon' => 'icons:save',
                    'manifest-metadata-theme-variables-image' => 'files/banner.jpg',
                    'manifest-metadata-theme-variables-imageAlt' => 'A banner',
                    'manifest-metadata-theme-variables-imageLink' => 'https://example.com',
                    'manifest-metadata-theme-variables-hexCode' => '#aa8833',
                    'manifest-metadata-theme-variables-palette' => 'grey',
                    'manifest-metadata-theme-regions-header' => array('block-1'),
                ),
                'author' => array(
                    'manifest-license' => 'cc-by',
                    'manifest-metadata-author-name' => 'Test Author',
                    'manifest-metadata-author-email' => 'author@example.com',
                    'manifest-metadata-author-image' => 'https://example.com/a.png',
                    'manifest-metadata-author-socialLink' => 'https://example.com/',
                ),
                'seo' => array(
                    'manifest-metadata-site-settings-private' => '1',
                    'manifest-metadata-site-settings-canonical' => '1',
                    'manifest-metadata-site-settings-lang' => 'fr',
                    'manifest-metadata-site-settings-pathauto' => 'false',
                    'manifest-metadata-site-settings-publishPagesOn' => '1',
                    'manifest-metadata-site-settings-sw' => '1',
                    'manifest-metadata-site-settings-forceUpgrade' => '0',
                    'manifest-metadata-site-settings-gaID' => 'UA-XYZ',
                ),
            ),
        );
        $result = $this->ops->saveManifest();

        // success returns the mutated manifest object
        $this->assertSame('Legacy Form Title', $result->title);
        $this->assertSame('Legacy description', $result->description);
        $this->assertSame('cc-by', $result->license);
        $this->assertSame('0.0.0-test', $result->metadata->site->version);
        $this->assertSame('https://new.example.com', $result->metadata->site->domain);
        // scheme-less values pass through sanitizeURLValue unchanged
        $this->assertSame('files/logo.png', $result->metadata->site->logo);
        // comma-separated input is canonicalized to a list per
        // haxtheweb/json-outline-schema
        $this->assertSame(array('hax', 'test'), $result->metadata->site->tags);
        // homePageId validated against the fixture item before being set
        $this->assertSame('item-uuid-1', $result->metadata->site->homePageId);

        // theme block: variables/regions auto-created on the empty theme object
        $this->assertSame('--simple-colors-default-theme-grey-7', $result->metadata->theme->variables->cssVariable);
        $this->assertSame('icons:save', $result->metadata->theme->variables->icon);
        $this->assertSame('files/banner.jpg', $result->metadata->theme->variables->image);
        $this->assertSame('A banner', $result->metadata->theme->variables->imageAlt);
        $this->assertSame('#aa8833', $result->metadata->theme->variables->hexCode);
        $this->assertSame('grey', $result->metadata->theme->variables->palette);
        $this->assertSame(array('block-1'), $result->metadata->theme->regions->header);

        // seo settings parsed through their filters
        $this->assertTrue($result->metadata->site->settings->private);
        $this->assertTrue($result->metadata->site->settings->canonical);
        $this->assertSame('fr', $result->metadata->site->settings->lang);
        $this->assertFalse($result->metadata->site->settings->pathauto);
        $this->assertTrue($result->metadata->site->settings->publishPagesOn);
        $this->assertTrue($result->metadata->site->settings->sw);
        $this->assertFalse($result->metadata->site->settings->forceUpgrade);
        $this->assertSame('UA-XYZ', $result->metadata->site->settings->gaID);

        // author block + license
        $this->assertSame('Test Author', $result->metadata->author->name);
        $this->assertSame('author@example.com', $result->metadata->author->email);
        $this->assertSame('https://example.com/a.png', $result->metadata->author->image);

        // platform defaults created because the fixture had none
        $this->assertSame('expert', $result->metadata->platform->audience);
        $this->assertInstanceOf(stdClass::class, $result->metadata->platform->features);
        $this->assertSame(array(), $result->metadata->platform->allowedBlocks);

        // collaborator recording: two commits, both rebuild hooks fired
        $site = $this->haxcms->loadedSite;
        $this->assertSame(array('Manifest updated', 'Managed files updated'), $site->gitCommits);
        $this->assertTrue($site->rebuiltManagedFiles);
        $this->assertTrue($site->updatedAlternateFormats);

        // independent source of truth: the persisted site.json
        $persisted = json_decode(file_get_contents($this->siteJsonPath()));
        $this->assertSame('Legacy Form Title', $persisted->title);
        $this->assertSame('Legacy description', $persisted->description);
        $this->assertSame('cc-by', $persisted->license);
        $this->assertSame('0.0.0-test', $persisted->metadata->site->version);
        $this->assertSame('https://new.example.com', $persisted->metadata->site->domain);
        $this->assertSame(array('hax', 'test'), $persisted->metadata->site->tags);
        $this->assertSame('item-uuid-1', $persisted->metadata->site->homePageId);
        $this->assertSame('--simple-colors-default-theme-grey-7', $persisted->metadata->theme->variables->cssVariable);
        $this->assertSame('expert', $persisted->metadata->platform->audience);
        $this->assertSame('Test Author', $persisted->metadata->author->name);
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
