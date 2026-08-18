<?php
/**
 * Shared test fixtures + helpers for lib/siteRoutes/v1/*.php route-handler
 * characterization tests (Phase 3, Area C).
 *
 * Not a PHPUnit test case itself (does not end in Test.php so the phpunit.xml
 * <directory>tests/Unit</directory> suite does not try to run it as a suite),
 * but each route test file that needs these fixtures must require_once this
 * file directly, since the bootstrap does not autoload tests/.
 */

/**
 * Minimal fake manifest exposing just the surface SiteRouteUtils actually
 * calls via method_exists(): orderTree, getItemById, findBranch. The real
 * JSONOutlineSchema class defines orderTree/getItemById but no
 * getItemByProperty, so findItemByIdOrSlug falls back to its own linear scan
 * for slug lookups either way -- this fake mirrors that same real contract.
 */
class SiteRoutesFakeManifest
{
    public $items = array();
    public $metadata;
    public $title = 'Test Site';

    public function __construct()
    {
        $this->metadata = new stdClass();
        $this->metadata->site = new stdClass();
        $this->metadata->site->name = 'testsite';
    }

    /**
     * Simple stand-in for JSONOutlineSchema::orderTree: stable sort by
     * 'order' ascending. Does not implement the full parent/child recursion
     * of the real implementation, but the fixtures below always provide
     * items in the desired final order via explicit 'order' values, so this
     * is sufficient for characterizing the route handlers built on top of
     * getOrderedItems().
     */
    public function orderTree($items)
    {
        $copy = array_values($items);
        usort($copy, function ($a, $b) {
            $aOrder = isset($a->order) ? $a->order : 0;
            $bOrder = isset($b->order) ? $b->order : 0;
            if ($aOrder == $bOrder) {
                return 0;
            }
            return ($aOrder < $bOrder) ? -1 : 1;
        });
        return $copy;
    }

    public function getItemById($id)
    {
        foreach ($this->items as $item) {
            if (isset($item->id) && (string) $item->id === (string) $id) {
                return $item;
            }
        }
        return false;
    }

    public function findBranch($ancestorId)
    {
        $out = array();
        foreach ($this->items as $item) {
            $id = isset($item->id) ? (string) $item->id : '';
            $parent = isset($item->parent) ? (string) $item->parent : '';
            if ($id === (string) $ancestorId || $parent === (string) $ancestorId) {
                $out[] = $item;
            }
        }
        return $out;
    }
}

/**
 * Minimal fake site. SiteRouteUtils::getItemContent() calls
 * $site->loadNode($id) then $site->getPageContent($page); this fake returns
 * canned HTML from a per-id map (pageContentMap) so route handlers that
 * include content (items?include=content, search, content.php, blocks.php,
 * reports.php) run against deterministic content without a real pages/
 * filesystem fixture. Mirrors ExportConvertersTestSite's established pattern
 * (tests/Unit/ExportConvertersFsTest.php) but under a distinct class name to
 * avoid cross-file redeclaration.
 */
class SiteRoutesFakeSite
{
    public $manifest;
    public $siteDirectory = '';
    public $directory = '';
    public $name = 'testsite';
    public $language = 'en';
    public $basePath = '';
    public $pageContentMap = array();

    public function __construct()
    {
        $this->manifest = new SiteRoutesFakeManifest();
    }

    public function loadNode($id)
    {
        return (object) array('id' => (string) $id);
    }

    public function getPageContent($page)
    {
        $id = isset($page->id) ? (string) $page->id : '';
        if (is_array($this->pageContentMap) && array_key_exists($id, $this->pageContentMap)) {
            return $this->pageContentMap[$id];
        }
        return '';
    }
}

/**
 * Build a JSONOutlineSchemaItem-shaped stdClass item. $metadata is an
 * associative array converted to a stdClass (matching how real manifest
 * items decode from JSON), or null for "no metadata property at all" (some
 * SiteRouteUtils code checks isset($item->metadata) before touching it).
 */
function makeSiteRouteItem(
    $id,
    $slug,
    $title,
    $parent = '',
    $order = 0,
    $indent = 0,
    $location = '',
    $description = '',
    $metadata = array()
) {
    $item = new stdClass();
    $item->id = $id;
    $item->slug = $slug;
    $item->title = $title;
    $item->parent = $parent;
    $item->order = $order;
    $item->indent = $indent;
    $item->location = $location != '' ? $location : ('pages/' . $id . '/index.html');
    $item->description = $description;
    if ($metadata !== null) {
        $item->metadata = (object) $metadata;
    }
    return $item;
}

/**
 * Build a SiteApiRequestContext-shaped context for invoking a route handler
 * closure directly, bypassing HTTP. Sets routeSuffix/apiBasePath/params/auth
 * directly on the public properties (avoiding the constructor's $_SERVER
 * sniffing) per the pattern in tests/v1-integration-tests.php.
 */
function makeSiteRouteContext(
    $site,
    $params = array(),
    $routeSuffix = '',
    $apiBasePath = '/x/api',
    $authenticated = true
) {
    $context = new SiteApiRequestContext($site);
    $context->apiBasePath = $apiBasePath;
    $context->routeSuffix = $routeSuffix;
    $context->setRouteParams($params);
    $context->auth = array(
        'authenticated' => $authenticated,
        'userName' => $authenticated ? 'test-user' : '',
    );
    return $context;
}

/**
 * Include a lib/siteRoutes/v1/<name>.php route handler file (which returns a
 * closure), invoke it with $context, capture stdout via ob_start/ob_get_clean
 * (mirrors tests/v1-integration-tests.php), and json_decode the JSON body.
 * Returns an array with 'raw' (string) and 'data' (decoded array|null).
 */
function invokeSiteRouteHandler($handlerFileName, $context)
{
    $path = dirname(__DIR__, 2) . '/lib/siteRoutes/v1/' . $handlerFileName;
    $handler = include $path;
    ob_start();
    $handler($context);
    $raw = ob_get_clean();
    $data = json_decode($raw, true);
    return array('raw' => $raw, 'data' => $data);
}
