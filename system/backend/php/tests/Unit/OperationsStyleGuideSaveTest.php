<?php
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/OperationsTestHaxcms.php';

/**
 * Characterization tests for Operations::handleStyleGuideSave
 * (lib/Operations.php) exercised through its production entry point:
 * saveNode() with params['node']['id'] === 'x/theme/style-guide'.
 *
 * The site fixture is a real temp site (site.json + pages tree) loaded via
 * the real HAXCMSSite::load, so $site->directory and
 * $site->manifest->metadata->site->name resolve exactly as they do in
 * production. git/twig collaborators are stubbed via a HAXCMSSite test
 * subclass (gitCommit recorded, alternate-format writers no-op).
 *
 * Pinned contract: 5 validation gates (missing body, non-string body,
 * whitespace-only body, non-HTML body, external styleGuide source), both
 * 500 write-failure branches (mkdir refused, file_put_contents refused),
 * and the happy path: content sanitized through
 * SanitizeContent::sanitizeHTMLForStorage, written to
 * <site>/theme/style-guide.html, gitCommit('Style guide updated'), and a
 * 200 response carrying bytes + the canonical relative file path.
 */
class OperationsStyleGuideSaveTest extends TestCase
{
    private $haxcms;
    private $ops;
    private $savedHaxcms;
    private $savedServerSoftware;
    private $tmpRoot;
    private $siteName = 'style-guide-site';

    protected function setUp(): void
    {
        if (isset($GLOBALS['HAXCMS'])) {
            $this->savedHaxcms = $GLOBALS['HAXCMS'];
        }
        if (isset($_SERVER['SERVER_SOFTWARE'])) {
            $this->savedServerSoftware = $_SERVER['SERVER_SOFTWARE'];
        }
        $_SERVER['SERVER_SOFTWARE'] = 'phpunit-test';

        $this->tmpRoot = sys_get_temp_dir() . '/haxcms_ops_styleguide_' . uniqid();
        $this->buildSiteFixture();

        $this->haxcms = new OperationsTestHaxcms();
        $this->haxcms->configDirectory = $this->tmpRoot . '/_config';
        mkdir($this->haxcms->configDirectory, 0777, true);
        $GLOBALS['HAXCMS'] = $this->haxcms;

        $site = new OperationsStyleGuideTestSite();
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
        // restore writability in case a test left the site dir read-only
        $siteDir = $this->tmpRoot . '/' . $this->siteName;
        if (is_dir($siteDir)) {
            @chmod($siteDir, 0777);
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
     * Real temp site fixture. metadata.theme is present but WITHOUT a
     * styleGuide value so the default location is editable; the external
     * source test flips the flag in memory after load.
     */
    private function buildSiteFixture(): void
    {
        $siteDir = $this->tmpRoot . '/' . $this->siteName;
        mkdir($siteDir . '/pages/home', 0777, true);
        file_put_contents($siteDir . '/pages/home/index.html', '<p>Home</p>');

        $manifest = (object)array(
            'id' => 'site-styleguide-uuid',
            'title' => 'Style Guide Site',
            'author' => '',
            'description' => '',
            'license' => 'by-sa',
            'metadata' => (object)array(
                'site' => (object)array(
                    'name' => $this->siteName,
                    'settings' => (object)array('pathauto' => false),
                ),
                'theme' => (object)array(),
                'platform' => (object)array(
                    'features' => (object)array(),
                ),
            ),
            'items' => array(
                (object)array(
                    'id' => 'home',
                    'indent' => 0,
                    'location' => 'pages/home/index.html',
                    'slug' => 'home',
                    'order' => 0,
                    'parent' => null,
                    'title' => 'Home',
                    'description' => '',
                    'metadata' => (object)array('created' => 1000000, 'updated' => 1000000),
                ),
            ),
        );
        file_put_contents($siteDir . '/site.json', json_encode($manifest, JSON_PRETTY_PRINT));
    }

    private function styleGuideParams($body = null, $includeBody = true): array
    {
        $params = array(
            'site_token' => 'good',
            'site' => array('name' => $this->siteName),
            'node' => array('id' => 'x/theme/style-guide'),
        );
        if ($includeBody) {
            $params['node']['body'] = $body;
        }
        return $params;
    }

    private function siteDir(): string
    {
        return $this->tmpRoot . '/' . $this->siteName;
    }

    private function styleGuideFile(): string
    {
        return $this->siteDir() . '/theme/style-guide.html';
    }

    /**
     * The 500 write-failure branches rely on PHP's own diagnostics: mkdir()
     * and file_put_contents() emit E_WARNING before returning false. Capture
     * those expected warnings for the duration of the call so the suite stays
     * warning-clean, while still asserting the diagnostic text as part of the
     * pinned contract. The previous handler is always restored.
     */
    private function saveNodeCapturingWarnings(): array
    {
        $warnings = array();
        set_error_handler(function ($errno, $errstr) use (&$warnings) {
            if ($errno === E_WARNING) {
                $warnings[] = $errstr;
            }
            return true;
        }, E_WARNING);
        try {
            return array($this->ops->saveNode(), $warnings);
        } finally {
            restore_error_handler();
        }
    }

    // =========================================================================
    // site_token gate on the saveNode entry point
    // =========================================================================

    public function testStyleGuideSaveFailsWithInvalidSiteToken(): void
    {
        $this->haxcms->validRequestToken = false;
        $this->ops->params = $this->styleGuideParams('<p>Body</p>');
        $result = $this->ops->saveNode();
        $this->assertSame(403, $result['__failed']['status']);
        $this->assertSame('invalid site token', $result['__failed']['message']);
    }

    // =========================================================================
    // Validation gates
    // =========================================================================

    public function testStyleGuideSaveWithoutBodyReturns400(): void
    {
        $this->haxcms->validRequestToken = true;
        $this->ops->params = $this->styleGuideParams(null, false);
        $result = $this->ops->saveNode();
        $this->assertSame(400, $result['__failed']['status']);
        $this->assertSame('Content parameter is required', $result['__failed']['message']);
    }

    public function testStyleGuideSaveWithNonStringBodyReturns400(): void
    {
        $this->haxcms->validRequestToken = true;
        $this->ops->params = $this->styleGuideParams(array('not', 'a', 'string'));
        $result = $this->ops->saveNode();
        $this->assertSame(400, $result['__failed']['status']);
        $this->assertSame('Content must be a string', $result['__failed']['message']);
    }

    public function testStyleGuideSaveWithWhitespaceOnlyBodyReturns400(): void
    {
        $this->haxcms->validRequestToken = true;
        $this->ops->params = $this->styleGuideParams("   \n\t  ");
        $result = $this->ops->saveNode();
        $this->assertSame(400, $result['__failed']['status']);
        $this->assertSame('Content cannot be empty', $result['__failed']['message']);
    }

    public function testStyleGuideSaveWithNonHtmlBodyReturns400(): void
    {
        $this->haxcms->validRequestToken = true;
        $this->ops->params = $this->styleGuideParams('just plain text, no markup');
        $result = $this->ops->saveNode();
        $this->assertSame(400, $result['__failed']['status']);
        $this->assertSame('Content must be valid HTML', $result['__failed']['message']);
        $this->assertFileDoesNotExist($this->styleGuideFile());
    }

    // =========================================================================
    // Write-failure branches
    // =========================================================================

    public function testStyleGuideSaveReturns500WhenThemeDirectoryCannotBeCreated(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('mkdir refusal cannot be simulated as root');
        }
        $this->haxcms->validRequestToken = true;
        // site dir exists but is read-only -> mkdir(<siteDir>/theme) refused
        chmod($this->siteDir(), 0555);
        $this->ops->params = $this->styleGuideParams('<p>Body</p>');
        [$result, $warnings] = $this->saveNodeCapturingWarnings();
        $this->assertSame(500, $result['__failed']['status']);
        $this->assertSame('Failed to create theme directory', $result['__failed']['message']);
        $this->assertFileDoesNotExist($this->styleGuideFile());
        // the 500 is driven by PHP's mkdir() refusal diagnostic
        $this->assertNotEmpty($warnings);
        $this->assertStringContainsString('Permission denied', implode("\n", $warnings));
    }

    public function testStyleGuideSaveReturns500WhenWriteFails(): void
    {
        $this->haxcms->validRequestToken = true;
        // A DIRECTORY named style-guide.html inside theme/ makes
        // file_put_contents fail deterministically without permission games.
        mkdir($this->siteDir() . '/theme/style-guide.html', 0777, true);
        $this->ops->params = $this->styleGuideParams('<p>Body</p>');
        [$result, $warnings] = $this->saveNodeCapturingWarnings();
        $this->assertSame(500, $result['__failed']['status']);
        $this->assertSame('Failed to write style guide file', $result['__failed']['message']);
        // file_put_contents() refused because the target path is a directory
        $this->assertNotEmpty($warnings);
        $this->assertStringContainsString('Failed to open stream', implode("\n", $warnings));
    }

    // =========================================================================
    // External styleGuide source guard (checked AFTER mkdir, per source order)
    // =========================================================================

    public function testStyleGuideSaveBlockedForExternalStyleGuideSource(): void
    {
        $this->haxcms->validRequestToken = true;
        $site = $this->haxcms->loadedSite;
        $site->manifest->metadata->theme->styleGuide = 'https://cdn.example.com/style-guide.html';
        $this->ops->params = $this->styleGuideParams('<p>Body</p>');
        $result = $this->ops->saveNode();
        $this->assertSame(403, $result['__failed']['status']);
        $this->assertSame(
            'Style guide is configured to use external source. Cannot edit through HAXcms.',
            $result['__failed']['message']
        );
        // the theme directory was already created by the preceding mkdir step
        $this->assertDirectoryExists($this->siteDir() . '/theme');
        // but no style guide file was written and no commit was made
        $this->assertFileDoesNotExist($this->styleGuideFile());
        $this->assertSame(array(), $site->gitCommits);
    }

    // =========================================================================
    // Happy path
    // =========================================================================

    public function testStyleGuideSaveWritesSanitizedFileRecordsCommitAndReturns200(): void
    {
        $this->haxcms->validRequestToken = true;
        $body = '<p>Style guide <script>alert(1)</script>body</p>';
        $this->ops->params = $this->styleGuideParams($body);
        $result = $this->ops->saveNode();

        $this->assertSame(200, $result['status']);
        $this->assertSame('Style guide saved successfully', $result['message']);
        $this->assertSame('theme/style-guide.html', $result['data']['file']);

        // file written: sanitized (script tag removed entirely), leading/
        // trailing whitespace trimmed before sanitizing
        $this->assertFileExists($this->styleGuideFile());
        $written = file_get_contents($this->styleGuideFile());
        $this->assertSame('<p>Style guide body</p>', $written);
        $this->assertStringNotContainsString('<script', $written);
        // response byte count matches what actually landed on disk
        $this->assertSame(strlen($written), $result['data']['bytes']);

        // git collaborator recorded with the canonical message
        $site = $this->haxcms->loadedSite;
        $this->assertSame(array('Style guide updated'), $site->gitCommits);

        // a second save overwrites the file (idempotent default location)
        $this->ops->params = $this->styleGuideParams('<h1>Updated guide</h1>');
        $result2 = $this->ops->saveNode();
        $this->assertSame(200, $result2['status']);
        $this->assertSame('<h1>Updated guide</h1>', file_get_contents($this->styleGuideFile()));
        $this->assertSame(array('Style guide updated', 'Style guide updated'), $site->gitCommits);
    }
}

/**
 * HAXCMSSite test subclass: real manifest load/save + real directory
 * resolution, but no git binary or twig template tree.
 */
class OperationsStyleGuideTestSite extends HAXCMSSite
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
