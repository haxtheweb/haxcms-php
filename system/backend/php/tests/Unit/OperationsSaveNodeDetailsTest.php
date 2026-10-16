<?php
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/OperationsTestHaxcms.php';

/**
 * Characterization tests for saveNodeDetails' node-detail operation switch
 * (lib/operations/saveNodeDetails.php). The gates (site_token 403,
 * outlineDesigner 403) plus moveDown/setTitle are pinned by
 * OperationsNodeOpsTest; this file drives the REST of the switch:
 *
 *   - structural ops: moveUp, indent, outdent, setParent (incl. empty-string
 *     and nonexistent parent ids) with the REAL pathauto slug cascade
 *     (settings.pathauto = true in the fixture, one child with
 *     overridePathauto so the cascade skips it)
 *   - field ops: setDescription / setTags / setIcon / setImage+setMedia /
 *     setRelatedItems (set + clear), setLocked / setPublished /
 *     setHideInMenu (FILTER_VALIDATE_BOOLEAN shapes)
 *   - setSlug incl. the reserved-route x / x/ prefixes
 *   - setOverridePathauto and the no-op default operation
 *   - failure slices: missing node id 400, pageBreak 403, node-not-found 404
 *
 * The route runs against a REAL manifest (load/save, loadNode reference
 * semantics, getUniqueSlugName) on a temp site fixture; git and alternate-
 * format collaborators are stubbed on the site subclass (same pattern as
 * OperationsNodeOpsTest). Expected values are read back from the persisted
 * site.json, not re-derived from the implementation.
 */
class OperationsSaveNodeDetailsTest extends TestCase
{
    private $haxcms;
    private $ops;
    private $savedHaxcms;
    private $savedServerSoftware;
    private $tmpRoot;
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

        $this->tmpRoot = sys_get_temp_dir() . '/haxcms_ops_savenodedetails_' . uniqid();
        $this->buildSiteFixture();

        $this->haxcms = new OperationsTestHaxcms();
        $this->haxcms->configDirectory = $this->tmpRoot . '/_config';
        mkdir($this->haxcms->configDirectory, 0777, true);
        $GLOBALS['HAXCMS'] = $this->haxcms;

        $site = new SaveNodeDetailsTestSite();
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

    /**
     * Temp site fixture with pathauto ON so the slug cascade runs:
     *   item-a (order 0, top)
     *   item-b (order 1, top)
     *   item-c (order 0, parent item-a, slug child-page)
     *   item-d (order 1, parent item-a, overridePathauto = true)
     */
    private function buildSiteFixture(): void
    {
        $siteDir = $this->tmpRoot . '/' . $this->siteName;
        foreach (array('item-a', 'item-b', 'item-c', 'item-d') as $id) {
            mkdir($siteDir . '/pages/' . $id, 0777, true);
            file_put_contents($siteDir . '/pages/' . $id . '/index.html', '<p>Content ' . $id . '</p>');
        }
        $item = function (string $id, string $title, string $slug, int $order, $parent, int $indent, array $extraMetadata = array()) {
            return (object)array(
                'id' => $id,
                'indent' => $indent,
                'location' => 'pages/' . $id . '/index.html',
                'slug' => $slug,
                'order' => $order,
                'parent' => $parent,
                'title' => $title,
                'description' => '',
                'metadata' => (object)array_merge(
                    array('created' => 1000000, 'updated' => 1000000),
                    $extraMetadata
                ),
            );
        };
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
            'items' => array(
                $item('item-a', 'Item A', 'item-a', 0, null, 0),
                $item('item-b', 'Item B', 'item-b', 1, null, 0),
                $item('item-c', 'Child Page', 'child-page', 0, 'item-a', 1),
                $item('item-d', 'Item D', 'item-d', 1, 'item-a', 1, array('overridePathauto' => true)),
            ),
        );
        file_put_contents($siteDir . '/site.json', json_encode($manifest, JSON_PRETTY_PRINT));
    }

    private function siteJsonPath(): string
    {
        return $this->tmpRoot . '/' . $this->siteName . '/site.json';
    }

    /**
     * Read the persisted site.json items keyed by id (independent source of
     * truth for every mutation below).
     */
    private function persistedById(): array
    {
        $decoded = json_decode(file_get_contents($this->siteJsonPath()));
        $byId = array();
        if (isset($decoded->items)) {
            foreach ($decoded->items as $item) {
                $byId[$item->id] = $item;
            }
        }
        return $byId;
    }

    private function runOperation(string $id, array $details): array
    {
        $this->haxcms->validRequestToken = true;
        $this->ops->params = array(
            'site_token' => 'good',
            'site' => array('name' => $this->siteName),
            'node' => array('id' => $id, 'details' => $details),
        );
        return $this->ops->saveNodeDetails();
    }

    private function disablePlatformFeature(string $feature): void
    {
        $site = $this->haxcms->loadedSite;
        $site->manifest->metadata->platform->features->$feature = false;
    }

    // =========================================================================
    // Failure slices
    // =========================================================================

    public function testMissingNodeIdFailsWith400(): void
    {
        $this->haxcms->validRequestToken = true;
        $this->ops->params = array(
            'site_token' => 'good',
            'site' => array('name' => $this->siteName),
            'node' => array('details' => array('operation' => 'setTitle', 'title' => 'x')),
        );
        $result = $this->ops->saveNodeDetails();
        $this->assertSame(400, $result['__failed']['status']);
        $this->assertSame('Missing node id', $result['__failed']['message']);
    }

    public function testPageDetailOperationRefusedWhenPageBreakDisabled(): void
    {
        $this->disablePlatformFeature('pageBreak');
        $result = $this->runOperation('item-a', array('operation' => 'setTitle', 'title' => 'x'));
        $this->assertSame(403, $result['__failed']['status']);
        $this->assertSame('Page details editing is disabled for this site', $result['__failed']['message']);
    }

    public function testUnknownNodeIdFailsWith404(): void
    {
        $result = $this->runOperation('ghost-node', array('operation' => 'setTitle', 'title' => 'x'));
        $this->assertSame(404, $result['__failed']['status']);
        $this->assertSame('Node not found', $result['__failed']['message']);
    }

    // =========================================================================
    // Structural operations
    // =========================================================================

    public function testMoveUpSwapsOrderWithPreviousSibling(): void
    {
        $result = $this->runOperation('item-b', array('operation' => 'moveUp'));
        $this->assertSame(200, $result['status']);
        $this->assertSame('item-b', $result['data']->id);
        $this->assertSame(0, (int) $result['data']->order);

        $byId = $this->persistedById();
        $this->assertSame(0, (int) $byId['item-b']->order);
        $this->assertSame(1, (int) $byId['item-a']->order);
    }

    public function testMoveUpOnFirstSiblingIsNoOp(): void
    {
        $result = $this->runOperation('item-a', array('operation' => 'moveUp'));
        $this->assertSame(200, $result['status']);
        $this->assertSame(0, (int) $result['data']->order);
        $byId = $this->persistedById();
        $this->assertSame(0, (int) $byId['item-a']->order);
        $this->assertSame(1, (int) $byId['item-b']->order);
    }

    public function testIndentReparentsUnderPreviousSibling(): void
    {
        // item-b (order 1) indents under item-a (order 0): parent item-a,
        // indent 1, order after the current last child (item-d order 1) => 2.
        $result = $this->runOperation('item-b', array('operation' => 'indent'));
        $this->assertSame(200, $result['status']);
        $this->assertSame('item-a', $result['data']->parent);
        $this->assertSame(1, (int) $result['data']->indent);
        $this->assertSame(2, (int) $result['data']->order);

        $byId = $this->persistedById();
        $this->assertSame('item-a', $byId['item-b']->parent);
        $this->assertSame(2, (int) $byId['item-b']->order);
    }

    public function testOutdentMovesUnderGrandparentAndShiftsSiblings(): void
    {
        // item-c (parent item-a, order 0) outdents: new parent = item-a's
        // parent (null), inserted after item-a (order 0) => order 1, and the
        // old order-1 sibling in that group (item-b) shifts to order 2.
        $result = $this->runOperation('item-c', array('operation' => 'outdent'));
        $this->assertSame(200, $result['status']);
        $this->assertNull($result['data']->parent);
        $this->assertSame(0, (int) $result['data']->indent);
        $this->assertSame(1, (int) $result['data']->order);

        $byId = $this->persistedById();
        $this->assertNull($byId['item-c']->parent);
        $this->assertSame(1, (int) $byId['item-c']->order);
        $this->assertSame(2, (int) $byId['item-b']->order);
    }

    public function testSetParentMovesUnderParentWithIndentAndOrder(): void
    {
        $result = $this->runOperation('item-c', array(
            'operation' => 'setParent',
            'parent' => 'item-b',
            'order' => 5,
        ));
        $this->assertSame(200, $result['status']);
        $this->assertSame('item-b', $result['data']->parent);
        $this->assertSame(1, (int) $result['data']->indent);
        $this->assertSame(5, (int) $result['data']->order);

        $byId = $this->persistedById();
        $this->assertSame('item-b', $byId['item-c']->parent);
        $this->assertSame(5, (int) $byId['item-c']->order);
    }

    public function testSetParentWithEmptyStringParentClearsParentAndIndent(): void
    {
        $result = $this->runOperation('item-c', array(
            'operation' => 'setParent',
            'parent' => '',
            'order' => 0,
        ));
        $this->assertSame(200, $result['status']);
        $this->assertNull($result['data']->parent);
        $this->assertSame(0, (int) $result['data']->indent);
        $byId = $this->persistedById();
        $this->assertNull($byId['item-c']->parent);
        $this->assertSame(0, (int) $byId['item-c']->indent);
    }

    public function testSetParentWithUnknownParentFallsBackToIndentOne(): void
    {
        $result = $this->runOperation('item-c', array(
            'operation' => 'setParent',
            'parent' => 'ghost-parent',
            'order' => 3,
        ));
        $this->assertSame(200, $result['status']);
        $this->assertSame('ghost-parent', $result['data']->parent);
        $this->assertSame(1, (int) $result['data']->indent);
    }

    // =========================================================================
    // setTitle + the real pathauto slug cascade
    // =========================================================================

    public function testSetTitleRegeneratesSlugAndCascadesToChildrenWithoutOverride(): void
    {
        $result = $this->runOperation('item-a', array('operation' => 'setTitle', 'title' => 'New <b>Title</b>'));
        $this->assertSame(200, $result['status']);
        $this->assertSame('New Title', $result['data']->title);

        $byId = $this->persistedById();
        // page slug regenerated from the cleaned title
        $this->assertSame('new-title', $byId['item-a']->slug);
        // child without overridePathauto cascaded to its own cleaned title,
        // nested under the parent's regenerated slug (hierarchical slugs)
        $this->assertSame('new-title/child-page', $byId['item-c']->slug);
        // child WITH overridePathauto was skipped by the cascade
        $this->assertSame('item-d', $byId['item-d']->slug);
    }

    public function testSetTitleWithEmptyTitleLeavesTitleUnchanged(): void
    {
        $result = $this->runOperation('item-a', array('operation' => 'setTitle', 'title' => ''));
        $this->assertSame(200, $result['status']);
        $this->assertSame('Item A', $result['data']->title);
        $byId = $this->persistedById();
        $this->assertSame('Item A', $byId['item-a']->title);
        $this->assertSame('item-a', $byId['item-a']->slug);
    }

    // =========================================================================
    // Field operations
    // =========================================================================

    public function testSetDescriptionStripsTagsAndClearsToEmptyString(): void
    {
        $result = $this->runOperation('item-a', array(
            'operation' => 'setDescription',
            'description' => 'A <b>fancy</b> description',
        ));
        $this->assertSame(200, $result['status']);
        $this->assertSame('A fancy description', $result['data']->description);

        // clearing: an explicit empty string resets the description
        $result2 = $this->runOperation('item-a', array(
            'operation' => 'setDescription',
            'description' => '',
        ));
        $this->assertSame(200, $result2['status']);
        $this->assertSame('', $result2['data']->description);
    }

    public function testSetTagsSetsSanitizedValueAndUnsetRemovesIt(): void
    {
        $result = $this->runOperation('item-a', array(
            'operation' => 'setTags',
            'tags' => 'one, two',
        ));
        $this->assertSame(200, $result['status']);
        $this->assertSame(array('one', 'two'), (array) $result['data']->metadata->tags);

        // clearing: empty string unsets the tags entry
        $result2 = $this->runOperation('item-a', array('operation' => 'setTags', 'tags' => ''));
        $this->assertSame(200, $result2['status']);
        $this->assertFalse(isset($result2['data']->metadata->tags));
    }

    public function testSetIconSetsAndUnsetRemovesIt(): void
    {
        $result = $this->runOperation('item-a', array(
            'operation' => 'setIcon',
            'icon' => 'icons:favorite',
        ));
        $this->assertSame(200, $result['status']);
        $this->assertSame('icons:favorite', $result['data']->metadata->icon);

        $result2 = $this->runOperation('item-a', array('operation' => 'setIcon', 'icon' => ''));
        $this->assertSame(200, $result2['status']);
        $this->assertFalse(isset($result2['data']->metadata->icon));
    }

    public function testSetImageAndSetMediaAliasShareTheImageBranch(): void
    {
        $result = $this->runOperation('item-a', array(
            'operation' => 'setImage',
            'image' => 'files/banner.jpg',
        ));
        $this->assertSame(200, $result['status']);
        $this->assertSame('files/banner.jpg', $result['data']->metadata->image);

        // setMedia routes through the same branch
        $result2 = $this->runOperation('item-b', array(
            'operation' => 'setMedia',
            'image' => 'files/media.jpg',
        ));
        $this->assertSame(200, $result2['status']);
        $this->assertSame('files/media.jpg', $result2['data']->metadata->image);

        // clearing
        $result3 = $this->runOperation('item-a', array('operation' => 'setImage', 'image' => ''));
        $this->assertSame(200, $result3['status']);
        $this->assertFalse(isset($result3['data']->metadata->image));
    }

    public function testSetRelatedItemsSetsAndUnsetRemovesIt(): void
    {
        $result = $this->runOperation('item-a', array(
            'operation' => 'setRelatedItems',
            'relatedItems' => 'item-b',
        ));
        $this->assertSame(200, $result['status']);
        $this->assertSame(array('item-b'), (array) $result['data']->metadata->relatedItems);

        $result2 = $this->runOperation('item-a', array('operation' => 'setRelatedItems', 'relatedItems' => ''));
        $this->assertSame(200, $result2['status']);
        $this->assertFalse(isset($result2['data']->metadata->relatedItems));
    }

    public function testSetLockedInterpretsBooleanStrings(): void
    {
        $result = $this->runOperation('item-a', array('operation' => 'setLocked', 'locked' => 'true'));
        $this->assertSame(200, $result['status']);
        $this->assertTrue($result['data']->metadata->locked);

        $result2 = $this->runOperation('item-a', array('operation' => 'setLocked', 'locked' => 'false'));
        $this->assertSame(200, $result2['status']);
        $this->assertFalse($result2['data']->metadata->locked);
    }

    public function testSetPublishedInterpretsBooleanStrings(): void
    {
        $result = $this->runOperation('item-a', array('operation' => 'setPublished', 'published' => 'true'));
        $this->assertSame(200, $result['status']);
        $this->assertTrue($result['data']->metadata->published);

        $result2 = $this->runOperation('item-a', array('operation' => 'setPublished', 'published' => 'false'));
        $this->assertSame(200, $result2['status']);
        $this->assertFalse($result2['data']->metadata->published);
    }

    public function testSetHideInMenuInterpretsBooleanStrings(): void
    {
        $result = $this->runOperation('item-a', array('operation' => 'setHideInMenu', 'hideInMenu' => 'true'));
        $this->assertSame(200, $result['status']);
        $this->assertTrue($result['data']->metadata->hideInMenu);

        $result2 = $this->runOperation('item-a', array('operation' => 'setHideInMenu', 'hideInMenu' => 'false'));
        $this->assertSame(200, $result2['status']);
        $this->assertFalse($result2['data']->metadata->hideInMenu);
    }

    // =========================================================================
    // setSlug (reserved route prefixes) + setOverridePathauto + default
    // =========================================================================

    public function testSetSlugRewritesReservedXRoutePrefixes(): void
    {
        $result = $this->runOperation('item-a', array('operation' => 'setSlug', 'slug' => 'x'));
        $this->assertSame(200, $result['status']);
        // 'x' is the reserved front-end route: rewritten to x-x
        $this->assertSame('x-x', $result['data']->slug);
        // a manual slug marks the page as overriding pathauto
        $this->assertTrue($result['data']->metadata->overridePathauto);
    }

    public function testSetSlugRewritesReservedXSubRoutePrefixes(): void
    {
        $result = $this->runOperation('item-a', array('operation' => 'setSlug', 'slug' => 'x/search'));
        $this->assertSame(200, $result['status']);
        // 'x/' sub-route prefix rewritten to 'x-x/'; generateSlugName then
        // flattens the remaining slash to a dash
        $this->assertSame('x-x-search', $result['data']->slug);
        $this->assertTrue($result['data']->metadata->overridePathauto);
    }

    public function testSetOverridePathautoStoresBoolean(): void
    {
        $result = $this->runOperation('item-a', array(
            'operation' => 'setOverridePathauto',
            'overridePathauto' => 'true',
        ));
        $this->assertSame(200, $result['status']);
        $this->assertTrue($result['data']->metadata->overridePathauto);
    }

    public function testUnknownOperationIsANoOpThatStillSavesAndCommits(): void
    {
        $result = $this->runOperation('item-a', array('operation' => 'notARealOperation'));
        $this->assertSame(200, $result['status']);
        $this->assertSame('item-a', $result['data']->id);
        $this->assertSame('Item A', $result['data']->title);

        // the route still persisted + committed with the operation name
        $site = $this->haxcms->loadedSite;
        $this->assertSame(
            'Node operation: notARealOperation on Item A (item-a)',
            $site->gitCommits[0]
        );
        $byId = $this->persistedById();
        $this->assertSame('Item A', $byId['item-a']->title);
    }
}

/**
 * HAXCMSSite test subclass stubbing only the git + alternate-format
 * collaborators (same shape as OperationsNodeTestSite). The REAL
 * manifest/loadNode/save, getUniqueSlugName, isPathautoEnabled and
 * getPageContent run against the temp fixture.
 */
class SaveNodeDetailsTestSite extends HAXCMSSite
{
    public $gitCommits = array();

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
        return null;
    }

    public function writePageAlternateFormats($page, $htmlContent = '')
    {
        return true;
    }
}
