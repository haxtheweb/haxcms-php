<?php
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/OperationsTestHaxcms.php';

/**
 * Characterization tests for the Operations authorization-gate seam.
 *
 * Beyond the user_token gate (see OperationsTokenGateTest), route methods apply
 * two more gates before any filesystem mutation:
 *   - IDOR: $GLOBALS['HAXCMS']->userCanAccessSite($siteName) -> 403 'Access
 *     denied to site' (cloneSite, archiveSite, downloadSite, ...). This fires
 *     BEFORE loadSite, so no site fixture is needed.
 *   - site_token: validateRequestToken($site_token, user:sitename) -> 403
 *     'invalid site token' (saveManifest, saveNode, saveOutline, ...).
 *   - platform: $this->platformAllows($site, $capability) -> 403 when a
 *     platform.features flag is false (saveManifest checks 'siteManifest').
 *
 * These slices pin the gate decisions via the shared OperationsTestHaxcms
 * collaborator mock. Expected return shape is the route failure contract:
 * ['__failed'=>['status'=>403, 'message'=>'...']]. No FS mutation is reached.
 */
class OperationsAuthorizationGateTest extends TestCase
{
    private $haxcms;
    private $ops;
    private $savedHaxcms;
    private $tmpConfigDir;

    protected function setUp(): void
    {
        if (isset($GLOBALS['HAXCMS'])) {
            $this->savedHaxcms = $GLOBALS['HAXCMS'];
        }
        $this->tmpConfigDir = sys_get_temp_dir() . '/haxcms_ops_gate_' . uniqid();
        mkdir($this->tmpConfigDir, 0777, true);
        $this->haxcms = new OperationsTestHaxcms();
        $this->haxcms->configDirectory = $this->tmpConfigDir;
        $GLOBALS['HAXCMS'] = $this->haxcms;
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
        $this->rrmdir($this->tmpConfigDir);
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
            if (is_dir($path)) {
                $this->rrmdir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    private function makeFakeSite(): stdClass
    {
        $site = new stdClass();
        $site->manifest = new stdClass();
        $site->manifest->metadata = new stdClass();
        $site->manifest->metadata->site = new stdClass();
        $site->manifest->metadata->site->name = 'my-site';
        $site->manifest->metadata->platform = new stdClass();
        $site->manifest->metadata->platform->features = new stdClass();
        return $site;
    }

    // --- cloneSite: user_token gate + IDOR ---

    public function testCloneSiteFailsWithInvalidToken(): void
    {
        $this->haxcms->validRequestToken = false;
        $this->ops->params = array('user_token' => 'bad', 'site' => array('name' => 'my-site'));
        $result = $this->ops->cloneSite();
        $this->assertSame(403, $result['__failed']['status']);
        $this->assertSame('invalid request token', $result['__failed']['message']);
    }

    public function testCloneSiteFailsWithMissingSiteName(): void
    {
        $this->haxcms->validRequestToken = true;
        $this->ops->params = array('user_token' => 'good');
        $result = $this->ops->cloneSite();
        $this->assertSame(403, $result['__failed']['status']);
        $this->assertSame('Access denied to site', $result['__failed']['message']);
    }

    public function testCloneSiteFailsWhenUserCannotAccessSite(): void
    {
        $this->haxcms->validRequestToken = true;
        $this->haxcms->canAccessSite = false;
        $this->ops->params = array('user_token' => 'good', 'site' => array('name' => 'my-site'));
        $result = $this->ops->cloneSite();
        $this->assertSame(403, $result['__failed']['status']);
        $this->assertSame('Access denied to site', $result['__failed']['message']);
    }

    // --- archiveSite: user_token gate + IDOR ---

    public function testArchiveSiteFailsWithInvalidToken(): void
    {
        $this->haxcms->validRequestToken = false;
        $this->ops->params = array('user_token' => 'bad', 'site' => array('name' => 'my-site'));
        $result = $this->ops->archiveSite();
        $this->assertSame(403, $result['__failed']['status']);
        $this->assertSame('invalid request token', $result['__failed']['message']);
    }

    public function testArchiveSiteFailsWhenUserCannotAccessSite(): void
    {
        $this->haxcms->validRequestToken = true;
        $this->haxcms->canAccessSite = false;
        $this->ops->params = array('user_token' => 'good', 'site' => array('name' => 'my-site'));
        $result = $this->ops->archiveSite();
        $this->assertSame(403, $result['__failed']['status']);
        $this->assertSame('Access denied to site', $result['__failed']['message']);
    }

    // --- saveManifest: site_token gate + platform gate ---

    public function testSaveManifestFailsWithInvalidSiteToken(): void
    {
        $this->haxcms->validRequestToken = false;
        $this->ops->params = array('site_token' => 'bad', 'site' => array('name' => 'my-site'));
        $result = $this->ops->saveManifest();
        $this->assertSame(403, $result['__failed']['status']);
        $this->assertSame('invalid site token', $result['__failed']['message']);
    }

    public function testSaveManifestFailsWhenManifestEditingDisabled(): void
    {
        $this->haxcms->validRequestToken = true;
        $site = $this->makeFakeSite();
        $site->manifest->metadata->platform->features->siteManifest = false;
        $this->haxcms->loadedSite = $site;
        $this->ops->params = array('site_token' => 'good', 'site' => array('name' => 'my-site'));
        $result = $this->ops->saveManifest();
        $this->assertSame(403, $result['__failed']['status']);
        $this->assertSame('Manifest editing is disabled for this site', $result['__failed']['message']);
    }

    public function testSaveManifestFailsWithInvalidFormToken(): void
    {
        // valid site_token (call 1 -> true) + platform allows + non-scoped
        // payload (haxcms_form_id present so the form-token check runs) but the
        // form token is invalid (call 2 -> false) -> 403 'invalid request token'.
        $this->haxcms->requestTokenSequence = array(true, false);
        $site = $this->makeFakeSite();
        $this->haxcms->loadedSite = $site;
        $this->ops->params = array(
            'site_token' => 'good',
            'site' => array('name' => 'my-site'),
            'haxcms_form_id' => 'siteSettings',
            'haxcms_form_token' => 'bad-form',
        );
        $result = $this->ops->saveManifest();
        $this->assertSame(403, $result['__failed']['status']);
        $this->assertSame('invalid request token', $result['__failed']['message']);
    }

    // --- saveNode: site_token gate ---

    public function testSaveNodeFailsWithInvalidSiteToken(): void
    {
        // FINDING (status-code inconsistency, NOT fixed -- flagged for review):
        // saveNode returns 500 'failed to write' for an invalid/missing
        // site_token, NOT 403. saveManifest returns 403 'invalid site token'
        // for the same condition, and the user_token routes return 403
        // 'invalid request token'. saveNode's invalid-token branch
        // (lib/operations/saveNode.php ~line 402) reports an auth failure as
        // a server error, which would misdiagnose as a write fault rather than
        // an auth rejection. The request is still rejected (no unauthorized
        // mutation), so this is a contract/UX bug, not a security hole.
        // Asserted here as the actual behavior; contrast with
        // testSaveManifestFailsWithInvalidSiteToken above (403).
        $this->haxcms->validRequestToken = false;
        $this->ops->params = array('site_token' => 'bad', 'site' => array('name' => 'my-site'));
        $result = $this->ops->saveNode();
        $this->assertSame(500, $result['__failed']['status']);
        $this->assertSame('failed to write', $result['__failed']['message']);
    }

    // --- saveOutline: site_token gate + platform gate ---

    public function testSaveOutlineFailsWithInvalidSiteToken(): void
    {
        $this->haxcms->validRequestToken = false;
        $this->ops->params = array('site_token' => 'bad', 'site' => array('name' => 'my-site'));
        $result = $this->ops->saveOutline();
        $this->assertSame(403, $result['__failed']['status']);
        $this->assertSame('invalid site token', $result['__failed']['message']);
    }

    public function testSaveOutlineFailsWhenOutlineOperationsDisabled(): void
    {
        $this->haxcms->validRequestToken = true;
        $site = $this->makeFakeSite();
        $site->manifest->metadata->platform->features->outlineDesigner = false;
        $this->haxcms->loadedSite = $site;
        $this->ops->params = array('site_token' => 'good', 'site' => array('name' => 'my-site'));
        $result = $this->ops->saveOutline();
        $this->assertSame(403, $result['__failed']['status']);
        $this->assertSame('Outline operations are disabled for this site', $result['__failed']['message']);
    }
}
