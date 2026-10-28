<?php
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/OperationsTestHaxcms.php';
require_once __DIR__ . '/OperationsSaveOutlineBatchingTest.php';

/**
 * saveOutline batching on a large, messy outline (haxtheweb/issues#3114).
 * Mirrors haxcms-nodejs test/unit/save-outline-large-parity.test.cjs.
 *
 * OperationsSaveOutlineBatchingTest proves the batched save matches the
 * one-save-per-item flow for a small edit. Outline designer saves are often
 * far messier, and pages under different parents share orders: those ties
 * are broken by the order the manifest items are in when site.json is
 * finally sorted, so anything that rearranges the items during the save
 * changes the order site.json (and every list built from it) ends up in.
 * These tests replay one big edit both ways and require the persisted
 * site.json to be identical.
 *
 * The edit: deep nesting, pages moved between parents, renames, deleted
 * parents whose children are kept (orphan rescue), new pages including new
 * pages under new pages, duplicates of existing pages, new contents, and
 * optionally per-page pathauto overrides with custom slugs.
 */
class OperationsSaveOutlineLargeParityTest extends TestCase
{
    private $ops;
    private $savedHaxcms;
    private $savedServerSoftware;
    private $tmpRoot;
    private $fixtureDirName;
    private $siteName = 'my-site';

    protected function setUp(): void
    {
        if (isset($GLOBALS['HAXCMS'])) {
            $this->savedHaxcms = $GLOBALS['HAXCMS'];
        }
        if (isset($_SERVER['SERVER_SOFTWARE'])) {
            $this->savedServerSoftware = $_SERVER['SERVER_SOFTWARE'];
        }
        $_SERVER['SERVER_SOFTWARE'] = 'phpunit-test';
        $boilerplateDir = HAXCMS_ROOT . '/system/boilerplate/page/default';
        if (!is_dir($boilerplateDir)) {
            mkdir($boilerplateDir, 0777, true);
        }
        if (!file_exists($boilerplateDir . '/index.html')) {
            file_put_contents($boilerplateDir . '/index.html', '<p>Default page content</p>');
        }
        $this->fixtureDirName = 'fixtures_outline_large_' . uniqid();
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

    private function copyDir(string $from, string $to): void
    {
        @mkdir($to, 0777, true);
        foreach (scandir($from) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if (is_dir($from . '/' . $entry)) {
                $this->copyDir($from . '/' . $entry, $to . '/' . $entry);
            } else {
                copy($from . '/' . $entry, $to . '/' . $entry);
            }
        }
    }

    /** small deterministic generator so the outline is the same on every run */
    private static function prng(int $seed): callable
    {
        $state = $seed;
        return function () use (&$state) {
            $state = ($state * 1103515245 + 12345) & 0x7fffffff;
            return $state / 0x7fffffff;
        };
    }

    /** $count pages on disk, nested up to five levels deep */
    private function buildSite(int $count): void
    {
        $this->rrmdir($this->tmpRoot);
        $siteDir = $this->tmpRoot . '/' . $this->siteName;
        $random = self::prng(7);
        $items = array();
        for ($i = 0; $i < $count; $i++) {
            $parent = null;
            $indent = 0;
            if ($i > 0 && $random() < 0.6) {
                $candidate = $items[(int) floor($random() * count($items))];
                if ($candidate->indent < 4) {
                    $parent = $candidate->id;
                    $indent = $candidate->indent + 1;
                }
            }
            $siblings = 0;
            foreach ($items as $existing) {
                if ($existing->parent === $parent) {
                    $siblings++;
                }
            }
            $id = 'page-' . $i;
            mkdir($siteDir . '/pages/' . $id, 0777, true);
            file_put_contents($siteDir . '/pages/' . $id . '/index.html', '<p>Page ' . $i . ' body</p>');
            $items[] = (object) array(
                'id' => $id,
                'indent' => $indent,
                'location' => 'pages/' . $id . '/index.html',
                'slug' => 'page-' . $i,
                'order' => $siblings,
                'parent' => $parent,
                'title' => 'Page ' . $i,
                'description' => '',
                'metadata' => (object) array('created' => 1000000, 'updated' => 1000000),
            );
        }
        file_put_contents($siteDir . '/site.json', json_encode((object) array(
            'id' => 'site-uuid',
            'title' => 'Test Site',
            'author' => '',
            'description' => '',
            'license' => 'by-sa',
            'metadata' => (object) array(
                'site' => (object) array(
                    'name' => $this->siteName,
                    'settings' => (object) array('pathauto' => false),
                ),
                'platform' => (object) array('features' => (object) array()),
            ),
            'items' => $items,
        ), JSON_PRETTY_PRINT));
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
        $this->ops = new Operations();
        return $site;
    }

    /** the request the outline designer would send for a big, messy edit */
    private function messyRequest(array $manifestItems, int $seed, bool $slugOverrides): array
    {
        $random = self::prng($seed);
        $items = array();
        foreach ($manifestItems as $m) {
            $items[] = array('id' => $m->id, 'title' => $m->title, 'parent' => $m->parent,
                'indent' => (int) $m->indent, 'order' => (int) $m->order, 'metadata' => array());
        }
        $index = array();
        foreach ($items as $k => $item) {
            $index[$item['id']] = $k;
        }
        $isDescendant = function ($ancestor, $id) use (&$items, &$index) {
            $current = isset($index[$id]) ? $items[$index[$id]] : null;
            while ($current && $current['parent']) {
                if ($current['parent'] === $ancestor) {
                    return true;
                }
                $current = isset($index[$current['parent']]) ? $items[$index[$current['parent']]] : null;
            }
            return false;
        };
        foreach ($items as $k => $item) {
            if ($random() < 0.3) {
                $items[$k]['title'] .= ' renamed';
            }
        }
        foreach ($items as $k => $item) {
            if ($random() < 0.2) {
                $target = $items[(int) floor($random() * count($items))];
                if ($target['id'] !== $item['id'] && !$isDescendant($item['id'], $target['id'])) {
                    $items[$k]['parent'] = $random() < 0.3 ? null : $target['id'];
                }
            }
        }
        // some leaves and some parents (their children stay: orphan rescue)
        foreach ($items as $k => $item) {
            if ($random() < 0.1) {
                $items[$k]['delete'] = true;
            }
        }
        $added = array();
        for ($k = 0; $k < 10; $k++) {
            $parent = null;
            if ($k % 3 === 2 && count($added)) {
                $parent = $added[count($added) - 1]['id'];
            } elseif ($random() < 0.5) {
                $parent = $items[(int) floor($random() * count($items))]['id'];
            }
            $added[] = array('id' => 'client-new-' . $k, 'title' => 'New ' . $k, 'parent' => $parent,
                'indent' => 0, 'order' => 0, 'metadata' => array(),
                'contents' => ($k % 2) ? '<p>new ' . $k . '</p>' : '', 'new' => true);
        }
        for ($k = 0; $k < 4; $k++) {
            $source = $items[(int) floor($random() * count($items))];
            $added[] = array('id' => 'client-dup-' . $k, 'title' => 'Dup ' . $k, 'parent' => $source['parent'],
                'indent' => 0, 'order' => 0, 'metadata' => array(), 'duplicate' => $source['id'], 'new' => true);
        }
        foreach ($items as $k => $item) {
            if ($random() < 0.1) {
                $items[$k]['contents'] = '<h2>' . $item['title'] . '</h2><p>edited</p>';
            }
        }
        $all = array_merge($items, $added);
        $allIndex = array();
        foreach ($all as $k => $item) {
            $allIndex[$item['id']] = $k;
        }
        // indents follow parents; siblings get unique, shuffled orders (pages
        // under different parents still share orders, which is the point)
        foreach ($all as $k => $item) {
            $depth = 0;
            $current = $item;
            while ($current['parent'] && isset($allIndex[$current['parent']])) {
                $depth++;
                $current = $all[$allIndex[$current['parent']]];
            }
            $all[$k]['indent'] = $depth;
        }
        $groups = array();
        foreach ($all as $k => $item) {
            $groups[$item['parent'] === null ? 'ROOT' : $item['parent']][] = $k;
        }
        foreach ($groups as $group) {
            usort($group, function () use ($random) {
                return $random() < 0.5 ? -1 : 1;
            });
            foreach ($group as $position => $k) {
                $all[$k]['order'] = $position;
            }
        }
        // sent in tree order, the way the designer sends it
        $sent = array();
        $walk = function ($parent) use (&$walk, &$sent, $all) {
            $kids = array();
            foreach ($all as $item) {
                if ($item['parent'] === $parent) {
                    $kids[] = $item;
                }
            }
            usort($kids, function ($a, $b) {
                return $a['order'] <=> $b['order'];
            });
            foreach ($kids as $item) {
                $sent[] = $item;
                $walk($item['id']);
            }
        };
        $walk(null);
        if ($slugOverrides) {
            foreach ($sent as $k => $item) {
                if ($k % 4 === 0) {
                    $sent[$k]['metadata']['overridePathauto'] = true;
                    $sent[$k]['slug'] = 'custom-' . $k;
                }
            }
        }
        return $sent;
    }

    private function runOutline(array $items): array
    {
        $this->ops->params = array('site_token' => 'good', 'site' => array('name' => $this->siteName));
        $this->ops->rawParams = array('items' => $items);
        return $this->ops->saveOutline();
    }

    /**
     * site.json with wall-clock fields blanked and every page id replaced by
     * its title, so new pages (fresh server ids on each replay) compare equal
     */
    private function normalizedSiteJson(): string
    {
        $raw = file_get_contents($this->tmpRoot . '/' . $this->siteName . '/site.json');
        // as arrays: array_walk_recursive does not descend into objects
        $decoded = json_decode($raw, true);
        $replacements = array();
        foreach ($decoded['items'] as $item) {
            $replacements[$item['id']] = 'ID{' . $item['title'] . '}';
        }
        uksort($replacements, function ($a, $b) {
            return strlen($b) - strlen($a);
        });
        array_walk_recursive($decoded, function (&$v, $k) {
            if ($k === 'updated' || $k === 'created') {
                $v = 0;
            }
        });
        return strtr(json_encode($decoded, JSON_PRETTY_PRINT), $replacements);
    }

    private function assertSameBothWays(int $seed, bool $slugOverrides): void
    {
        $this->buildSite(60);
        $siteDir = $this->tmpRoot . '/' . $this->siteName;
        $before = $this->tmpRoot . '/snapshot';
        $this->copyDir($siteDir, $before);
        $site = $this->loadSite(false);
        $items = $this->messyRequest(array_values($site->manifest->items), $seed, $slugOverrides);
        $hasDelete = false;
        $hasDuplicate = false;
        foreach ($items as $item) {
            $hasDelete = $hasDelete || !empty($item['delete']);
            $hasDuplicate = $hasDuplicate || isset($item['duplicate']);
        }
        $this->assertTrue($hasDelete && $hasDuplicate, 'the edit really is messy');

        $batchedResult = $this->runOutline($items);
        $this->assertArrayNotHasKey('__failed', $batchedResult);
        $batched = $this->normalizedSiteJson();

        $this->rrmdir($siteDir);
        $this->copyDir($before, $siteDir);
        $this->loadSite(true);
        $legacyResult = $this->runOutline($items);
        $this->assertArrayNotHasKey('__failed', $legacyResult);

        $this->assertSame($this->normalizedSiteJson(), $batched, 'persisted site.json is identical');
    }

    public function testLargeMessyOutlineMatchesOneSavePerItemFlow(): void
    {
        $this->assertSameBothWays(11, false);
    }

    public function testLargeMessyOutlineMatchesItWithADifferentEdit(): void
    {
        $this->assertSameBothWays(99, false);
    }

    public function testLargeMessyOutlineWithPathautoOverridesMatchesIt(): void
    {
        $this->assertSameBothWays(23, true);
    }
}
