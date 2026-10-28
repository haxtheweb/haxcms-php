<?php
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/OperationsTestHaxcms.php';

/**
 * The outline designer and saveOutline have to agree on the data they pass:
 * what the designer shows after an edit is what saveOutline must store.
 * Mirrors haxcms-nodejs test/unit/save-outline-designer-contract.test.cjs.
 *
 * fixtures/outline-designer-sessions.json holds real outline-designer
 * getData() output for a set of editing sessions (keyboard moves, indent and
 * outdent, deleting a parent with its sub-pages, delete then undo, adding a
 * page part-way down a list, adding at the top, duplicating, locking,
 * deleting a selection, a mouse drag, and a combined edit), captured from
 * haxtheweb/webcomponents (see its capturedFrom). Each session records:
 *   - sent:  the items the designer hands to saveOutline
 *   - shown: the outline the designer showed, as "title<parent title" in the
 *            order the rows appeared (deleted pages left out)
 * The same fixture and test live in haxcms-nodejs; when the designer changes
 * what it sends, regenerate the fixture and update both.
 */
class OperationsSaveOutlineDesignerContractTest extends TestCase
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
        $this->fixtureDirName = 'fixtures_outline_contract_' . uniqid();
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

    private static function fixture(): stdClass
    {
        return json_decode(file_get_contents(__DIR__ . '/fixtures/outline-designer-sessions.json'));
    }

    /**
     * a site holding exactly the outline the designer was opened on, with
     * the same page ids, and a contents file per page
     */
    private function buildSite(): ContractTestSite
    {
        $this->rrmdir($this->tmpRoot);
        $siteDir = $this->tmpRoot . '/' . $this->siteName;
        $items = array();
        foreach (self::fixture()->initial as $source) {
            @mkdir(dirname($siteDir . '/' . $source->location), 0777, true);
            file_put_contents($siteDir . '/' . $source->location, '<p>' . $source->title . ' contents</p>');
            $items[] = (object) array(
                'id' => $source->id,
                'indent' => $source->indent,
                'location' => $source->location,
                'slug' => $source->slug,
                'order' => $source->order,
                'parent' => $source->parent,
                'title' => $source->title,
                'description' => '',
                'metadata' => (object) array('created' => 1700000000, 'updated' => 1700000000),
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
        $haxcms = new ContractTestHaxcms();
        $haxcms->configDirectory = $this->tmpRoot . '/_config';
        @mkdir($haxcms->configDirectory, 0777, true);
        $haxcms->validRequestToken = true;
        $haxcms->sitesDirectory = $this->fixtureDirName;
        $GLOBALS['HAXCMS'] = $haxcms;
        $site = new ContractTestSite();
        $site->load($this->tmpRoot, '/', $this->siteName);
        $haxcms->loadedSite = $site;
        $this->ops = new Operations();
        return $site;
    }

    /** the stored outline, read the way it is displayed */
    private function storedTree(array $items): array
    {
        $titles = array();
        foreach ($items as $i) {
            $titles[$i->id] = $i->title;
        }
        $out = array();
        $walk = function ($parent) use (&$walk, &$out, $items, $titles) {
            $kids = array();
            foreach ($items as $position => $i) {
                $p = empty($i->parent) ? null : $i->parent;
                if ($p === $parent) {
                    $kids[] = array($i, $position);
                }
            }
            usort($kids, function ($x, $y) {
                return ((int) $x[0]->order <=> (int) $y[0]->order) ?: ($x[1] <=> $y[1]);
            });
            foreach ($kids as $k) {
                $out[] = $k[0]->title . '<' . ($parent === null ? 'root' : $titles[$parent]);
                $walk($k[0]->id);
            }
        };
        $walk(null);
        return $out;
    }

    public static function sessions(): array
    {
        $cases = array();
        foreach (self::fixture()->scenarios as $session) {
            $cases[$session->name] = array($session->name);
        }
        return $cases;
    }

    public function testFixtureIsRealDesignerOutput(): void
    {
        $fixture = self::fixture();
        $this->assertStringContainsString('outline-designer', $fixture->capturedFrom);
        $this->assertGreaterThanOrEqual(10, count($fixture->scenarios));
    }

    /**
     * @dataProvider sessions
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('sessions')]
    public function testWhatTheDesignerShowedIsWhatIsStored(string $name): void
    {
        $session = null;
        foreach (self::fixture()->scenarios as $candidate) {
            if ($candidate->name === $name) {
                $session = $candidate;
            }
        }
        $this->buildSite();
        $this->ops->params = array('site_token' => 'good', 'site' => array('name' => $this->siteName));
        $this->ops->rawParams = array('items' => json_decode(json_encode($session->sent), true));
        $result = $this->ops->saveOutline();
        $this->assertArrayNotHasKey('__failed', $result, $session->description);

        $siteDir = $this->tmpRoot . '/' . $this->siteName;
        $onDisk = json_decode(file_get_contents($siteDir . '/site.json'));
        // what the designer showed is what was stored
        $this->assertSame($session->shown, $this->storedTree($onDisk->items), $session->description);
        foreach ($session->sent as $sent) {
            $kept = null;
            foreach ($onDisk->items as $i) {
                if ($i->id === $sent->id || $i->title === $sent->title) {
                    $kept = $i;
                }
            }
            // deleted pages are gone; every page sent and not deleted is there
            if (!empty($sent->delete)) {
                $this->assertNull($kept, $sent->title . ' is deleted');
                continue;
            }
            $this->assertNotNull($kept, $sent->title . ' is kept');
            // per-page settings the designer changed are kept
            if (isset($sent->metadata->locked) && is_bool($sent->metadata->locked) && $kept->id === $sent->id) {
                $this->assertSame($sent->metadata->locked, !empty($kept->metadata->locked), $sent->title . ' keeps its lock state');
            }
            // a duplicate gets the contents of the page it copies
            if (isset($sent->duplicate)) {
                $source = null;
                foreach ($onDisk->items as $i) {
                    if ($i->id === $sent->duplicate) {
                        $source = $i;
                    }
                }
                $this->assertNotNull($source, 'the duplicate source exists');
                $this->assertSame(
                    file_get_contents($siteDir . '/' . $source->location),
                    file_get_contents($siteDir . '/' . $kept->location)
                );
            }
        }
    }
}

class ContractTestHaxcms extends OperationsTestHaxcms
{
    public $outlineSchema;

    public function __construct()
    {
        parent::__construct();
        $this->outlineSchema = new JSONOutlineSchema();
    }

    public function generateMachineName($name)
    {
        $name = preg_replace('/[^a-zA-Z0-9_-]+/', '-', (string) $name);
        $name = strtolower(trim($name, '-_'));
        return $name === '' ? 'default' : $name;
    }
}

/** HAXCMSSite double; git and feed rebuilds stubbed, everything else real */
class ContractTestSite extends HAXCMSSite
{
    public function gitCommit($msg = 'Committed changes')
    {
        return true;
    }

    public function rebuildManagedFiles($templates = array())
    {
        return null;
    }

    public function updateAlternateFormats($format = null)
    {
        return null;
    }

    public function writePageAlternateFormats($page, $htmlContent = '')
    {
        return true;
    }
}
