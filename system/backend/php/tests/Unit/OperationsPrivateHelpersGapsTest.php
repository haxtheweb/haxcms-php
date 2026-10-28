<?php
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/OperationsTestHaxcms.php';

/**
 * Gap-filling tests for private helpers on lib/Operations.php, invoked via
 * reflection: scoped Details manifest payloads, appearance/bulk-import/site
 * file normalizers, siteFile MIME sniffing, rename/duplicate path builders,
 * collectSiteFiles (files listing used by siteRoutes files + site routes),
 * resolveSiteFileOperationPath edge cases, GIF/WebP/JPG image-op branches,
 * and the site search parsing helpers.
 *
 * The unreferenced image-op output-path helpers (imgops/ derivative copies)
 * were removed from Operations.php as dead code. The HAXIAM symlink
 * helpers hardcode /var/www/sites, so only their not-found branches are
 * exercised here.
 */
class OperationsPrivateHelpersGapsTest extends TestCase
{
    private $ops;
    private $tmp;
    private $savedHaxcms;

    protected function setUp(): void
    {
        $this->savedHaxcms = isset($GLOBALS['HAXCMS']) ? $GLOBALS['HAXCMS'] : null;
        $GLOBALS['HAXCMS'] = new OperationsTestHaxcms();
        $this->ops = new Operations();
        $this->ops->params = array();
        $this->tmp = sys_get_temp_dir() . '/haxcms_ops_gaps_' . uniqid();
        mkdir($this->tmp, 0777, true);
    }

    protected function tearDown(): void
    {
        if ($this->savedHaxcms !== null) {
            $GLOBALS['HAXCMS'] = $this->savedHaxcms;
        } else {
            unset($GLOBALS['HAXCMS']);
        }
        exec('rm -rf ' . escapeshellarg($this->tmp));
    }

    private function call(string $method, ...$args)
    {
        $ref = new ReflectionMethod('Operations', $method);
        $ref->setAccessible(true);
        return $ref->invokeArgs($this->ops, $args);
    }

    /** A site object rooted at $this->tmp/<name> with a files/ dir. */
    private function site(string $name = 'gapsite', bool $withFiles = true): stdClass
    {
        if ($withFiles) {
            @mkdir($this->tmp . '/' . $name . '/files', 0777, true);
        }
        $site = new stdClass();
        $site->directory = $this->tmp;
        $site->manifest = (object) array(
            'items' => array((object) array('id' => 'item-1')),
            'metadata' => (object) array('site' => (object) array('name' => $name)),
        );
        return $site;
    }

    private function pathResult(string $siteName, string $normalizedPath): array
    {
        $root = $this->tmp . '/' . $siteName;
        return array(
            'normalizedPath' => $normalizedPath,
            'resolvedPath' => $root . '/' . $normalizedPath,
            'siteRoot' => $root,
            'filesRoot' => $root . '/files',
        );
    }

    private function image(string $path, string $type, int $w = 20, int $h = 10): void
    {
        $img = imagecreatetruecolor($w, $h);
        imagefill($img, 0, 0, imagecolorallocate($img, 30, 120, 200));
        switch ($type) {
            case 'gif': imagegif($img, $path); break;
            case 'webp': imagewebp($img, $path); break;
            case 'jpg': imagejpeg($img, $path); break;
            default: imagepng($img, $path);
        }
        imagedestroy($img);
    }

    // ------------------------------------------------------------------
    // scoped Details manifest payloads
    // ------------------------------------------------------------------

    public function testIsScopedDetailsManifestPayloadGuards(): void
    {
        $this->assertFalse($this->call('isScopedDetailsManifestPayload', 'nope'));
        $this->assertFalse($this->call('isScopedDetailsManifestPayload', array('manifest' => array('site' => array()))));
        $this->assertTrue($this->call('isScopedDetailsManifestPayload', array(
            'manifest' => array('seo' => array('manifest-metadata-site-settings-sw' => true)),
        )));
    }

    public function testEnsureSiteMetadataContainersBuildsMissingObjects(): void
    {
        $site = (object) array('manifest' => new stdClass());
        $this->call('ensureSiteMetadataContainers', $site);
        $this->assertInstanceOf(stdClass::class, $site->manifest->metadata->site->settings);
    }

    public function testApplyScopedDetailsFromManifestBlocks(): void
    {
        $site = $this->site('details', false);
        $this->call('applyScopedDetailsManifestPayload', $site, array(
            'manifest' => array(
                'site' => array('manifest-title' => '<b>New</b> Title', 'manifest-metadata-site-homePageId' => 'item-1'),
                'seo' => array('manifest-metadata-site-settings-sw' => 'true', 'manifest-metadata-site-settings-forceUpgrade' => '0'),
            ),
        ));
        $this->assertSame('New Title', $site->manifest->title);
        $this->assertSame('item-1', $site->manifest->metadata->site->homePageId);
        $this->assertTrue($site->manifest->metadata->site->settings->sw);
        $this->assertFalse($site->manifest->metadata->site->settings->forceUpgrade);
        // the HAXcms version is never written to the public site.json
        $this->assertFalse(property_exists($site->manifest->metadata->site, 'version'));
    }

    public function testApplyScopedDetailsFromFlatParamsClearsInvalidHomePage(): void
    {
        $site = $this->site('details2', false);
        $site->manifest->metadata->site->homePageId = 'old';
        $site->manifest->metadata->site->settings = (object) array('homePageId' => 'old');
        $this->call('applyScopedDetailsManifestPayload', $site, array(
            'title' => 'Flat', 'homePageId' => 'missing-page', 'sw' => 'yes', 'forceUpgrade' => 'on',
        ));
        $this->assertSame('Flat', $site->manifest->title);
        $this->assertObjectNotHasProperty('homePageId', $site->manifest->metadata->site);
        $this->assertObjectNotHasProperty('homePageId', $site->manifest->metadata->site->settings);
        $this->assertTrue($site->manifest->metadata->site->settings->sw);
        $this->assertTrue($site->manifest->metadata->site->settings->forceUpgrade);
    }

    // ------------------------------------------------------------------
    // small normalizers
    // ------------------------------------------------------------------

    public function testHasOnlyAllowedKeysHandlesObjectsAndScalars(): void
    {
        $this->assertTrue($this->call('hasOnlyAllowedKeys', (object) array('a' => 1), array('a')));
        $this->assertFalse($this->call('hasOnlyAllowedKeys', 'str', array('a')));
    }

    public function testAppearanceNormalizersRejectBadInput(): void
    {
        $this->assertFalse($this->call('normalizeAppearanceCssVariable', 5));
        $this->assertFalse($this->call('sanitizeAppearanceRegionIds', 'x'));
        $this->assertFalse($this->call('sanitizeAppearanceRegionIds', array(1)));
        $this->assertFalse($this->call('sanitizeAppearanceRegionIds', array('  ')));
        $this->assertSame(array('a'), $this->call('sanitizeAppearanceRegionIds', array('a', 'a')));
    }

    public function testBulkImportAndSiteFileNormalizers(): void
    {
        $this->assertFalse($this->call('normalizeBulkImportName', null));
        $this->assertFalse($this->call('normalizeBulkImportName', 'a//b.png'));
        $this->assertSame('a/b.png', $this->call('normalizeBulkImportName', 'files/a/b.png'));
        $this->assertFalse($this->call('normalizeSiteFilePath', array()));
        $this->assertFalse($this->call('normalizeSiteFilePath', 'theme//x.css'));
        $this->assertFalse($this->call('normalizeSiteFilePath', 'theme/evil.php'));
    }

    public function testSiteFileContentMimeAcceptable(): void
    {
        // unknown extension has no allowlist -> accepted
        $this->assertTrue($this->call('siteFileContentMimeAcceptable', 'whatever', 'theme/file.unknownext'));
        ob_start();
        $img = imagecreatetruecolor(2, 2);
        imagepng($img);
        imagedestroy($img);
        $png = ob_get_clean();
        $this->assertTrue($this->call('siteFileContentMimeAcceptable', $png, 'theme/a.png'));
        // text/plain is tolerated for text-type extensions
        $this->assertTrue($this->call('siteFileContentMimeAcceptable', 'just some words', 'theme/a.css'));
        // binary image posing as css is refused
        $this->assertFalse($this->call('siteFileContentMimeAcceptable', $png, 'theme/a.css'));
        // htm maps to the html allowlist
        $this->assertTrue($this->call('siteFileContentMimeAcceptable', '<html><body>x</body></html>', 'theme/a.htm'));
    }

    // ------------------------------------------------------------------
    // rename / duplicate path builders
    // ------------------------------------------------------------------

    public function testBuildRenamedFilePathValidationBranches(): void
    {
        $this->site('ren');
        file_put_contents($this->tmp . '/ren/files/doc.txt', 'x');
        file_put_contents($this->tmp . '/ren/files/taken.txt', 'x');
        $pr = $this->pathResult('ren', 'files/doc.txt');

        $msg = function ($name, $result = null) use ($pr) {
            $r = $result !== null ? $result : $this->call('buildRenamedFilePath', $pr, $name);
            return $r['valid'] ? 'OK:' . $r['relativePath'] : $r['status'] . ':' . $r['message'];
        };
        $this->assertSame('400:New file name is required', $msg(null));
        $this->assertSame('400:New file name is required', $msg('  '));
        $this->assertSame('400:File name can only include one extension', $msg('a.b.txt'));
        $this->assertSame('400:New file name must include at least one alphanumeric character', $msg('!!!'));
        $this->assertSame('400:Requested extension is not allowed', $msg('new.exe'));
        $this->assertSame('400:Extension cannot be changed during rename and must remain .txt', $msg('new.pdf'));
        $this->assertSame('400:New file name must be different from current name', $msg('doc'));
        $this->assertSame('400:A file with this name already exists', $msg('taken.txt'));
        $this->assertSame('OK:files/fresh-name.txt', $msg('Fresh Name'));

        $bad = $this->pathResult('ren', 'files/script.exe');
        $this->assertSame('400:Current file extension is not allowed for rename',
            $msg(null, $this->call('buildRenamedFilePath', $bad, 'x')));

        $outside = $pr;
        $outside['filesRoot'] = $this->tmp . '/elsewhere';
        $this->assertSame('403:Renamed file path is outside of allowed files directory',
            $msg(null, $this->call('buildRenamedFilePath', $outside, 'other')));
    }

    public function testGetDuplicateFilePathBranches(): void
    {
        $this->site('dup');
        file_put_contents($this->tmp . '/dup/files/README', 'x');
        file_put_contents($this->tmp . '/dup/files/README-copy', 'x');
        $r = $this->call('getDuplicateFilePath', $this->pathResult('dup', 'files/README'));
        $this->assertTrue($r['valid']);
        $this->assertSame('files/README-copy-2', $r['relativePath']);

        $outside = $this->pathResult('dup', 'files/README');
        $outside['filesRoot'] = $this->tmp . '/elsewhere';
        $r = $this->call('getDuplicateFilePath', $outside);
        $this->assertSame(403, $r['status']);
    }

    // ------------------------------------------------------------------
    // collectSiteFiles / file URL helpers
    // ------------------------------------------------------------------

    public function testCollectSiteFilesListsFilteredSortedRecords(): void
    {
        $site = $this->site('collect');
        $files = $this->tmp . '/collect/files';
        mkdir($files . '/sub', 0777, true);
        mkdir($files . '/haxcms-managed/thumbs', 0777, true);
        file_put_contents($files . '/zeta.txt', 'z');
        file_put_contents($files . '/sub/Alpha.txt', 'aa');
        file_put_contents($files . '/.gitkeep', '');
        file_put_contents($files . '/haxcms-managed/thumbs/t.png', 'x');
        symlink($files . '/zeta.txt', $files . '/link.txt');

        $all = $this->call('collectSiteFiles', $site, $files);
        $this->assertSame(array('files/sub/Alpha.txt', 'files/zeta.txt'), array_column($all, 'path'));
        $alpha = $all[0];
        $this->assertSame('Alpha.txt', $alpha['name']);
        $this->assertSame(2, $alpha['size']);
        $this->assertGreaterThan(0, $alpha['dateCreated']);
        $this->assertStringStartsWith('/files/sub/Alpha.txt?t=', $alpha['fullUrl']);

        $hit = $this->call('collectSiteFiles', $site, $files, ' ALPHA ');
        $this->assertSame(array('files/sub/Alpha.txt'), array_column($hit, 'path'));
        $this->assertSame(array(), $this->call('collectSiteFiles', $site, $this->tmp . '/nope'));
    }

    public function testBuildSiteFileUrlInMultisiteContext(): void
    {
        $site = $this->site('multi');
        $GLOBALS['HAXCMS'] = new class extends OperationsTestHaxcms {
            public $operatingContext = 'multisite';
        };
        $this->assertSame('/_sites/multi/files/a.png', $this->call('buildSiteFileUrl', $site, 'files\\a.png'));
    }

    public function testIsManagedDerivativePath(): void
    {
        $this->assertTrue($this->call('isManagedDerivativePath', 'haxcms-managed'));
        $this->assertTrue($this->call('isManagedDerivativePath', '/haxcms-managed/x.png'));
        $this->assertFalse($this->call('isManagedDerivativePath', 'files/haxcms-managed.png'));
    }

    // ------------------------------------------------------------------
    // resolveSiteFileOperationPath edge cases
    // ------------------------------------------------------------------

    public function testResolveSiteFileOperationPathEdgeCases(): void
    {
        $site = $this->site('resolve');
        file_put_contents($this->tmp . '/resolve/files/real.txt', 'x');
        symlink($this->tmp . '/resolve/files/real.txt', $this->tmp . '/resolve/files/alias.txt');

        $this->assertSame(400, $this->call('resolveSiteFileOperationPath', $site, array())['status']);
        $ok = $this->call('resolveSiteFileOperationPath', $site, './files/real.txt');
        $this->assertTrue($ok['valid']);
        $this->assertSame('files/real.txt', $ok['normalizedPath']);

        $missing = $this->site('ghost', false);
        $this->assertSame(500, $this->call('resolveSiteFileOperationPath', $missing, 'files/x.txt')['status']);
        mkdir($this->tmp . '/nofiles');
        $this->assertSame('Files directory was not found',
            $this->call('resolveSiteFileOperationPath', $this->site('nofiles', false), 'files/x.txt')['message']);
    }

    // ------------------------------------------------------------------
    // image ops: GIF / WebP / JPG branches
    // ------------------------------------------------------------------

    public function testImageOpsAcrossGifWebpJpg(): void
    {
        foreach (array('gif', 'webp', 'jpeg') as $type) {
            $path = $this->tmp . '/img.' . $type;
            $this->image($path, $type === 'jpeg' ? 'jpg' : $type, 40, 20);
            $this->assertTrue($this->call('scaleImageInPlaceFile', $path, 10, 10)['success'], 'scale ' . $type);
            $this->assertSame(array(10, 5), array_slice(getimagesize($path), 0, 2));
            $this->assertTrue($this->call('rotateImageInPlaceFile', $path, 450)['success'], 'rotate ' . $type);
            $this->assertSame(array(5, 10), array_slice(getimagesize($path), 0, 2));
            $this->assertTrue($this->call('compressImageInPlaceFile', $path)['success'], 'compress ' . $type);
            $this->assertTrue($this->call('transformImageInPlaceFile', $path, 'sepia')['success'], 'transform ' . $type);
        }
    }

    public function testRotateBy360KeepsOrientation(): void
    {
        $path = $this->tmp . '/r.png';
        $this->image($path, 'png', 8, 4);
        $this->assertTrue($this->call('rotateImageInPlaceFile', $path, 360)['success']);
        $this->assertSame(array(8, 4), array_slice(getimagesize($path), 0, 2));
    }

    public function testSaveImageResourceByExtensionUnknownIsFalse(): void
    {
        $img = imagecreatetruecolor(2, 2);
        $this->assertFalse($this->call('saveImageResourceByExtension', $img, $this->tmp . '/x.bmp', 'bmp'));
        $this->assertTrue($this->call('saveImageResourceByExtension', $img, $this->tmp . '/x.gif', 'gif'));
        imagedestroy($img);
    }

    // ------------------------------------------------------------------
    // site search helpers
    // ------------------------------------------------------------------

    public function testSiteSearchScalarParsers(): void
    {
        $this->assertTrue($this->call('parseSiteSearchBoolean', 1));
        $this->assertFalse($this->call('parseSiteSearchBoolean', '0'));
        $this->assertTrue($this->call('parseSiteSearchBoolean', ' Yes '));
        $this->assertFalse($this->call('parseSiteSearchBoolean', array()));
        $this->assertSame(25, $this->call('parseSiteSearchLimit', 'abc'));
        $this->assertSame(0, $this->call('parseSiteSearchLimit', -5));
        $this->assertSame(200, $this->call('parseSiteSearchLimit', 999));
        $this->assertSame('', $this->call('getSiteSearchSiteName'));
        $this->ops->params = array('siteName' => 'flat');
        $this->assertSame('flat', $this->call('getSiteSearchSiteName'));
    }

    public function testNormalizeSiteSearchFields(): void
    {
        $default = array('title', 'slug', 'description', 'tags', 'content');
        $this->assertSame(array('id', 'parent'), $this->call('normalizeSiteSearchFields', 'ID, ,parent,id,bogus'));
        $this->assertSame($default, $this->call('normalizeSiteSearchFields', array('title', 'all')));
        $this->assertSame($default, $this->call('normalizeSiteSearchFields', 'bogus'));
    }

    public function testSiteSearchTextReplace(): void
    {
        $this->assertSame(array('content' => '', 'total' => 0), $this->call('siteSearchTextReplace', '', 'a'));
        $this->assertSame(array('content' => 'abc', 'total' => 0), $this->call('siteSearchTextReplace', 'abc', ''));
        $this->assertSame(array('content' => 'xBx', 'total' => 2), $this->call('siteSearchTextReplace', 'aBa', 'a', 'x', true));
        $this->assertSame(array('content' => 'x-x-x', 'total' => 3), $this->call('siteSearchTextReplace', 'Cat-cat-CAT', 'cat', 'x'));
    }

    public function testParseSimpleSiteSearchSelectorVariants(): void
    {
        $this->assertSame('Selector query is required', $this->call('parseSimpleSiteSearchSelector', ' ')['reason']);
        $this->assertSame('Invalid selector syntax', $this->call('parseSimpleSiteSearchSelector', '1abc')['reason']);
        $attrOnly = $this->call('parseSimpleSiteSearchSelector', '[data-x]');
        $this->assertSame('//*[@data-x]', $attrOnly['xpath']);
        $this->assertSame('[data-x]', $attrOnly['selector']);
        $single = $this->call('parseSimpleSiteSearchSelector', "a[href='x']");
        $this->assertSame('a[href="x"]', $single['selector']);
        $this->assertSame("//a[@href='x']", $single['xpath']);
        $bare = $this->call('parseSimpleSiteSearchSelector', 'img[alt=cat]');
        $this->assertSame("//img[@alt='cat']", $bare['xpath']);
        $double = $this->call('parseSimpleSiteSearchSelector', 'p[class="lead"]');
        $this->assertSame("//p[@class='lead']", $double['xpath']);
        // an explicit empty value is kept, not dropped to attribute-exists
        $empty = $this->call('parseSimpleSiteSearchSelector', 'p[class=""]');
        $this->assertSame("//p[@class='']", $empty['xpath']);
    }

    public function testSiteSearchXPathLiteralQuoting(): void
    {
        $this->assertSame('"it\'s"', $this->call('siteSearchXPathLiteral', "it's"));
        $this->assertSame("concat('a', \"'\", 'b\"c')", $this->call('siteSearchXPathLiteral', "a'b\"c"));
    }

    public function testSiteSearchTagsAndFieldValues(): void
    {
        $this->assertSame('a, b', $this->call('siteSearchTagsValue', (object) array('metadata' => (object) array('tags' => array('a', 'b')))));
        $this->assertSame('x', $this->call('siteSearchTagsValue', (object) array('metadata' => (object) array('tags' => 'x'))));
        $this->assertSame('{"k":1}', $this->call('siteSearchTagsValue', (object) array('metadata' => (object) array('tags' => (object) array('k' => 1)))));
        $this->assertSame('', $this->call('getSiteSearchFieldValue', 'nope', new stdClass()));
    }

    public function testSiteSearchSelectorMatchBranches(): void
    {
        $this->assertNull($this->call('siteSearchSelectorMatch', '  ', array('xpath' => '//p')));
        $this->assertNull($this->call('siteSearchSelectorMatch', '<p>x</p>', array()));
        $long = '<p>' . str_repeat('word ', 60) . '</p>';
        $match = $this->call('siteSearchSelectorMatch', $long, array('xpath' => '//p'));
        $this->assertSame(1, $match['count']);
        $this->assertSame(180, strlen($match['snippets'][0]));
        $this->assertStringEndsWith('...', $match['snippets'][0]);
    }

    // ------------------------------------------------------------------
    // app store broker: forwarded query params
    // ------------------------------------------------------------------

    public function testAppStoreForwardedParamsDropReservedAuthNullAndObjects(): void
    {
        $forwarded = $this->call('appStoreSearchBuildForwardedParams', array(
            'provider' => 'youtube', 'site_token' => 't', 'siteName' => 's', 'jwt' => 'j',
            'key' => 'leak', 'access_token' => 'leak', 'api_key' => 'leak', 'client_id' => 'leak',
            'nothing' => null, 'obj' => new stdClass(),
            'q' => 'cats', 'page' => 2, 'filters' => array('a'),
        ));
        $this->assertSame(array('q' => 'cats', 'page' => 2, 'filters' => array('a')), $forwarded);
    }

    // ------------------------------------------------------------------
    // HAXIAM helpers (hardcoded /var/www/sites)
    // ------------------------------------------------------------------

    public function testHaxiamHelpersRejectMissingSourceSite(): void
    {
        $user = 'nouser_' . uniqid();
        $this->assertFalse($this->call('_validateUserOwnsSite', $user, 'site'));
        $this->assertSame(
            array('success' => false, 'error' => 'Source site does not exist or is not owned by you'),
            $this->call('_createUserSiteSymlink', $user, 'other', 'site')
        );
    }
}
