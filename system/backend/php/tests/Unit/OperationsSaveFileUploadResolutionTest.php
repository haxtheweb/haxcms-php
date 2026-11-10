<?php
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/OperationsTestHaxcms.php';

/**
 * saveFile (lib/operations/saveFile.php) request parsing, the part
 * OperationsSaveFileTest does not reach:
 *
 *   - normalizeUploadCandidate: the PHP multi-file shape
 *     (name[]/tmp_name[]/...) picks the first non-empty tmp_name and its
 *     matching name/type/error/size; empty / non-string / missing tmp_name
 *     entries are rejected
 *   - resolveUploadFromRequestFiles: preferred keys win in order
 *     (file-upload, upload, file, files[]); any other key is the fallback
 *   - node id resolution: node.id, nodeId, node[id], then $_GET nodeId /
 *     node[id]
 *   - site name from site[name]
 *   - a tmp_name that was not delivered by an HTTP upload (an arbitrary
 *     server file) is refused with 500 'Invalid upload source', nothing is
 *     copied into files/ and nothing is committed
 *   - a multi-file upload with an error on the chosen entry is a 400
 *
 * The private helpers are reached through Reflection on Operations (they
 * live in the OperationsRouteSaveFile trait), which keeps these tests off
 * the is_uploaded_file() wall that CLI cannot pass.
 */
class OperationsSaveFileUploadResolutionTest extends TestCase
{
    private $haxcms;
    private $ops;
    private $savedHaxcms;
    private $savedServerSoftware;
    private $savedFiles;
    private $savedGet;
    private $tmpRoot;
    private $fixtureDirName;
    private $siteName = 'upload-resolution-site';

    protected function setUp(): void
    {
        if (isset($GLOBALS['HAXCMS'])) {
            $this->savedHaxcms = $GLOBALS['HAXCMS'];
        }
        if (isset($_SERVER['SERVER_SOFTWARE'])) {
            $this->savedServerSoftware = $_SERVER['SERVER_SOFTWARE'];
        }
        $this->savedFiles = isset($_FILES) ? $_FILES : array();
        $this->savedGet = $_GET;
        $_SERVER['SERVER_SOFTWARE'] = 'phpunit-test';
        $_FILES = array();
        $_GET = array();

        $this->fixtureDirName = 'fixtures_upload_resolution_' . bin2hex(random_bytes(6));
        $this->tmpRoot = HAXCMS_ROOT . '/' . $this->fixtureDirName;
        $siteDir = $this->tmpRoot . '/' . $this->siteName;
        mkdir($siteDir . '/files', 0777, true);
        mkdir($siteDir . '/pages/item-a', 0777, true);
        file_put_contents($siteDir . '/pages/item-a/index.html', '<p>A</p>');
        $manifest = (object) array(
            'id' => 'site-upload-uuid',
            'title' => 'Upload Resolution Site',
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
            'items' => array(
                (object) array(
                    'id' => 'item-a',
                    'indent' => 0,
                    'location' => 'pages/item-a/index.html',
                    'slug' => 'item-a',
                    'order' => 0,
                    'parent' => null,
                    'title' => 'Item A',
                    'description' => '',
                    'metadata' => (object) array(),
                ),
            ),
        );
        file_put_contents($siteDir . '/site.json', json_encode($manifest, JSON_PRETTY_PRINT));

        $this->haxcms = new OperationsTestHaxcms();
        $this->haxcms->configDirectory = $this->tmpRoot . '/_config';
        mkdir($this->haxcms->configDirectory, 0777, true);
        $this->haxcms->sitesDirectory = $this->fixtureDirName;
        $this->haxcms->validRequestToken = true;
        $GLOBALS['HAXCMS'] = $this->haxcms;

        $site = new SaveFileUploadResolutionTestSite();
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
        $_FILES = $this->savedFiles;
        $_GET = $this->savedGet;
        $this->rrmdir($this->tmpRoot);
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir) || is_link($dir)) {
            return;
        }
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_link($path) || !is_dir($path)) {
                @unlink($path);
            } else {
                $this->rrmdir($path);
            }
        }
        @rmdir($dir);
    }

    private function callPrivate(string $method, array $args = array())
    {
        $ref = new ReflectionMethod('Operations', $method);
        $ref->setAccessible(true);
        return $ref->invokeArgs($this->ops, $args);
    }

    private function single(string $name, string $tmpName, int $error = UPLOAD_ERR_OK): array
    {
        return array(
            'name' => $name,
            'type' => 'text/plain',
            'tmp_name' => $tmpName,
            'error' => $error,
            'size' => 5,
        );
    }

    /** A real file on the server that was NOT delivered by an HTTP upload. */
    private function serverFile(string $content = 'server-side data'): string
    {
        $path = $this->tmpRoot . '/not-an-upload.txt';
        file_put_contents($path, $content);
        return $path;
    }

    private function filesDirEntries(): array
    {
        return array_values(array_diff(scandir($this->tmpRoot . '/' . $this->siteName . '/files'), array('.', '..')));
    }

    // =========================================================================
    // normalizeUploadCandidate
    // =========================================================================

    public function testSingleShapeIsReturnedAsIs(): void
    {
        $candidate = $this->single('a.txt', '/tmp/phpA');
        $this->assertSame($candidate, $this->callPrivate('normalizeUploadCandidate', array($candidate)));
    }

    public function testRejectsNonArrayAndMissingOrBlankTmpName(): void
    {
        $this->assertNull($this->callPrivate('normalizeUploadCandidate', array('a.txt')));
        $this->assertNull($this->callPrivate('normalizeUploadCandidate', array(array('name' => 'a.txt'))));
        $this->assertNull($this->callPrivate('normalizeUploadCandidate', array($this->single('a.txt', '   '))));
        $bad = $this->single('a.txt', '');
        $bad['tmp_name'] = 42;
        $this->assertNull($this->callPrivate('normalizeUploadCandidate', array($bad)));
    }

    public function testMultiShapePicksFirstUsableEntryWithItsOwnFields(): void
    {
        $candidate = array(
            'name' => array('skipped.txt', 'chosen.txt', 'later.txt'),
            'type' => array('text/plain', 'image/png', 'text/plain'),
            'tmp_name' => array('', '/tmp/phpChosen', '/tmp/phpLater'),
            'error' => array(UPLOAD_ERR_NO_FILE, UPLOAD_ERR_OK, UPLOAD_ERR_OK),
            'size' => array(0, 1234, 9),
        );
        $this->assertSame(
            array(
                'name' => 'chosen.txt',
                'type' => 'image/png',
                'tmp_name' => '/tmp/phpChosen',
                'error' => UPLOAD_ERR_OK,
                'size' => 1234,
            ),
            $this->callPrivate('normalizeUploadCandidate', array($candidate))
        );
    }

    public function testMultiShapeWithMissingParallelArraysFallsBackToDefaults(): void
    {
        $candidate = array('tmp_name' => array('/tmp/phpOnly'));
        $this->assertSame(
            array('name' => '', 'type' => '', 'tmp_name' => '/tmp/phpOnly', 'error' => 0, 'size' => 0),
            $this->callPrivate('normalizeUploadCandidate', array($candidate))
        );
    }

    public function testMultiShapeWithNoUsableEntryIsNull(): void
    {
        $candidate = array('name' => array('a', 'b'), 'tmp_name' => array('', null));
        $this->assertNull($this->callPrivate('normalizeUploadCandidate', array($candidate)));
    }

    // =========================================================================
    // resolveUploadFromRequestFiles
    // =========================================================================

    public function testPreferredKeysWinInOrder(): void
    {
        $_FILES = array(
            'zzz-other' => $this->single('other.txt', '/tmp/phpOther'),
            'files[]' => $this->single('files.txt', '/tmp/phpFiles'),
            'file' => $this->single('file.txt', '/tmp/phpFile'),
            'upload' => $this->single('upload.txt', '/tmp/phpUpload'),
        );
        $this->assertSame('upload.txt', $this->callPrivate('resolveUploadFromRequestFiles')['name']);
        unset($_FILES['upload']);
        $this->assertSame('file.txt', $this->callPrivate('resolveUploadFromRequestFiles')['name']);
        unset($_FILES['file']);
        $this->assertSame('files.txt', $this->callPrivate('resolveUploadFromRequestFiles')['name']);
        $_FILES['file-upload'] = $this->single('fu.txt', '/tmp/phpFu');
        $this->assertSame('fu.txt', $this->callPrivate('resolveUploadFromRequestFiles')['name']);
    }

    public function testUnusablePreferredKeyFallsThroughToAnyOtherKey(): void
    {
        $_FILES = array(
            'file' => $this->single('empty.txt', ''),
            'custom-field' => $this->single('custom.txt', '/tmp/phpCustom'),
        );
        $this->assertSame('custom.txt', $this->callPrivate('resolveUploadFromRequestFiles')['name']);
    }

    public function testNoFilesIsNull(): void
    {
        $_FILES = array();
        $this->assertNull($this->callPrivate('resolveUploadFromRequestFiles'));
    }

    // =========================================================================
    // saveFile parameter resolution
    // =========================================================================

    /**
     * @return array<string, array{0: array, 1: array}>
     */
    public static function nodeIdSources(): array
    {
        return array(
            'node.id' => array(array('node' => array('id' => 'item-a')), array()),
            'nodeId param' => array(array('nodeId' => 'item-a'), array()),
            'node[id] param' => array(array('node[id]' => 'item-a'), array()),
            'nodeId query' => array(array(), array('nodeId' => 'item-a')),
            'node[id] query' => array(array(), array('node[id]' => 'item-a')),
        );
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('nodeIdSources')]
    public function testNodeIdIsResolvedFromEverySupportedSource(array $params, array $query): void
    {
        $_GET = $query;
        $_FILES = array('file' => $this->single('note.txt', $this->serverFile()));
        $this->ops->params = array_merge(
            array('site_token' => 'accepted-by-double', 'site' => array('name' => $this->siteName)),
            $params
        );
        $this->ops->saveFile();
        $this->assertSame('item-a', $this->ops->params['node']['id']);
    }

    public function testSiteNameFromBracketParam(): void
    {
        $_FILES = array('file' => $this->single('note.txt', $this->serverFile()));
        $this->ops->params = array('site_token' => 'accepted-by-double', 'site[name]' => $this->siteName);
        $result = $this->ops->saveFile();
        $this->assertSame($this->siteName, $this->ops->params['site']['name']);
        // got past the site-name gate (to the upload source check)
        $this->assertNotSame('Missing site name', $result['__failed']['message']);
    }

    // =========================================================================
    // upload source + error gates through the route
    // =========================================================================

    public function testServerFileThatWasNotUploadedIsRefusedAndNotCopied(): void
    {
        $_FILES = array('file' => $this->single('copy-me.txt', $this->serverFile('do not copy')));
        $this->ops->params = array('site_token' => 'accepted-by-double', 'site' => array('name' => $this->siteName));
        $result = $this->ops->saveFile();
        $this->assertSame(500, $result['__failed']['status']);
        $this->assertSame('Invalid upload source', $result['__failed']['message']);
        $this->assertSame(array(), $this->filesDirEntries());
        $this->assertSame(array(), $this->haxcms->loadedSite->gitCommits);
    }

    public function testMultiFileUploadErrorOnChosenEntryIs400(): void
    {
        $_FILES = array('files[]' => array(
            'name' => array('a.txt'),
            'type' => array('text/plain'),
            'tmp_name' => array($this->serverFile()),
            'error' => array(UPLOAD_ERR_PARTIAL),
            'size' => array(5),
        ));
        $this->ops->params = array('site_token' => 'accepted-by-double', 'site' => array('name' => $this->siteName));
        $result = $this->ops->saveFile();
        $this->assertSame(400, $result['__failed']['status']);
        $this->assertSame('Upload failed before processing', $result['__failed']['message']);
    }

    public function testUploadErrorIsCheckedBeforeTheSiteToken(): void
    {
        $this->haxcms->validRequestToken = false;
        $_FILES = array('file' => $this->single('a.txt', $this->serverFile(), UPLOAD_ERR_INI_SIZE));
        $this->ops->params = array('site_token' => 'rejected-by-double', 'site' => array('name' => $this->siteName));
        $result = $this->ops->saveFile();
        $this->assertSame(400, $result['__failed']['status']);
    }
}

/** HAXCMSSite double that records commits and skips feed rebuilds. */
class SaveFileUploadResolutionTestSite extends HAXCMSSite
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
}
