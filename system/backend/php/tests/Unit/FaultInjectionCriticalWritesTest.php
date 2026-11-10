<?php
require_once __DIR__ . '/FaultInjectionTestSupport.php';

/**
 * Critical writes other than saveOutline under filesystem faults, driven
 * through the site-route handlers as an HTTP request would. Mirrors
 * haxcms-nodejs test/unit/fault-injection-critical-writes-http.test.cjs;
 * scenario IDs (FI-NODE-*, FI-SITE-*, FI-UP-*, FI-HTTP-*) are shared.
 *
 * Tests that PHP does not pass today run through knownBug() and report as
 * skipped with the reason.
 */
class FaultInjectionCriticalWritesTest extends FaultInjectionTestCase
{
    private function siteJsonEnospc(): array
    {
        return array(array('op' => 'open', 'match' => $this->isSiteJsonWrite(), 'errno' => 'ENOSPC'));
    }

    // FI-NODE-01 PATCH /x/api/v1/content/{id}, EACCES on the page file
    public function testFiNode01SaveContentEaccesIs500AndChangesNothing(): void
    {
        $pageBefore = file_get_contents($this->pageFile('b'));
        $siteBefore = $this->siteJsonBytes();
        $res = $this->request('contentMutation.php', $this->context(
            array('body' => '<page-break title="Edited" item-id="b" slug="b"></page-break><p>edited</p>'),
            'PATCH',
            'content',
            array('idOrSlug' => 'b')
        ), array(
            array('op' => 'open', 'match' => $this->isPath($this->pageFile('b')), 'errno' => 'EACCES'),
        ));
        $this->assertNotEmpty($res['fired'], 'fault fired');
        $this->assertErrorEnvelope($res, 500, 'failed to write');
        $this->assertSame($pageBefore, file_get_contents($this->pageFile('b')));
        $this->assertSame($siteBefore, $this->siteJsonBytes(), 'site.json unchanged');
        $this->assertStillServing();
    }

    // FI-NODE-02 POST /x/api/v1/items, ENOSPC on site.json
    public function testFiNode02CreatePageSiteJsonEnospc(): void
    {
        $before = $this->siteJsonBytes();
        $res = $this->request('itemsMutation.php', $this->context(
            array('node' => array('title' => 'New page')),
            'POST',
            'items'
        ), $this->siteJsonEnospc());
        $this->assertNotEmpty($res['fired'], 'fault fired');
        $this->assertSame($before, $this->siteJsonBytes());
        $this->assertErrorEnvelope($res, 500, 'Server error');
        $this->knownBug(
            'FI-NODE-02 the new page folder is copied before site.json is written and is not removed when that write fails (same as haxcms-nodejs)',
            function () {
                $this->assertSame(array(), $this->orphanPageFolders(), 'no orphan page folder');
            }
        );
        $this->assertStillServing();
    }

    // FI-NODE-03 DELETE /x/api/v1/items/{id}, ENOSPC on site.json
    public function testFiNode03DeletePageSiteJsonEnospc(): void
    {
        $before = $this->siteJsonBytes();
        $res = $this->request('itemsMutation.php', $this->context(
            array(),
            'DELETE',
            'items',
            array('idOrSlug' => 'b')
        ), $this->siteJsonEnospc());
        $this->assertNotEmpty($res['fired'], 'fault fired');
        $this->assertSame($before, $this->siteJsonBytes(), 'page still listed');
        $this->assertFileExists($this->pageFile('b'));
        $this->assertErrorEnvelope($res, 500, 'Server error');
        $this->assertStillServing();
    }

    // FI-SITE-01 PATCH /x/api/v1/site, ENOSPC on site.json
    public function testFiSite01SiteSettingsSiteJsonEnospc(): void
    {
        $before = $this->siteJsonBytes();
        $res = $this->request('siteMutation.php', $this->context(
            array('manifest' => array('site' => array('manifest-title' => 'Renamed site'))),
            'PATCH',
            'site'
        ), $this->siteJsonEnospc());
        $this->assertNotEmpty($res['fired'], 'fault fired');
        $this->assertSame($before, $this->siteJsonBytes());
        $this->assertErrorEnvelope($res, 500, 'Server error');
        $this->assertStillServing();
    }

    // FI-UP-01 POST /x/api/v1/files, the write into files/ fails. PHP has no
    // move/rename step: HAXCMSFile::save() reads the upload and writes it
    // with file_put_contents(), so the equivalent fault is on that write.
    public function testFiUp01UploadWriteFailureIs500AndLeavesNothing(): void
    {
        $staging = $this->haxcms->configDirectory . '/tmp/imports';
        mkdir($staging, 0777, true);
        $tmp = $staging . '/upload.png';
        // 1x1 transparent PNG
        file_put_contents($tmp, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));
        $_FILES['file-upload'] = array(
            'name' => 'pixel.png',
            'type' => 'image/png',
            'tmp_name' => $tmp,
            'error' => UPLOAD_ERR_OK,
            'size' => filesize($tmp),
            // the bulk-import staging path stands in for is_uploaded_file(),
            // which is always false under the CLI
            'bulk-import' => true,
        );
        $filesDir = $this->siteDir() . '/files';
        $res = $this->request('filesMutation.php', $this->context(array(), 'POST', 'files'), array(
            array('op' => 'open', 'match' => function ($path) use ($filesDir) {
                return strpos($path, $filesDir . '/') === 0;
            }, 'errno' => 'EXDEV'),
        ));
        $this->assertNotEmpty($res['fired'], 'fault fired');
        $this->assertErrorEnvelope($res, 500);
        $this->assertSame('FILE_OP_FAILED', $res['body']['data']['code']);
        $this->assertSame(array('.', '..'), scandir($filesDir), 'no new file under files/');
        // The upload temp file needs no check here: PHP deletes request
        // uploads itself when the request ends (the bulk-import staging file
        // used to stand in for one belongs to the caller).
        // same message as haxcms-nodejs
        $this->assertSame('Unable to save file to target location', $res['body']['data']['message']);
        $this->assertStillServing();
    }

    // FI-HTTP-01 an IO error thrown inside a site write route
    public function testFiHttp01ThrownIoErrorIsA5xxEnvelope(): void
    {
        // A page whose alternate-format rebuild throws, as an IO layer that
        // raises instead of returning false would.
        $site = new FaultInjectionThrowingSite();
        $site->load($this->tmpRoot, '/', $this->siteName);
        $this->haxcms->loadedSite = $site;
        $this->site = $site;
        $res = $this->request('itemsMutation.php', $this->context(
            array('node' => array('title' => 'New page')),
            'POST',
            'items'
        ));
        $this->assertErrorEnvelope($res, 500, 'Server error');
        // each PHP request is its own process, so the next one is served
        $this->assertStillServing();
    }
}

/** site whose feed rebuild throws, standing in for an IO layer that throws */
class FaultInjectionThrowingSite extends OperationsNodeTestSite
{
    public function updateAlternateFormats($format = null)
    {
        throw new RuntimeException('EIO: injected i/o error');
    }
}
