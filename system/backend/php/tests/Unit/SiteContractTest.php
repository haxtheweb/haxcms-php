<?php
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/OperationsTestHaxcms.php';
// HAXCMSNewSiteTestSite / HAXCMSNewSiteTestHaxcms: the real newSite() with
// only gitCommit and updateAlternateFormats recorded instead of run.
require_once __DIR__ . '/HAXCMSSiteNewSiteTest.php';

/**
 * haxtheweb/issues#3351: the site folder contract.
 *
 * system/boilerplate/site-contract.json is this repo's copy of
 * schema/haxcms-site-contract.json from
 * https://github.com/haxtheweb/json-outline-schema (haxcms-nodejs carries the
 * same copy at src/boilerplate/site-contract.json). It lists every path a
 * HAXcms site folder may hold: required, optional or retired, and who owns
 * it. These tests fail when this backend and the contract disagree, so adding
 * or dropping a site file is a deliberate edit to the contract:
 *
 *   - the site boilerplate ships exactly the contract's boilerplate entries
 *   - getManagedTemplateFiles() and the ManagedFiles policies match the
 *     contract's managed entries
 *   - a site this backend just made has every required path, no retired path
 *     and no file the contract does not know
 *   - files/ exists in the boilerplate and in a new site, even when empty
 *   - rebuildManagedFiles() really rewrites every file the contract calls
 *     managed (ghpages.html, index.php and config.php included)
 *
 * Mirrors haxcms-nodejs test/unit/site-contract.test.cjs. The full validator
 * (schema checks on site.json, level reporting) lives in json-outline-schema
 * as `jos-validate --site`; the matcher here is only what these comparisons
 * need.
 */
class SiteContractTest extends TestCase
{
    /**
     * Managed paths written by a rebuild step other than the
     * getManagedTemplateFiles() copy loop. Add to this list only with the
     * code that writes the file.
     */
    const MANAGED_OUTSIDE_TEMPLATE_LIST = array(
        // HAXCMSSite::rebuildAgentSkills() copies the skills and their index
        '.well-known/agent-skills/index.json',
        // HAXCMSSite::rebuildManagedFiles() adds the PHP entry point and its
        // settings to the template list on every full rebuild
        'index.php',
        'config.php',
    );

    private static $contract;
    private static $literals = array();
    private static $patterns = array();

    private $savedHaxcms;
    private $tmpRoot;

    public static function setUpBeforeClass(): void
    {
        $file = self::boilerplateRoot() . '/site-contract.json';
        self::$contract = json_decode(file_get_contents($file), true);
        self::$literals = array();
        self::$patterns = array();
        foreach (self::$contract['entries'] as $entry) {
            // a folder entry is about the folder existing; it never classifies a file
            if (self::isFolderEntry($entry)) {
                continue;
            }
            if (self::hasWildcard($entry['path'])) {
                self::$patterns[] = array('entry' => $entry, 'regex' => self::patternToRegex($entry['path']));
            } elseif (!isset(self::$literals[$entry['path']])) {
                self::$literals[$entry['path']] = $entry;
            }
        }
    }

    protected function setUp(): void
    {
        if (isset($GLOBALS['HAXCMS'])) {
            $this->savedHaxcms = $GLOBALS['HAXCMS'];
        }
        $this->tmpRoot = sys_get_temp_dir() . '/haxcms_site_contract_' . uniqid();
        mkdir($this->tmpRoot);
        $GLOBALS['HAXCMS'] = new HAXCMSNewSiteTestHaxcms();
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

    // ---- contract matching ----
    // `*` stays inside one path segment, `**` crosses them; a path without a
    // wildcard is matched first, then the first matching entry

    private static function boilerplateRoot(): string
    {
        // tests/Unit -> tests -> php -> backend -> system
        return dirname(__DIR__, 4) . '/boilerplate';
    }

    private static function hasWildcard(string $pattern): bool
    {
        return strpos($pattern, '*') !== false;
    }

    private static function patternToRegex(string $pattern): string
    {
        $deep = array();
        foreach (explode('**', $pattern) as $part) {
            $shallow = array();
            foreach (explode('*', $part) as $literal) {
                $shallow[] = preg_quote($literal, '#');
            }
            $deep[] = implode('[^/]*', $shallow);
        }
        return '#^' . implode('.*', $deep) . '$#';
    }

    private static function classify(string $relativePath): ?array
    {
        if (isset(self::$literals[$relativePath])) {
            return self::$literals[$relativePath];
        }
        foreach (self::$patterns as $pattern) {
            if (preg_match($pattern['regex'], $relativePath) === 1) {
                return $pattern['entry'];
            }
        }
        return null;
    }

    private static function isFolderEntry(array $entry): bool
    {
        return isset($entry['kind']) && $entry['kind'] === 'folder';
    }

    /** Required folders (they must exist even when empty) missing under $root. */
    private static function missingFolders(string $root): array
    {
        $missing = array();
        foreach (self::$contract['entries'] as $entry) {
            if (self::isFolderEntry($entry) && $entry['status'] === 'required' && !is_dir($root . '/' . $entry['path'])) {
                $missing[] = $entry['path'];
            }
        }
        return $missing;
    }

    private static function isManaged(array $entry): bool
    {
        return $entry['owner'] === 'managed' || $entry['owner'] === 'partly-managed';
    }

    /** Literal contract paths whose entry passes $filter, sorted. */
    private static function contractPaths(callable $filter): array
    {
        $paths = array();
        foreach (self::$contract['entries'] as $entry) {
            if (!self::hasWildcard($entry['path']) && !self::isFolderEntry($entry) && $filter($entry)) {
                $paths[] = $entry['path'];
            }
        }
        sort($paths);
        return $paths;
    }

    /** Every file and symbolic link under $root (links listed, never followed). */
    private static function listFolder(string $root, string $prefix = ''): array
    {
        $ignore = isset(self::$contract['ignore']) ? self::$contract['ignore'] : array();
        $found = array();
        foreach (scandir($root) as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            if ($prefix === '' && in_array($name, $ignore, true)) {
                continue;
            }
            $path = $root . '/' . $name;
            if (is_dir($path) && !is_link($path)) {
                $found = array_merge($found, self::listFolder($path, $prefix . $name . '/'));
            } else {
                $found[] = $prefix . $name;
            }
        }
        sort($found);
        return $found;
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

    // ---- the contract copy itself ----

    public function testContractLevelsAndEntriesAreWellFormed(): void
    {
        $levels = array_column(self::$contract['levels'], 'id');
        $this->assertSame(array('content', 'runnable', 'managed'), $levels);
        $seen = array();
        foreach (self::$contract['entries'] as $entry) {
            $this->assertArrayNotHasKey($entry['path'], $seen, 'duplicate ' . $entry['path']);
            $seen[$entry['path']] = true;
            $this->assertContains($entry['status'], array('required', 'optional', 'retired'), $entry['path'] . ' status');
            if ($entry['status'] === 'required') {
                $this->assertContains($entry['level'], $levels, $entry['path'] . ' level');
            } else {
                $this->assertArrayNotHasKey('level', $entry, $entry['path'] . ' has a level');
            }
            $this->assertSame(self::isManaged($entry), isset($entry['policy']), $entry['path'] . ' policy');
        }
    }

    // ---- boilerplate ----

    public function testBoilerplateShipsExactlyTheContractsBoilerplateEntries(): void
    {
        $files = self::listFolder(self::boilerplateRoot() . '/site');
        $missing = array_values(array_diff(
            self::contractPaths(function ($entry) {
                return isset($entry['boilerplate']) && $entry['boilerplate'] === true;
            }),
            $files
        ));
        $extra = array();
        $retired = array();
        foreach ($files as $file) {
            $entry = self::classify($file);
            if ($entry !== null && $entry['status'] === 'retired') {
                $retired[] = $file;
            } elseif ($entry === null || empty($entry['boilerplate'])) {
                $extra[] = $file;
            }
        }
        $this->assertSame(array(), $missing, 'the contract says the boilerplate ships these; add the file or change system/boilerplate/site-contract.json');
        $this->assertSame(array(), $extra, 'the boilerplate ships files no boilerplate entry covers; add them to system/boilerplate/site-contract.json (and the json-outline-schema and haxcms-nodejs copies)');
        $this->assertSame(array(), $retired, 'the boilerplate still ships a retired path');
        // files/ must exist in every site, so the boilerplate carries it (kept
        // in git by files/.gitkeep)
        $this->assertSame(array(), self::missingFolders(self::boilerplateRoot() . '/site'));
        $this->assertContains('files/.gitkeep', $files);
    }

    // ---- managed files ----

    public function testManagedTemplateFilesMatchTheContractsManagedEntries(): void
    {
        $listed = array_values((new HAXCMSSite())->getManagedTemplateFiles());
        sort($listed);
        $expected = array_values(array_diff(
            self::contractPaths(function ($entry) {
                return self::isManaged($entry);
            }),
            self::MANAGED_OUTSIDE_TEMPLATE_LIST
        ));
        $this->assertSame($expected, $listed);
        foreach (self::MANAGED_OUTSIDE_TEMPLATE_LIST as $file) {
            $entry = self::classify($file);
            $this->assertNotNull($entry, $file . ' has no contract entry');
            $this->assertTrue(self::isManaged($entry), $file . ' is not managed in the contract');
        }
    }

    public function testEachManagedFilePolicyMatchesTheContract(): void
    {
        foreach ((new HAXCMSSite())->getManagedTemplateFiles() as $key => $file) {
            $entry = self::classify($file);
            $this->assertNotNull($entry, $file . ' has no contract entry');
            $policy = ManagedFiles::getPolicy($key);
            $this->assertSame($policy['policy'], $entry['policy'], $file . ' policy');
            $this->assertSame($entry['policy'] === 'replace' ? 'managed' : 'partly-managed', $entry['owner'], $file . ' owner');
            $this->assertSame('required', $entry['status'], $file . ' status');
        }
    }

    // ---- a site this backend just made ----

    public function testNewSiteHoldsEveryRequiredPathNothingRetiredAndNothingUnknown(): void
    {
        $build = new stdClass();
        $build->structure = 'course';
        $build->type = '6w';
        $site = new HAXCMSNewSiteTestSite();
        $site->newSite($this->tmpRoot, '/', 'Contract Site', new stdClass(), null, $build);
        $siteDir = $this->tmpRoot . '/contract-site';
        $this->assertDirectoryExists($siteDir);
        $files = self::listFolder($siteDir);

        // Feeds, sitemaps and the search index come from
        // updateAlternateFormats(), which this fixture records instead of
        // running (RSSFeedTest covers it; the cross-backend parity run in
        // haxtheweb/create checks them on a real install).
        $this->assertNotEmpty($site->updateAlternateFormatsCalls);
        $missing = array_values(array_diff(
            self::contractPaths(function ($entry) {
                return $entry['status'] === 'required' && $entry['owner'] !== 'generated';
            }),
            $files
        ));
        $this->assertSame(array(), $missing, 'required by the contract, not in a new site');
        $this->assertSame(array(), self::missingFolders($siteDir), 'a new site lacks a folder the contract requires');

        $unknown = array();
        $retired = array();
        foreach ($files as $file) {
            $entry = self::classify($file);
            if ($entry === null) {
                $unknown[] = $file;
            } elseif ($entry['status'] === 'retired') {
                $retired[] = $file;
            }
        }
        $this->assertSame(array(), $unknown, 'a new site holds files the contract does not know; add them to system/boilerplate/site-contract.json (and the json-outline-schema and haxcms-nodejs copies)');
        $this->assertSame(array(), $retired, 'a new site holds a retired path');

        // content level: a file at every item's location, inside the site
        $manifest = json_decode(file_get_contents($siteDir . '/site.json'));
        $this->assertCount(7, $manifest->items);
        foreach ($manifest->items as $item) {
            $this->assertSame('pages/' . $item->id . '/index.html', $item->location);
            $this->assertContains($item->location, $files, $item->location . ' is missing');
        }
    }

    // ---- the contract says "managed"; the rebuild has to agree ----

    public function testRebuildRewritesEveryFileTheContractCallsManaged(): void
    {
        $site = new HAXCMSNewSiteTestSite();
        $site->newSite($this->tmpRoot, '/', 'Contract Rebuild', new stdClass(), null, null);
        $siteDir = $this->tmpRoot . '/contract-rebuild';
        // whole-file managed entries, except the skills index (covered by
        // AgentSkillsIntegrityTest: it is copied with its skill files)
        $managed = array_values(array_diff(
            self::contractPaths(function ($entry) {
                return $entry['owner'] === 'managed' && $entry['policy'] === 'replace';
            }),
            array('.well-known/agent-skills/index.json')
        ));
        $this->assertContains('ghpages.html', $managed);
        $this->assertContains('index.php', $managed);
        $this->assertContains('config.php', $managed);
        $damage = 'damaged by SiteContractTest';
        $before = array();
        foreach ($managed as $file) {
            $before[$file] = file_get_contents($siteDir . '/' . $file);
            file_put_contents($siteDir . '/' . $file, $damage);
        }
        $site->rebuildManagedFiles();
        $notRewritten = array();
        foreach ($managed as $file) {
            if (file_get_contents($siteDir . '/' . $file) === $damage) {
                $notRewritten[] = $file;
            }
        }
        $this->assertSame(array(), $notRewritten, 'managed in the contract but left alone by rebuildManagedFiles()');
        // and the rebuild gives back what site creation wrote (security.txt
        // carries an expiry date that a rebuild may refresh)
        foreach ($managed as $file) {
            if ($file === '.well-known/security.txt') {
                continue;
            }
            $this->assertSame($before[$file], file_get_contents($siteDir . '/' . $file), $file . ' differs after a rebuild');
        }

        // partly managed files: the owner's part survives, HAXcms's part returns
        file_put_contents($siteDir . '/robots.txt', "\nUser-agent: contract-test\nDisallow: /x\n", FILE_APPEND);
        $site->rebuildManagedFiles();
        $robots = file_get_contents($siteDir . '/robots.txt');
        $this->assertStringContainsString('User-agent: contract-test', $robots);
        $this->assertStringContainsString('# BEGIN HAXcms managed', $robots);
    }
}
