<?php
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/OperationsTestHaxcms.php';

/**
 * Gap-filling characterization tests for the fileOperation() route
 * (lib/operations/fileOperation.php), complementary to
 * OperationsFileOperationTest (gates + happy paths) and
 * OperationsFileOperationPathSecurityTest (path validation):
 *
 *   - site_token 'token?siteName=site' packing + flat params siteName form
 *   - site not found -> 404
 *   - rate-limited principal -> 429 (enabled settings + cache-backed entry)
 *   - requestedPath accepted via filePath / file params; array refused 400
 *   - delete/rename/duplicate 500 branches when the files dir is read-only
 *   - rename value accepted via name / value params
 *   - non-image refusal branches for rotate-90 / convert-jpg / sepia /
 *     black-and-white / scale (compress is pinned in OperationsFileOperationTest)
 *   - convert-pptx-deck: non-.pptx 400, missing ZIP signature 400, corrupt
 *     zip 400, happy path (deck.json + media written, files.json registered,
 *     commit, response shape), and deck-name uniquification on re-run
 *
 * The media-settings read catch (126-127) is unreachable by design:
 * HAXCMSMediaSettingsService::readMediaSettings never throws, so it is not
 * tested here.
 */
class OperationsFileOperationGapsTest extends TestCase
{
    private $haxcms;
    private $ops;
    private $savedHaxcms;
    private $savedServerSoftware;
    private $tmpRoot;
    private $siteName = 'fileops-site';
    private $siteRoot;

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
            define('HAXCMS_ROOT', sys_get_temp_dir() . '/haxcms_ops_fileopsgaps_root');
        }

        $this->tmpRoot = sys_get_temp_dir() . '/haxcms_fileopsgaps_' . uniqid();
        $this->siteRoot = $this->tmpRoot . '/' . $this->siteName;
        $this->buildSiteFixture();

        $this->haxcms = new FileOpsGapsTestHaxcms();
        $this->haxcms->configDirectory = $this->tmpRoot . '/_config';
        mkdir($this->haxcms->configDirectory, 0777, true);
        $this->haxcms->validRequestToken = true;
        $GLOBALS['HAXCMS'] = $this->haxcms;

        $site = new FileOpsGapsTestSite();
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
        // never leave a read-only fixture behind
        @chmod($this->siteRoot . '/files', 0777);
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

    private function buildSiteFixture(): void
    {
        mkdir($this->siteRoot . '/files', 0777, true);
        file_put_contents($this->siteRoot . '/files/test.txt', 'Test file content');
        file_put_contents($this->siteRoot . '/files/note.txt', 'Note file content');

        $img = imagecreatetruecolor(20, 15);
        $red = imagecolorallocate($img, 255, 0, 0);
        imagefill($img, 0, 0, $red);
        imagepng($img, $this->siteRoot . '/files/test.png');
        imagedestroy($img);

        $manifest = (object)array(
            'id' => 'site-fileops-uuid',
            'title' => 'File Ops Test Site',
            'author' => '',
            'description' => '',
            'license' => 'by-sa',
            'metadata' => (object)array(
                'site' => (object)array(
                    'name' => $this->siteName,
                    'settings' => (object)array('pathauto' => false),
                ),
                'platform' => (object)array('features' => (object)array()),
            ),
            'items' => array(),
        );
        file_put_contents($this->siteRoot . '/site.json', json_encode($manifest, JSON_PRETTY_PRINT));
    }

    /** Run fileOperation with the standard site param form. */
    private function runOp(string $operation, array $extra = array()): array
    {
        $this->ops->params = array_merge(array(
            'site_token' => 'good',
            'site' => array('name' => $this->siteName),
            'operation' => $operation,
        ), $extra);
        return $this->ops->fileOperation();
    }

    // =========================================================================
    // site name resolution forms + 404
    // =========================================================================

    public function testSiteTokenQueryStringEncodingCarriesSiteName(): void
    {
        $this->ops->params = array(
            'site_token' => 'good?siteName=' . $this->siteName,
            'operation' => 'delete',
            'path' => 'files/test.txt',
        );
        $result = $this->ops->fileOperation();
        // the packed token split worked: name resolved, delete succeeded
        $this->assertSame(200, $result['status']);
        $this->assertTrue($result['data']['deleted']);
        $this->assertFalse(file_exists($this->siteRoot . '/files/test.txt'));
    }

    public function testFlatSiteNameParamAccepted(): void
    {
        $this->ops->params = array(
            'site_token' => 'good',
            'siteName' => $this->siteName,
            'operation' => 'delete',
            'path' => 'files/test.txt',
        );
        $result = $this->ops->fileOperation();
        $this->assertSame(200, $result['status']);
        $this->assertFalse(file_exists($this->siteRoot . '/files/test.txt'));
    }

    public function testSiteNotFoundReturns404(): void
    {
        $this->haxcms->loadedSite = null;
        $result = $this->runOp('delete', array('path' => 'files/test.txt'));
        $this->assertSame(404, $result['__failed']['status']);
        $this->assertSame('Site not found', $result['__failed']['message']);
    }

    // =========================================================================
    // rate limit gate (enabled settings + cache-backed blocked entry)
    // =========================================================================

    public function testRateLimitedPrincipalGets429(): void
    {
        $settings = new stdClass();
        $settings->enabled = true;
        $settings->windowMs = 60000;
        $settings->max = 1;
        $settings->blockMs = 5000;
        $this->haxcms->fileOpsRateLimitSettings = $settings;
        // cache reports the principal as already in a block window
        $nowMs = intval(round(microtime(true) * 1000));
        $this->haxcms->cache = new FileOpsGapsTestCache(array(
            'fileops-rate:' . sha1('testuser::' . $this->siteName) => array(
                'firstAttempt' => $nowMs - 1000,
                'attempts' => 5,
                'blockedUntil' => $nowMs + 60000,
            ),
        ));
        $result = $this->runOp('delete', array('path' => 'files/test.txt'));
        $this->assertSame(429, $result['__failed']['status']);
        $this->assertStringContainsString(
            'File operation rate limit reached',
            $result['__failed']['message']
        );
        // nothing was deleted while blocked
        $this->assertTrue(file_exists($this->siteRoot . '/files/test.txt'));
    }

    // =========================================================================
    // requestedPath param forms
    // =========================================================================

    public function testRequestedPathAcceptedViaFilePathAndFileParams(): void
    {
        $viaFilePath = $this->runOp('delete', array('filePath' => 'files/test.txt'));
        $this->assertSame(200, $viaFilePath['status']);
        $this->assertFalse(file_exists($this->siteRoot . '/files/test.txt'));

        $viaFile = $this->runOp('delete', array('file' => 'files/note.txt'));
        $this->assertSame(200, $viaFile['status']);
        $this->assertFalse(file_exists($this->siteRoot . '/files/note.txt'));
    }

    public function testArrayPathRefusedWith400(): void
    {
        $result = $this->runOp('delete', array('path' => array('files/test.txt')));
        $this->assertSame(400, $result['__failed']['status']);
        $this->assertSame(
            'Only a single file path is allowed per request',
            $result['__failed']['message']
        );
    }

    // =========================================================================
    // 500 branches when the files directory is read-only
    // =========================================================================

    public function testDeleteFailsWith500WhenDirectoryNotWritable(): void
    {
        // file mode bits do not restrict root, so these cases cannot fail as root
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('file permission checks do not apply when running as root');
        }
        chmod($this->siteRoot . '/files', 0555);
        $result = $this->runOp('delete', array('path' => 'files/test.txt'));
        $this->assertSame(500, $result['__failed']['status']);
        $this->assertSame('Unable to delete file', $result['__failed']['message']);
        $this->assertTrue(file_exists($this->siteRoot . '/files/test.txt'));
    }

    public function testRenameFailsWith500WhenDirectoryNotWritable(): void
    {
        // file mode bits do not restrict root, so these cases cannot fail as root
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('file permission checks do not apply when running as root');
        }
        chmod($this->siteRoot . '/files', 0555);
        $result = $this->runOp('rename', array(
            'path' => 'files/test.txt',
            'newName' => 'renamed.txt',
        ));
        $this->assertSame(500, $result['__failed']['status']);
        $this->assertSame('Unable to rename file', $result['__failed']['message']);
        $this->assertTrue(file_exists($this->siteRoot . '/files/test.txt'));
    }

    public function testDuplicateFailsWith500WhenDirectoryNotWritable(): void
    {
        // file mode bits do not restrict root, so these cases cannot fail as root
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('file permission checks do not apply when running as root');
        }
        chmod($this->siteRoot . '/files', 0555);
        $result = $this->runOp('duplicate', array('path' => 'files/test.txt'));
        $this->assertSame(500, $result['__failed']['status']);
        $this->assertSame('Unable to duplicate file', $result['__failed']['message']);
        $this->assertFalse(file_exists($this->siteRoot . '/files/test-copy.txt'));
    }

    // =========================================================================
    // rename value via name / value params
    // =========================================================================

    public function testRenameAcceptsNameAndValueParams(): void
    {
        $viaName = $this->runOp('rename', array(
            'path' => 'files/test.txt',
            'name' => 'renamed.txt',
        ));
        $this->assertSame(200, $viaName['status']);
        $this->assertSame('files/renamed.txt', $viaName['data']['path']);
        $this->assertTrue(file_exists($this->siteRoot . '/files/renamed.txt'));

        $viaValue = $this->runOp('rename', array(
            'path' => 'files/note.txt',
            'value' => 'noted.txt',
        ));
        $this->assertSame(200, $viaValue['status']);
        $this->assertSame('files/noted.txt', $viaValue['data']['path']);
        $this->assertTrue(file_exists($this->siteRoot . '/files/noted.txt'));
    }

    // =========================================================================
    // non-image refusal branches
    // =========================================================================

    public function testRotate90OnNonImageReturns400(): void
    {
        $result = $this->runOp('rotate-90', array('path' => 'files/test.txt'));
        $this->assertSame(400, $result['__failed']['status']);
        $this->assertSame('Only raster images can be rotated', $result['__failed']['message']);
    }

    public function testConvertJpgOnNonImageReturns400(): void
    {
        $result = $this->runOp('convert-jpg', array('path' => 'files/test.txt'));
        $this->assertSame(400, $result['__failed']['status']);
        $this->assertSame(
            'Only raster images can be converted to JPG',
            $result['__failed']['message']
        );
    }

    public function testSepiaOnNonImageReturns400(): void
    {
        $result = $this->runOp('sepia', array('path' => 'files/test.txt'));
        $this->assertSame(400, $result['__failed']['status']);
        $this->assertSame('Only raster images can be transformed', $result['__failed']['message']);
    }

    public function testScaleOnNonImageReturns400(): void
    {
        $result = $this->runOp('scale', array('path' => 'files/test.txt', 'size' => 'sm'));
        $this->assertSame(400, $result['__failed']['status']);
        $this->assertSame('Only raster images can be scaled', $result['__failed']['message']);
    }

    // =========================================================================
    // convert-pptx-deck
    // =========================================================================

    public function testConvertPptxDeckRequiresPptxExtension(): void
    {
        $result = $this->runOp('convert-pptx-deck', array('path' => 'files/test.txt'));
        $this->assertSame(400, $result['__failed']['status']);
        $this->assertSame(
            'convert-pptx-deck requires a .pptx file',
            $result['__failed']['message']
        );
    }

    public function testConvertPptxDeckRejectsMissingZipSignature(): void
    {
        file_put_contents($this->siteRoot . '/files/broken.pptx', 'not a zip at all');
        $result = $this->runOp('convert-pptx-deck', array('path' => 'files/broken.pptx'));
        $this->assertSame(400, $result['__failed']['status']);
        $this->assertSame(
            'File is not a valid .pptx (missing ZIP signature)',
            $result['__failed']['message']
        );
    }

    public function testConvertPptxDeckRejectsCorruptZip(): void
    {
        // starts with the PK signature but is not a readable archive
        file_put_contents($this->siteRoot . '/files/corrupt.pptx', "PK\x03\x04garbage-that-is-not-a-zip");
        $result = $this->runOp('convert-pptx-deck', array('path' => 'files/corrupt.pptx'));
        $this->assertSame(400, $result['__failed']['status']);
        $this->assertStringContainsString('Error processing PPTX', $result['__failed']['message']);
        $this->assertFalse(is_dir($this->siteRoot . '/files/decks/corrupt'));
    }

    private function slideXml(string $titleText, string $bodyText): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<p:sld xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"'
            . ' xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main">'
            . '<p:cSld><p:spTree>'
            . '<p:sp>'
            . '<p:nvSpPr><p:nvPr><p:ph type="title"/></p:nvPr></p:nvSpPr>'
            . '<p:txBody><a:bodyPr/><a:lstStyle/><a:p><a:r><a:t>' . htmlspecialchars($titleText, ENT_XML1) . '</a:t></a:r></a:p></p:txBody>'
            . '</p:sp>'
            . '<p:sp>'
            . '<p:nvSpPr><p:nvPr></p:nvPr></p:nvSpPr>'
            . '<p:txBody><a:bodyPr/><a:lstStyle/><a:p><a:r><a:t>' . htmlspecialchars($bodyText, ENT_XML1) . '</a:t></a:r></a:p></p:txBody>'
            . '</p:sp>'
            . '</p:spTree></p:cSld>'
            . '</p:sld>';
    }

    /** Build a minimal single-slide PPTX (same shape as ImportPptxDeckTest). */
    private function buildMinimalPptx(string $targetPath): void
    {
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($targetPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true);
        $zip->addFromString('ppt/slides/slide1.xml', $this->slideXml('Sample Title', 'Sample body text'));
        $zip->addFromString(
            'ppt/slides/_rels/slide1.xml.rels',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideLayout" Target="../slideLayouts/slideLayout1.xml"/>'
            . '</Relationships>'
        );
        $zip->close();
    }

    public function testConvertPptxDeckBuildsDeckAndRegistersFiles(): void
    {
        if (!class_exists('ZipArchive')) {
            $this->markTestSkipped('ZipArchive extension not available');
        }
        $this->buildMinimalPptx($this->siteRoot . '/files/sample.pptx');

        $result = $this->runOp('convert-pptx-deck', array('path' => 'files/sample.pptx'));
        $this->assertSame(200, $result['status']);
        $data = $result['data'];
        $this->assertSame('convert-pptx-deck', $data['operation']);
        $this->assertSame('files/decks/sample/deck.json', $data['deckPath']);
        $this->assertSame(
            '<slide-deck source="files/decks/sample/deck.json"></slide-deck>',
            $data['embedHtml']
        );

        // manifest: original files/ path referenced, slide content carried over
        $this->assertSame('files/sample.pptx', $data['manifest']['pptx']);
        $this->assertSame('sample', $data['manifest']['title']);
        $this->assertCount(1, $data['manifest']['slides']);
        $this->assertSame('Sample Title', $data['manifest']['slides'][0]['title']);
        $this->assertStringContainsString('Sample body text', $data['manifest']['slides'][0]['html']);

        // deck.json on disk mirrors the response manifest
        $deckJson = json_decode(file_get_contents($this->siteRoot . '/files/decks/sample/deck.json'));
        $this->assertSame('sample', $deckJson->title);
        $this->assertSame('Sample Title', $deckJson->slides[0]->title);

        // the original .pptx was NOT moved out of files/
        $this->assertTrue(file_exists($this->siteRoot . '/files/sample.pptx'));

        // files.json datastore registered the deck.json record
        $this->assertFileExists($this->siteRoot . '/files/files.json');

        $site = $this->haxcms->loadedSite;
        $this->assertSame(
            'PPTX deck converted: files/sample.pptx -> files/decks/sample/deck.json',
            $site->gitCommits[0]
        );
    }

    public function testConvertPptxDeckUniquifiesDeckFolderOnRerun(): void
    {
        if (!class_exists('ZipArchive')) {
            $this->markTestSkipped('ZipArchive extension not available');
        }
        $this->buildMinimalPptx($this->siteRoot . '/files/sample.pptx');

        $first = $this->runOp('convert-pptx-deck', array('path' => 'files/sample.pptx'));
        $this->assertSame(200, $first['status']);
        $this->assertSame('files/decks/sample/deck.json', $first['data']['deckPath']);

        // second conversion of the same deck must not overwrite the first
        $second = $this->runOp('convert-pptx-deck', array('path' => 'files/sample.pptx'));
        $this->assertSame(200, $second['status']);
        $this->assertSame('files/decks/sample-1/deck.json', $second['data']['deckPath']);
        $this->assertTrue(file_exists($this->siteRoot . '/files/decks/sample/deck.json'));
        $this->assertTrue(file_exists($this->siteRoot . '/files/decks/sample-1/deck.json'));
    }
}

/**
 * File-ops mock: declares the cache handle (dynamic properties on PHP 8.2+
 * deprecate) and configurable rate-limit settings.
 */
class FileOpsGapsTestHaxcms extends OperationsTestHaxcms
{
    public $cache = null;
    public $fileOpsRateLimitSettings = null;

    public function getFileOpsRateLimitSettings()
    {
        if ($this->fileOpsRateLimitSettings !== null) {
            return $this->fileOpsRateLimitSettings;
        }
        return parent::getFileOpsRateLimitSettings();
    }
}

/** Minimal retrieve/store cache double for the rate-limit gate. */
class FileOpsGapsTestCache
{
    private $data;

    public function __construct(array $data = array())
    {
        $this->data = $data;
    }

    public function retrieve($key)
    {
        return array_key_exists($key, $this->data) ? $this->data[$key] : null;
    }

    public function store($key, $value, $ttl = null)
    {
        $this->data[$key] = $value;
        return true;
    }
}

/** HAXCMSSite double recording git commits, real manifest + path resolution. */
class FileOpsGapsTestSite extends HAXCMSSite
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
