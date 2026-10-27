<?php
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/OperationsTestHaxcms.php';

/**
 * saveOutline batching (haxtheweb/issues#3114). saveOutline used to call
 * HAXCMSSite::updateNode() for every existing item, and each call rewrote
 * site.json and rebuilt RSS / Atom / sitemap / llms.txt / the search index,
 * so a request cost O(items^2). The manifest is now updated in memory and
 * saved + rebuilt once per request. Mirrors haxcms-nodejs
 * test/unit/save-outline-batching.test.cjs.
 *
 *   1. correctness: the persisted site.json and the response are identical
 *      to what the legacy one-save-per-item flow produced (replayed by a
 *      site double whose updateNode ignores the new $persist flag, which is
 *      exactly what it did before)
 *   2. cost: the number of site.json saves and alternate-format rebuilds per
 *      request does not grow with the number of items
 *   3. an early validation error still flushes the pending save + rebuild
 */
class OperationsSaveOutlineBatchingTest extends TestCase
{
    private $ops;
    private $savedHaxcms;
    private $savedServerSoftware;
    private $tmpRoot;
    private $fixtureDirName;
    private $siteName = 'my-site';
    private $site;

    protected function setUp(): void
    {
        if (isset($GLOBALS['HAXCMS'])) {
            $this->savedHaxcms = $GLOBALS['HAXCMS'];
        }
        if (isset($_SERVER['SERVER_SOFTWARE'])) {
            $this->savedServerSoftware = $_SERVER['SERVER_SOFTWARE'];
        }
        $_SERVER['SERVER_SOFTWARE'] = 'phpunit-test';
        if (!defined('HAXCMS_ROOT')) {
            define('HAXCMS_ROOT', sys_get_temp_dir() . '/haxcms_ops_outline_root');
        }
        $boilerplateDir = HAXCMS_ROOT . '/system/boilerplate/page/default';
        if (!is_dir($boilerplateDir)) {
            mkdir($boilerplateDir, 0777, true);
        }
        if (!file_exists($boilerplateDir . '/index.html')) {
            file_put_contents($boilerplateDir . '/index.html', '<p>Default page content</p>');
        }
        $this->fixtureDirName = 'fixtures_outline_batch_' . uniqid();
        $this->tmpRoot = HAXCMS_ROOT . '/' . $this->fixtureDirName;
    }

    protected function tearDown(): void
    {
        if (isset($this->savedHaxcms)) {
            $GLOBALS['HAXCMS'] = $this->savedHaxcms;
            $this->savedHaxcms = null;
        } else {
            unset($GLOBALS['HAXCMS']);
        }
        if (isset($this->savedServerSoftware)) {
            $_SERVER['SERVER_SOFTWARE'] = $this->savedServerSoftware;
            $this->savedServerSoftware = null;
        } else {
            unset($_SERVER['SERVER_SOFTWARE']);
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

    /** Site with $count root pages (page-0 .. page-N) on disk, pathauto on. */
    private function buildSite(int $count): void
    {
        $this->rrmdir($this->tmpRoot);
        $siteDir = $this->tmpRoot . '/' . $this->siteName;
        $items = array();
        for ($i = 0; $i < $count; $i++) {
            mkdir($siteDir . '/pages/page-' . $i, 0777, true);
            file_put_contents($siteDir . '/pages/page-' . $i . '/index.html', '<p>Content ' . $i . '</p>');
            $items[] = (object)array(
                'id' => 'page-' . $i,
                'indent' => ($i === 1) ? 1 : 0,
                'location' => 'pages/page-' . $i . '/index.html',
                'slug' => 'page-' . $i,
                'order' => $i,
                'parent' => ($i === 1) ? 'page-0' : null,
                'title' => 'Page ' . $i,
                'description' => '',
                'metadata' => (object)array('created' => 1000000, 'updated' => 1000000),
            );
        }
        $manifest = (object)array(
            'id' => 'site-uuid',
            'title' => 'Test Site',
            'author' => '',
            'description' => '',
            'license' => 'by-sa',
            'metadata' => (object)array(
                'site' => (object)array(
                    'name' => $this->siteName,
                    'settings' => (object)array('pathauto' => true),
                ),
                'platform' => (object)array('features' => (object)array()),
            ),
            'items' => $items,
        );
        file_put_contents($siteDir . '/site.json', json_encode($manifest, JSON_PRETTY_PRINT));
    }

    private function loadSite(bool $legacy): BatchingTestSite
    {
        $haxcms = new BatchingTestHaxcms();
        $haxcms->configDirectory = $this->tmpRoot . '/_config';
        @mkdir($haxcms->configDirectory, 0777, true);
        $haxcms->validRequestToken = true;
        $haxcms->sitesDirectory = $this->fixtureDirName;
        $GLOBALS['HAXCMS'] = $haxcms;
        $site = new BatchingTestSite();
        $site->legacy = $legacy;
        $site->load($this->tmpRoot, '/', $this->siteName);
        $haxcms->loadedSite = $site;
        $this->site = $site;
        $this->ops = new Operations();
        return $site;
    }

    /** renamed + reversed payload; optionally flags the root page 0 for delete */
    private function payload(int $count, bool $withDelete): array
    {
        $items = array();
        for ($i = 0; $i < $count; $i++) {
            $items[] = array(
                'id' => 'page-' . $i,
                'title' => 'Page ' . $i . ' edited',
                'slug' => 'page-' . $i,
                'order' => $count - $i,
                'parent' => ($i === 1) ? 'page-0' : null,
                'indent' => ($i === 1) ? 1 : 0,
                'metadata' => array(),
            );
        }
        if ($withDelete) {
            $items[0]['delete'] = true;
        }
        return $items;
    }

    private function runOutline(array $items): array
    {
        $this->ops->params = array(
            'site_token' => 'good',
            'site' => array('name' => $this->siteName),
        );
        $this->ops->rawParams = array('items' => $items);
        return $this->ops->saveOutline();
    }

    /** site.json with the wall-clock fields blanked so runs compare equal */
    private function normalizedSiteJson(): string
    {
        $decoded = json_decode(file_get_contents($this->tmpRoot . '/' . $this->siteName . '/site.json'));
        array_walk_recursive($decoded, function (&$v, $k) {
            if ($k === 'updated' || $k === 'created') {
                $v = 0;
            }
        });
        return json_encode($decoded, JSON_PRETTY_PRINT);
    }

    public function testOutputMatchesOneSavePerItemFlow(): void
    {
        $this->buildSite(6);
        $siteDir = $this->tmpRoot . '/' . $this->siteName;
        $snapshot = file_get_contents($siteDir . '/site.json');

        $batched = $this->loadSite(false);
        $batchedResult = $this->runOutline($this->payload(6, true));
        $batchedJson = $this->normalizedSiteJson();
        $batchedCommits = $batched->gitCommits;

        // restore the original site.json + deleted page dirs, replay legacy
        file_put_contents($siteDir . '/site.json', $snapshot);
        for ($i = 0; $i < 6; $i++) {
            @mkdir($siteDir . '/pages/page-' . $i, 0777, true);
            file_put_contents($siteDir . '/pages/page-' . $i . '/index.html', '<p>Content ' . $i . '</p>');
        }
        $legacy = $this->loadSite(true);
        $legacyResult = $this->runOutline($this->payload(6, true));

        $this->assertArrayNotHasKey('__failed', $batchedResult);
        $this->assertStringContainsString('edited', $batchedJson);
        $this->assertSame($this->normalizedSiteJson(), $batchedJson, 'persisted site.json is identical');
        $this->assertSame(
            array_map(function ($i) { return array($i->id, $i->title, $i->slug, $i->parent, $i->order); }, $legacyResult['items']),
            array_map(function ($i) { return array($i->id, $i->title, $i->slug, $i->parent, $i->order); }, $batchedResult['items']),
            'same response items'
        );
        $this->assertEquals($legacyResult['idMap'], $batchedResult['idMap']);
        $this->assertSame($legacy->gitCommits, $batchedCommits, 'same git commits');
        $this->assertGreaterThan($batched->alternateFormatRebuilds, $legacy->alternateFormatRebuilds, 'legacy did more work');
    }

    public function testSavesAndRebuildsDoNotGrowWithItemCount(): void
    {
        $counts = array();
        foreach (array(4, 12) as $size) {
            $this->buildSite($size);
            $site = $this->loadSite(false);
            $result = $this->runOutline($this->payload($size, false));
            $this->assertArrayNotHasKey('__failed', $result);
            $counts[$size] = array($site->manifest->saveCount(), $site->alternateFormatRebuilds);
        }
        $this->assertSame($counts[4], $counts[12], 'cost does not grow with item count');
        // one checkpoint after the structure pass (save + rebuild), then the
        // end-of-request saves (post-delete, final) and rebuild
        $this->assertSame(array(3, 2), $counts[12]);
    }

    public function testEarlyErrorStillFlushesPendingSaveAndRebuild(): void
    {
        $this->buildSite(3);
        $site = $this->loadSite(false);
        $items = $this->payload(3, false);
        // existing items update fine, then the content phase rejects this one
        $items[1]['contents'] = 'plain text, not html';
        $result = $this->runOutline($items);
        $this->assertSame(400, $result['__failed']['status']);
        $this->assertSame('invalid page contents', $result['__failed']['message']);
        $this->assertSame(1, $site->alternateFormatRebuilds, 'rebuilt once before returning the error');
        $this->assertSame(1, $site->manifest->saveCount(), 'saved once before returning the error');
        $persisted = file_get_contents($this->tmpRoot . '/' . $this->siteName . '/site.json');
        $this->assertStringContainsString('Page 2 edited', $persisted, 'edits made before the error are persisted');
    }
}

class BatchingTestHaxcms extends OperationsTestHaxcms
{
    public $outlineSchema;

    public function __construct()
    {
        parent::__construct();
        $this->outlineSchema = new JSONOutlineSchema();
    }

    public function generateMachineName($name)
    {
        $name = str_replace(chr(0), '', (string) $name);
        $name = urldecode($name);
        $name = preg_replace('/\.{2,}/', '', $name);
        $name = str_replace(array('\\\\', '/'), '', $name);
        $name = preg_replace('/[^a-zA-Z0-9_-]+/', '-', $name);
        $name = preg_replace('/[-_]{2,}/', '-', $name);
        $name = trim($name, '-_');
        $name = strtolower($name);
        return $name === '' ? 'default' : $name;
    }
}

/** JSONOutlineSchema that counts how often site.json is written. */
class BatchingCountingManifest extends JSONOutlineSchema
{
    // private so the parent's get_object_vars() does not serialize it into site.json
    private $saves = 0;

    public function saveCount()
    {
        return $this->saves;
    }

    public function save($reorder = true)
    {
        $this->saves++;
        return parent::save($reorder);
    }
}

/**
 * HAXCMSSite double: counts site.json saves and alternate-format rebuilds.
 * $legacy replays the pre-#3114 updateNode, which always saved + rebuilt.
 */
class BatchingTestSite extends HAXCMSSite
{
    public $legacy = false;
    public $gitCommits = array();
    public $alternateFormatRebuilds = 0;

    public function load($directory, $siteBasePath, $name)
    {
        $result = parent::load($directory, $siteBasePath, $name);
        $counting = new BatchingCountingManifest();
        foreach (get_object_vars($this->manifest) as $key => $value) {
            $counting->$key = $value;
        }
        $this->manifest = $counting;
        return $result;
    }

    public function updateNode($page, $persist = true)
    {
        return parent::updateNode($page, $this->legacy ? true : $persist);
    }

    public function gitCommit($msg = 'Committed changes')
    {
        $this->gitCommits[] = $msg;
        return true;
    }

    public function rebuildManagedFiles($templates = array())
    {
        return null;
    }

    public function updateAlternateFormats($format = null)
    {
        $this->alternateFormatRebuilds++;
        return null;
    }

    public function writePageAlternateFormats($page, $htmlContent = '')
    {
        return true;
    }
}
