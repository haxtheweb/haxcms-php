<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/SiteRoutesTestHelpers.php';

/**
 * SiteApiRequestContext subclass with injected body + headers so the
 * normalize-slugs handler sees controlled getBody()/getHeader() values
 * (same pattern as SiteMutationRouteTestContext, distinct class name).
 */
class SiteNormalizeSlugsTestContext extends SiteApiRequestContext
{
    public $injectedBody = array();
    public $injectedHeaders = array();

    public function getBody()
    {
        return $this->injectedBody;
    }

    public function getHeader($name)
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        if (array_key_exists($key, $this->injectedHeaders)) {
            return $this->injectedHeaders[$key];
        }
        return parent::getHeader($name);
    }
}

/**
 * Minimal manifest fake: array items, metadata->site->name, save() recorder.
 */
class SiteNormalizeSlugsFakeManifest
{
    public $items = array();
    public $metadata;
    public $title = 'Test Site';
    public $saveCalls = array();

    public function __construct($items = array())
    {
        $this->items = $items;
        $this->metadata = new stdClass();
        $this->metadata->site = new stdClass();
        $this->metadata->site->name = 'testsite';
    }

    public function save($value)
    {
        $this->saveCalls[] = $value;
        return true;
    }
}

/**
 * Site fake with just the surface siteNormalizeSlugs.php touches:
 * isPathautoEnabled, getUniqueSlugName (deterministic slugify + collision
 * suffix against sibling slugs), updateAlternateFormats, gitCommit.
 */
class SiteNormalizeSlugsFakeSite
{
    public $manifest;
    public $siteDirectory = '';
    public $pathauto = false;
    public $gitCommits = array();
    public $alternateFormatsCalls = 0;

    public function __construct($items = array())
    {
        $this->manifest = new SiteNormalizeSlugsFakeManifest($items);
    }

    public function isPathautoEnabled()
    {
        return $this->pathauto;
    }

    public function getUniqueSlugName($title, $item, $flag)
    {
        $base = strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '-', (string) $title), '-'));
        $taken = array();
        foreach ($this->manifest->items as $other) {
            if ((string) $other->id === (string) $item->id) {
                continue;
            }
            if (isset($other->slug)) {
                $taken[] = (string) $other->slug;
            }
        }
        $slug = $base;
        $counter = 2;
        while (in_array($slug, $taken)) {
            $slug = $base . '-' . $counter;
            $counter++;
        }
        return $slug;
    }

    public function updateAlternateFormats($format = null)
    {
        $this->alternateFormatsCalls++;
        return true;
    }

    public function gitCommit($message)
    {
        $this->gitCommits[] = $message;
        return true;
    }
}

/**
 * HAXCMS global fake: cleanTitle used by the handler plus the request-token
 * surface used by SiteRouteUtils::validateSiteToken.
 */
class SiteNormalizeSlugsFakeHaxcms
{
    public $validTokens = array();

    public function cleanTitle($title)
    {
        return strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '-', (string) $title), '-'));
    }

    public function getRequestTokenUserName()
    {
        return 'testuser';
    }

    public function validateRequestToken($token, $value)
    {
        return isset($this->validTokens[$token]) && $this->validTokens[$token] === $value;
    }
}

/**
 * Route-level characterization tests for
 * lib/siteRoutes/v1/siteNormalizeSlugs.php (bulk slug normalization).
 */
class SiteRoutesNormalizeSlugsTest extends TestCase
{
    protected function setUp(): void
    {
        unset($_GET);
        $_GET = array();
        unset($GLOBALS['HAXCMS']);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['HAXCMS']);
    }

    private function installHaxcmsGlobal()
    {
        $haxcms = new SiteNormalizeSlugsFakeHaxcms();
        $haxcms->validTokens['tok123'] = 'testuser:testsite';
        $GLOBALS['HAXCMS'] = $haxcms;
        return $haxcms;
    }

    private function makeItem($id, $title, $slug, $parent = '', $metadata = null)
    {
        $item = new stdClass();
        $item->id = $id;
        $item->title = $title;
        $item->slug = $slug;
        $item->parent = $parent;
        $item->order = 0;
        if ($metadata !== null) {
            $item->metadata = $metadata;
        }
        return $item;
    }

    private function makeContext($site, $body = array(), $headers = array())
    {
        $context = new SiteNormalizeSlugsTestContext($site);
        $context->apiBasePath = '/x/api';
        $context->routeSuffix = 'v1/site/normalize-slugs';
        $context->setRouteParams(array());
        $context->injectedBody = $body;
        $context->injectedHeaders = $headers;
        return $context;
    }

    private function invoke($context)
    {
        return invokeSiteRouteHandler('siteNormalizeSlugs.php', $context);
    }

    private function tokenHeader()
    {
        return array('HTTP_X_HAXCMS_SITE_TOKEN' => 'tok123');
    }

    public function testMissingSiteReturns404(): void
    {
        $result = $this->invoke($this->makeContext(null));
        $this->assertSame(404, $result['data']['status']);
        $this->assertSame(
            'Unable to resolve site context for /x/api/v1/site/normalize-slugs',
            $result['data']['data']['message']
        );
    }

    public function testMissingSiteNameReturns400(): void
    {
        $site = new SiteNormalizeSlugsFakeSite();
        $site->manifest->metadata->site->name = '';
        $result = $this->invoke($this->makeContext($site));
        $this->assertSame(400, $result['data']['status']);
        $this->assertSame(
            'Unable to resolve site name for /x/api/v1/site/normalize-slugs',
            $result['data']['data']['message']
        );
    }

    public function testSiteNamePropertyAbsentReturns400(): void
    {
        $site = new SiteNormalizeSlugsFakeSite();
        unset($site->manifest->metadata->site->name);
        $result = $this->invoke($this->makeContext($site));
        $this->assertSame(400, $result['data']['status']);
    }

    public function testMissingSiteTokenReturns403(): void
    {
        $this->installHaxcmsGlobal();
        $site = new SiteNormalizeSlugsFakeSite();
        $result = $this->invoke($this->makeContext($site));
        $this->assertSame(403, $result['data']['status']);
        $this->assertSame(
            'X-HAXCMS-Site-Token header is required for this endpoint',
            $result['data']['data']['message']
        );
    }

    public function testInvalidSiteTokenReturns403(): void
    {
        $this->installHaxcmsGlobal();
        $site = new SiteNormalizeSlugsFakeSite();
        $result = $this->invoke($this->makeContext($site, array(), array('HTTP_X_HAXCMS_SITE_TOKEN' => 'wrong-token')));
        $this->assertSame(403, $result['data']['status']);
    }

    public function testPlatformOutlineDesignerDisabledReturns403(): void
    {
        $this->installHaxcmsGlobal();
        $site = new SiteNormalizeSlugsFakeSite(array(
            $this->makeItem('item-a', 'Hello World', 'old-slug'),
        ));
        $site->manifest->metadata->platform = (object) array('outlineDesigner' => false);
        $result = $this->invoke($this->makeContext($site, array(), $this->tokenHeader()));
        $this->assertSame(403, $result['data']['status']);
        $this->assertSame('Outline operations are disabled for this site', $result['data']['data']['message']);
        $this->assertCount(0, $site->manifest->saveCalls);
    }

    public function testPreviewNormalizesSlugsAndReportsChangesWithoutSaving(): void
    {
        $this->installHaxcmsGlobal();
        $site = new SiteNormalizeSlugsFakeSite(array(
            $this->makeItem('item-a', 'Hello World', 'old-slug'),
            $this->makeItem('item-b', 'Hello World', 'second'),
            $this->makeItem('item-c', 'Child Page', 'child', 'item-a'),
        ));
        $result = $this->invoke($this->makeContext(
            $site,
            array('preview' => true),
            $this->tokenHeader()
        ));
        $this->assertSame(200, $result['data']['status']);
        $data = $result['data']['data'];
        $this->assertTrue($data['changed']);
        $this->assertTrue($data['preview']);
        $this->assertSame(array(), $data['skipped']);
        $byId = array();
        foreach ($data['changes'] as $change) {
            $byId[$change['id']] = $change;
        }
        $this->assertSame(array('item-a', 'item-b', 'item-c'), array_keys($byId));
        $this->assertSame('old-slug', $byId['item-a']['oldSlug']);
        $this->assertSame('hello-world', $byId['item-a']['newSlug']);
        // Second 'Hello World' collides with the freshly assigned item-a slug.
        $this->assertSame('hello-world-2', $byId['item-b']['newSlug']);
        $this->assertSame('child-page', $byId['item-c']['newSlug']);
        // Preview mode never persists.
        $this->assertCount(0, $site->manifest->saveCalls);
        $this->assertCount(0, $site->gitCommits);
        $this->assertSame(0, $site->alternateFormatsCalls);
    }

    public function testChildBeforeParentIsProcessedInSecondPass(): void
    {
        $this->installHaxcmsGlobal();
        // Child listed BEFORE its parent: iteration 1 defers it to nextBatch.
        $site = new SiteNormalizeSlugsFakeSite(array(
            $this->makeItem('item-c', 'Child Page', 'child', 'item-a'),
            $this->makeItem('item-a', 'Hello World', 'old-slug'),
        ));
        $result = $this->invoke($this->makeContext(
            $site,
            array('preview' => true),
            $this->tokenHeader()
        ));
        $data = $result['data']['data'];
        $ids = array_column($data['changes'], 'id');
        sort($ids);
        $this->assertSame(array('item-a', 'item-c'), $ids);
    }

    public function testApplyPersistsManifestAndReportsCounts(): void
    {
        $this->installHaxcmsGlobal();
        $site = new SiteNormalizeSlugsFakeSite(array(
            $this->makeItem('item-a', 'Hello World', 'old-slug'),
        ));
        $result = $this->invoke($this->makeContext($site, array(), $this->tokenHeader()));
        $this->assertSame(200, $result['data']['status']);
        $data = $result['data']['data'];
        $this->assertTrue($data['changed']);
        $this->assertFalse($data['preview']);
        $this->assertCount(1, $data['changes']);
        $this->assertSame('hello-world', $data['changes'][0]['newSlug']);
        // Persist path: save(false), updated timestamp, alternate formats, git.
        $this->assertSame(array(false), $site->manifest->saveCalls);
        $this->assertGreaterThan(0, $site->manifest->metadata->site->updated);
        $this->assertSame(1, $site->alternateFormatsCalls);
        $this->assertSame(
            array('Bulk slug normalization: 1 changed, 0 skipped'),
            $site->gitCommits
        );
    }

    public function testNoChangesReportsChangedFalse(): void
    {
        $this->installHaxcmsGlobal();
        $site = new SiteNormalizeSlugsFakeSite(array(
            $this->makeItem('item-a', 'Hello World', 'hello-world'),
        ));
        $result = $this->invoke($this->makeContext(
            $site,
            array('preview' => true),
            $this->tokenHeader()
        ));
        $data = $result['data']['data'];
        $this->assertFalse($data['changed']);
        $this->assertSame(array(), $data['changes']);
    }

    public function testOverridePathautoItemsAreSkippedWhenPathautoEnabled(): void
    {
        $this->installHaxcmsGlobal();
        $site = new SiteNormalizeSlugsFakeSite(array(
            $this->makeItem(
                'item-a',
                'Hello World',
                'locked-slug',
                '',
                (object) array('overridePathauto' => true)
            ),
            $this->makeItem('item-b', 'Other Page', 'other'),
        ));
        $site->pathauto = true;
        $result = $this->invoke($this->makeContext(
            $site,
            array('preview' => true),
            $this->tokenHeader()
        ));
        $data = $result['data']['data'];
        $this->assertTrue($data['changed']);
        $this->assertCount(1, $data['skipped']);
        $this->assertSame('item-a', $data['skipped'][0]['id']);
        $this->assertSame('locked-slug', $data['skipped'][0]['oldSlug']);
        $this->assertSame('overridePathauto', $data['skipped'][0]['reason']);
        $ids = array_column($data['changes'], 'id');
        $this->assertSame(array('item-b'), $ids);
        $this->assertSame('other-page', $data['changes'][0]['newSlug']);
    }

    public function testOverridePathautoIgnoredWhenPathautoDisabled(): void
    {
        $this->installHaxcmsGlobal();
        $site = new SiteNormalizeSlugsFakeSite(array(
            $this->makeItem(
                'item-a',
                'Hello World',
                'locked-slug',
                '',
                (object) array('overridePathauto' => true)
            ),
        ));
        // pathauto disabled (default): overridePathauto is not honored.
        $result = $this->invoke($this->makeContext(
            $site,
            array('preview' => true),
            $this->tokenHeader()
        ));
        $data = $result['data']['data'];
        $this->assertSame(array(), $data['skipped']);
        $this->assertSame('hello-world', $data['changes'][0]['newSlug']);
    }

    public function testPreviewAcceptedFromBodyAndQueryVariants(): void
    {
        $this->installHaxcmsGlobal();
        foreach (array(true, 'true', 1) as $bodyPreview) {
            $site = new SiteNormalizeSlugsFakeSite(array(
                $this->makeItem('item-a', 'Hello World', 'old-slug'),
            ));
            $result = $this->invoke($this->makeContext(
                $site,
                array('preview' => $bodyPreview),
                $this->tokenHeader()
            ));
            $this->assertTrue($result['data']['data']['preview'], var_export($bodyPreview, true));
        }
        foreach (array('true', '1') as $queryPreview) {
            $_GET['preview'] = $queryPreview;
            $site = new SiteNormalizeSlugsFakeSite(array(
                $this->makeItem('item-a', 'Hello World', 'old-slug'),
            ));
            $result = $this->invoke($this->makeContext($site, array(), $this->tokenHeader()));
            $this->assertTrue($result['data']['data']['preview'], var_export($queryPreview, true));
        }
    }

    public function testNonArrayBodyTreatedAsEmpty(): void
    {
        $this->installHaxcmsGlobal();
        $site = new SiteNormalizeSlugsFakeSite(array(
            $this->makeItem('item-a', 'Hello World', 'old-slug'),
        ));
        $context = $this->makeContext($site, array(), $this->tokenHeader());
        $context->injectedBody = null;
        $result = $this->invoke($context);
        $this->assertSame(200, $result['data']['status']);
        $this->assertFalse($result['data']['data']['preview']);
    }
}
