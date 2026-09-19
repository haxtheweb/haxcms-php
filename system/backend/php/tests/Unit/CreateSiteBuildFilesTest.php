<?php
use PHPUnit\Framework\TestCase;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;

require_once __DIR__ . '/OperationsTestHaxcms.php';

/**
 * Unit tests for createSite's importBuildFile and linkImportedPageFiles
 * (#3060, #3043).
 *
 * Mirrors haxcms-nodejs test/unit/createSiteBuildFiles.test.cjs and
 * createSiteLinkImportedFiles.test.cjs. Importers hand createSite remote files
 * as http(s) URLs in build.files; each one is fetched through SsrfGuard into
 * the bulk-import staging root and takes the same bulk-import
 * HAXCMSFile::save as a staged file, so it becomes a file entity in
 * files.json, and linkImportedPageFiles then links pages to it. The
 * GHSA-q862-gcgq-5m6g protections still hold: other schemes and paths outside
 * the staging root are rejected, and private, loopback and metadata addresses
 * are never contacted.
 *
 * The network is a Guzzle MockHandler, and remote URLs use a public IP
 * literal so the SSRF check needs no DNS.
 */
class CreateSiteBuildFilesTest extends TestCase
{
    const REMOTE = 'http://93.184.215.14';

    private $ops;
    private $haxcms;
    private $savedHaxcms;
    private $savedFileSystem;
    private $tmpRoot;
    private $configDir;
    private $stagingRoot;
    private $siteName = 'cbf-site';
    private $siteRoot;
    private $site;
    private $png;

    protected function setUp(): void
    {
        if (isset($GLOBALS['HAXCMS'])) {
            $this->savedHaxcms = $GLOBALS['HAXCMS'];
        }
        if (isset($GLOBALS['fileSystem'])) {
            $this->savedFileSystem = $GLOBALS['fileSystem'];
        }
        // 1x1 PNG (same payload the Node tests use)
        $this->png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');
        $this->tmpRoot = sys_get_temp_dir() . '/haxcms_cbf_' . uniqid();
        $this->configDir = $this->tmpRoot . '/_config';
        $this->stagingRoot = $this->configDir . '/tmp/imports';
        @mkdir($this->stagingRoot, 0777, true);
        // HAXCMSFile::save writes to HAXCMS_ROOT/_sites/<name>/files/
        $this->siteRoot = HAXCMS_ROOT . '/_sites/' . $this->siteName;
        @mkdir($this->siteRoot . '/files', 0777, true);

        $this->haxcms = new OperationsTestHaxcms();
        $this->haxcms->configDirectory = $this->configDir;
        $this->haxcms->sitesDirectory = '_sites';
        $GLOBALS['HAXCMS'] = $this->haxcms;
        $GLOBALS['fileSystem'] = new \Symfony\Component\Filesystem\Filesystem();

        $this->site = new CreateSiteBuildFilesTestSite();
        $this->site->name = $this->siteName;
        $this->site->directory = HAXCMS_ROOT . '/_sites';
        $this->site->siteDirectory = $this->siteRoot;
        $this->site->manifest = new CreateSiteBuildFilesTestManifest($this->siteName);

        $this->ops = new Operations();
        $this->ops->params = array();
        $this->ops->rawParams = array();
    }

    protected function tearDown(): void
    {
        if (isset($this->savedHaxcms)) {
            $GLOBALS['HAXCMS'] = $this->savedHaxcms;
            $this->savedHaxcms = null;
        }
        else {
            unset($GLOBALS['HAXCMS']);
        }
        if (isset($this->savedFileSystem)) {
            $GLOBALS['fileSystem'] = $this->savedFileSystem;
            $this->savedFileSystem = null;
        }
        else {
            unset($GLOBALS['fileSystem']);
        }
        $this->rrmdir($this->tmpRoot);
        $this->rrmdir($this->siteRoot);
        $sites = HAXCMS_ROOT . '/_sites';
        if (is_dir($sites) && count(scandir($sites)) <= 2) {
            @rmdir($sites);
        }
    }

    private function rrmdir($dir)
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path) && !is_link($path)) {
                $this->rrmdir($path);
            }
            else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    private function callOperation($method, array $args)
    {
        // private methods are invocable through reflection since PHP 8.1
        $ref = new ReflectionMethod('Operations', $method);
        return $ref->invokeArgs($this->ops, $args);
    }

    private function importBuildFile($locationName, $source, $client)
    {
        return $this->callOperation('importBuildFile', array($this->site, $locationName, $source, $client));
    }

    private function linkImportedPageFiles()
    {
        return $this->callOperation('linkImportedPageFiles', array($this->site));
    }

    // a client that answers from a queue of responses or exceptions
    private function mockClient(array $queue, &$mock)
    {
        $mock = new MockHandler($queue);
        return new Client(array('handler' => HandlerStack::create($mock)));
    }

    // the file entity files.json holds for a path, or null; read-only, so it
    // only finds records the save itself wrote
    private function entityAt($relativePath)
    {
        $fileStorage = FileStorage::registerOn(new EntityRegistry($this->site));
        $record = $fileStorage->getDataStore()->getByPath($relativePath);
        return ($record !== null && isset($record['uuid'])) ? $fileStorage->load($record['uuid']) : null;
    }

    private function stagedFiles()
    {
        return array_values(array_diff(scandir($this->stagingRoot), array('.', '..')));
    }

    // a page as createSite leaves it: written, with no file references yet
    private function addPage($id, $html, $withMetadata = true)
    {
        $location = 'pages/' . $id . '/index.html';
        @mkdir($this->siteRoot . '/pages/' . $id, 0777, true);
        file_put_contents($this->siteRoot . '/' . $location, $html);
        $page = new stdClass();
        $page->id = $id;
        $page->title = $id;
        $page->location = $location;
        if ($withMetadata) {
            $page->metadata = new stdClass();
            $page->metadata->files = array();
        }
        $this->site->manifest->items[] = $page;
        return $page;
    }

    // a file staged the way an importer stages one
    private function stage($name)
    {
        $staged = $this->stagingRoot . '/' . $name;
        file_put_contents($staged, $this->png);
        return $staged;
    }

    public function testUrlIsFetchedIntoStagingAndSavedAsFileEntity(): void
    {
        $client = $this->mockClient(array(new Response(200, array(), $this->png)), $mock);
        $this->assertTrue($this->importBuildFile('files/chart.png', self::REMOTE . '/img/chart.png', $client));
        $this->assertSame(0, $mock->count(), 'the URL was fetched once');
        $entity = $this->entityAt('files/chart.png');
        $this->assertInstanceOf(FileEntity::class, $entity, 'files.json records the downloaded file');
        $this->assertSame('files/chart.png', $entity->getPath());
        $this->assertTrue($entity->isImage());
        $this->assertFileExists($this->siteRoot . '/files/chart.png');
        $this->assertSame(array(), $this->stagedFiles(), 'nothing is left in staging');
    }

    public function testStagedFileAndUrlInTheSamePayloadAreBothSaved(): void
    {
        $client = $this->mockClient(array(new Response(200, array(), $this->png)), $mock);
        $this->assertTrue($this->importBuildFile('files/local.png', $this->stage('local.png'), $client));
        $this->assertTrue($this->importBuildFile('files/remote.png', self::REMOTE . '/remote.png', $client));
        $this->assertInstanceOf(FileEntity::class, $this->entityAt('files/local.png'), 'the staged file is an entity');
        $this->assertInstanceOf(FileEntity::class, $this->entityAt('files/remote.png'), 'the downloaded file is an entity');
        $this->assertSame(array(), preg_grep('/^haxbi_/', $this->stagedFiles()), 'the download is removed from staging');
    }

    public function testUppercaseSchemeIsStillTreatedAsAUrl(): void
    {
        $client = $this->mockClient(array(new Response(200, array(), $this->png)), $mock);
        $this->assertTrue($this->importBuildFile('files/upper.png', 'HTTP://93.184.215.14/upper.png', $client));
        $this->assertSame(0, $mock->count(), 'the URL was fetched');
        $this->assertInstanceOf(FileEntity::class, $this->entityAt('files/upper.png'));
    }

    public function testKeyWithoutTheFilesPrefixIsSavedUnderFiles(): void
    {
        $client = $this->mockClient(array(new Response(200, array(), $this->png)), $mock);
        $this->assertTrue($this->importBuildFile('bare.png', self::REMOTE . '/bare.png', $client));
        $this->assertInstanceOf(FileEntity::class, $this->entityAt('files/bare.png'));
    }

    public function testUrlWithoutAnExtensionIsSavedUnderTheKeysName(): void
    {
        // Plone serves images from paths like .../@@images/image
        $client = $this->mockClient(array(new Response(200, array(), $this->png)), $mock);
        $this->assertTrue($this->importBuildFile('files/photo.png', self::REMOTE . '/site/photo/@@images/image', $client));
        $entity = $this->entityAt('files/photo.png');
        $this->assertInstanceOf(FileEntity::class, $entity);
        $this->assertSame('files/photo.png', $entity->getPath());
        $this->assertSame('image/png', $entity->getMimetype());
    }

    public function testUrlThatCannotBeFetchedIsSkippedWithoutFailingTheSite(): void
    {
        $client = $this->mockClient(array(
            new Response(404),
            new Response(200, array(), ''),
            new ConnectException('socket hang up', new Request('GET', self::REMOTE . '/down.png')),
        ), $mock);
        foreach (array('missing', 'empty', 'down') as $name) {
            $this->assertTrue($this->importBuildFile('files/' . $name . '.png', self::REMOTE . '/' . $name . '.png', $client), $name);
            $this->assertNull($this->entityAt('files/' . $name . '.png'), $name);
        }
        $this->assertSame(0, $mock->count(), 'each URL was tried');
        $this->assertSame(array(), $this->stagedFiles());
    }

    public function testPrivateLoopbackAndMetadataAddressesAreRefusedWithoutBeingContacted(): void
    {
        $queue = array();
        for ($i = 0; $i < 4; $i++) {
            $queue[] = new Response(200, array(), $this->png);
        }
        $client = $this->mockClient($queue, $mock);
        $targets = array(
            'http://127.0.0.1:8080/secret.png',
            'http://localhost/secret.png',
            'http://169.254.169.254/latest/meta-data/secret.png',
            'http://10.0.0.1/secret.png',
        );
        foreach ($targets as $i => $target) {
            $this->assertTrue($this->importBuildFile('files/secret-' . $i . '.png', $target, $client), $target);
            $this->assertNull($this->entityAt('files/secret-' . $i . '.png'), $target);
        }
        $this->assertSame(4, $mock->count(), 'no request was sent to any of them');
        $this->assertSame(array(), $this->stagedFiles());
    }

    public function testOtherSchemesAndFilesOutsideTheStagingRootAreStillRejected(): void
    {
        $client = $this->mockClient(array(new Response(200, array(), $this->png)), $mock);
        $outside = $this->tmpRoot . '/outside.png';
        file_put_contents($outside, $this->png);
        $sources = array(
            'file:///etc/passwd',
            'gopher://example.org/x.png',
            'ftp://example.org/x.png',
            '/etc/passwd',
            $outside,
            'relative/x.png',
            '',
            // the advisory's proof-of-concept payload shape
            array('tmp_name' => 'http://169.254.169.254/latest/meta-data/iam/security-credentials/'),
        );
        foreach ($sources as $source) {
            $this->assertFalse($this->importBuildFile('files/x.png', $source, $client), json_encode($source));
        }
        $this->assertSame(1, $mock->count(), 'nothing was fetched');
        $this->assertNull($this->entityAt('files/x.png'));
    }

    public function testEntryWithAnUnsafeNameIsRejectedBeforeAnythingIsFetched(): void
    {
        $client = $this->mockClient(array(new Response(200, array(), $this->png)), $mock);
        $this->assertFalse($this->importBuildFile('files/../escape.png', self::REMOTE . '/x.png', $client));
        $this->assertFalse($this->importBuildFile('files/shell.php', self::REMOTE . '/x.png', $client));
        $this->assertSame(1, $mock->count(), 'nothing was fetched');
    }

    public function testDownloadWhoseContentDoesNotMatchItsExtensionIsDropped(): void
    {
        $client = $this->mockClient(array(new Response(200, array(), '<html><body>not an image</body></html>')), $mock);
        $this->assertTrue($this->importBuildFile('files/fake.png', self::REMOTE . '/fake.png', $client));
        $this->assertNull($this->entityAt('files/fake.png'));
        $this->assertFileDoesNotExist($this->siteRoot . '/files/fake.png');
        $this->assertSame(array(), $this->stagedFiles(), 'the rejected download is removed from staging');
    }

    public function testDownloadOverTheSiteUploadLimitIsDropped(): void
    {
        // an uncompressed 700x700 PNG, about 1.4MB
        $img = imagecreatetruecolor(700, 700);
        ob_start();
        imagepng($img, null, 0);
        $big = ob_get_clean();
        $this->assertGreaterThan(1024 * 1024, strlen($big));
        $this->assertLessThan(3 * 1024 * 1024, strlen($big));
        $client = $this->mockClient(array(
            new Response(200, array(), $big),
            new Response(200, array(), $big),
        ), $mock);
        $mediaSettings = $this->configDir . '/settings/media.json';
        @mkdir(dirname($mediaSettings), 0777, true);
        file_put_contents($mediaSettings, json_encode(array('maxUploadSizeMb' => 1)));
        $this->assertTrue($this->importBuildFile('files/big.png', self::REMOTE . '/big.png', $client));
        $this->assertNull($this->entityAt('files/big.png'), 'over the 1MB limit');
        $this->assertSame(array(), $this->stagedFiles(), 'the rejected download is removed from staging');
        // the same file under a higher limit is saved, so the limit is what stopped it
        file_put_contents($mediaSettings, json_encode(array('maxUploadSizeMb' => 3)));
        $this->assertTrue($this->importBuildFile('files/big.png', self::REMOTE . '/big.png', $client));
        $this->assertInstanceOf(FileEntity::class, $this->entityAt('files/big.png'), 'under the 3MB limit');
    }

    public function testPageThatReferencesADownloadedFileIsLinkedToItsEntity(): void
    {
        $client = $this->mockClient(array(new Response(200, array(), $this->png)), $mock);
        $page = $this->addPage('intro', '<p><img src="files/chart.png" alt="Chart"></p>');
        $this->importBuildFile('files/chart.png', self::REMOTE . '/img/chart.png', $client);
        $this->assertSame(1, $this->linkImportedPageFiles());
        $this->assertSame(array($this->entityAt('files/chart.png')->getUuid()), $page->metadata->files);
        $this->assertSame(1, $this->site->manifest->saves, 'the manifest is saved once');
    }

    public function testImageSharedByPagesIsOneEntityReferencedByEachPage(): void
    {
        $client = $this->mockClient(array(), $mock);
        $this->importBuildFile('files/shared.png', $this->stage('shared.png'), $client);
        $first = $this->addPage('first', '<media-image source="files/shared.png" alt=""></media-image>');
        $second = $this->addPage('second', '<p><a href="files/shared.png">shared</a></p><img src="files/shared.png">');
        $this->assertSame(2, $this->linkImportedPageFiles());
        $uuid = $this->entityAt('files/shared.png')->getUuid();
        $this->assertSame(array($uuid), $first->metadata->files);
        $this->assertSame(array($uuid), $second->metadata->files, 'a repeated reference is recorded once');
        $this->assertSame(1, $this->site->manifest->saves);
    }

    public function testPagesWithoutFileReferencesAndTheManifestAreLeftAlone(): void
    {
        $page = $this->addPage('plain', '<p>Just text</p><img src="https://example.org/remote.png">');
        $this->assertSame(0, $this->linkImportedPageFiles());
        $this->assertSame(array(), $page->metadata->files);
        $this->assertSame(0, $this->site->manifest->saves, 'nothing to save');
    }

    public function testReferencesToFilesNeverIngestedAreIgnored(): void
    {
        $client = $this->mockClient(array(), $mock);
        $this->importBuildFile('files/real.png', $this->stage('real.png'), $client);
        $page = $this->addPage('mixed', '<media-image source="files/real.png"></media-image><media-image source="files/missing.png"></media-image>');
        $this->linkImportedPageFiles();
        $this->assertSame(array($this->entityAt('files/real.png')->getUuid()), $page->metadata->files);
    }

    public function testPageThatArrivedWithoutMetadataIsLinked(): void
    {
        $client = $this->mockClient(array(), $mock);
        $this->importBuildFile('files/bare.png', $this->stage('bare.png'), $client);
        $page = $this->addPage('bare', '<media-image source="files/bare.png"></media-image>', false);
        $this->linkImportedPageFiles();
        $this->assertSame(array($this->entityAt('files/bare.png')->getUuid()), $page->metadata->files);
    }
}

/**
 * Manifest double: the site name HAXCMSFile::save reads, and a counted save.
 */
class CreateSiteBuildFilesTestManifest
{
    public $items = array();
    public $metadata;
    public $saves = 0;

    public function __construct($siteName)
    {
        $this->metadata = new stdClass();
        $this->metadata->site = new stdClass();
        $this->metadata->site->name = $siteName;
    }

    public function save($reload = true)
    {
        $this->saves++;
        return true;
    }
}

/**
 * Site double: the directories the file layer reads, and page content read
 * straight from disk.
 */
class CreateSiteBuildFilesTestSite
{
    public $name;
    public $directory;
    public $siteDirectory;
    public $manifest;

    public function getPageContent($page)
    {
        $path = $this->siteDirectory . '/' . $page->location;
        return is_file($path) ? file_get_contents($path) : '';
    }
}
