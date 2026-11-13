<?php
use PHPUnit\Framework\TestCase;

include_once dirname(__FILE__) . '/../../lib/SystemStatusService.php';

/**
 * Update awareness for the system status report (haxtheweb/issues#1768).
 * Vectors in tests/fixtures/platform-update-vectors.json are shared verbatim
 * with haxcms-nodejs test/fixtures/platform-update-vectors.json
 * (test/unit/platform-updates.test.cjs), so both backends report the same.
 */
class PlatformUpdateServiceTest extends TestCase
{
    private static function fixture(): array
    {
        return json_decode(file_get_contents(dirname(__FILE__) . '/../fixtures/platform-update-vectors.json'), true);
    }

    public function testCompareVersionsVectors(): void
    {
        foreach (self::fixture()['compareVersions'] as $c) {
            $this->assertSame($c['expected'], HAXCMSPlatformUpdateService::compareVersions($c['a'], $c['b']), $c['a'] . ' vs ' . $c['b']);
        }
    }

    public function testNormalizeAdvisoryPackageVectors(): void
    {
        foreach (self::fixture()['normalizeAdvisoryPackage'] as $c) {
            $this->assertSame($c['expected'], HAXCMSPlatformUpdateService::normalizeAdvisoryPackage($c['name']), $c['name']);
        }
    }

    public function testFindSecurityAdvisoriesVectors(): void
    {
        $fixture = self::fixture();
        foreach ($fixture['findSecurityAdvisories'] as $c) {
            $found = HAXCMSPlatformUpdateService::findSecurityAdvisories($fixture['advisories'], $c['current'], $c['packageKey']);
            $this->assertSame($c['expectedIds'], array_column($found, 'id'), $c['packageKey'] . '@' . $c['current']);
        }
    }

    public function testUntrustedAdvisoryLinkIsDropped(): void
    {
        $found = HAXCMSPlatformUpdateService::findSecurityAdvisories(self::fixture()['advisories'], '26.8.0', 'haxcms-php');
        $untrusted = null;
        foreach ($found as $advisory) {
            if ($advisory['id'] === 'GHSA-0000-0000-untr') {
                $untrusted = $advisory;
            }
        }
        $this->assertNotNull($untrusted);
        $this->assertSame('', $untrusted['url']);
        $this->assertSame('26.8.1', $untrusted['patched']);
    }

    public function testBuildUpdateInfoVectors(): void
    {
        $fixture = self::fixture();
        foreach ($fixture['buildUpdateInfo'] as $c) {
            $in = $c['input'];
            $info = HAXCMSPlatformUpdateService::buildUpdateInfo(array(
                'currentVersion' => $in['currentVersion'],
                'managed' => $in['managed'],
                'checkEnabled' => $in['checkEnabled'],
                'installMethod' => $in['installMethod'],
                'packageKey' => $in['packageKey'],
                'release' => $in['useRelease'] ? $fixture['release'] : null,
                'advisories' => $in['useAdvisories'] ? $fixture['advisories'] : null,
            ));
            $e = $c['expected'];
            $this->assertSame($e['checkEnabled'], $info['checkEnabled'], $c['name']);
            $this->assertSame($e['managed'], $info['managed'], $c['name']);
            $this->assertSame($e['current'], $info['current'], $c['name']);
            $this->assertSame($e['latest'], $info['latest'], $c['name']);
            $this->assertSame($e['updateAvailable'], $info['updateAvailable'], $c['name']);
            $this->assertSame($e['security'], $info['security'], $c['name']);
            $this->assertCount($e['advisoryCount'], $info['advisories'], $c['name']);
            $this->assertSame($e['installMethod'], $info['installMethod'], $c['name']);
            $this->assertCount($e['assetCount'], $info['assets'], $c['name']);
            foreach ($info['assets'] as $asset) {
                $this->assertStringStartsWith('https://github.com/haxtheweb/', $asset['url'], $c['name']);
            }
        }
    }

    public function testUpdateCheckPolicy(): void
    {
        $make = function ($profile, $updates = null) {
            $haxcms = new class {
                public $config;
                public $profile;
                public function getDeploymentProfile()
                {
                    return $this->profile;
                }
            };
            $haxcms->profile = $profile;
            $haxcms->config = new stdClass();
            if ($updates !== null) {
                $haxcms->config->updates = (object) $updates;
            }
            return $haxcms;
        };
        $this->assertSame(array('managed' => false, 'checkEnabled' => true), HAXCMSPlatformUpdateService::getUpdateCheckPolicy($make('single-site')));
        $this->assertSame(array('managed' => false, 'checkEnabled' => false), HAXCMSPlatformUpdateService::getUpdateCheckPolicy($make('self-hosted-multi-site', array('check' => false))));
        $this->assertSame(array('managed' => true, 'checkEnabled' => false), HAXCMSPlatformUpdateService::getUpdateCheckPolicy($make('haxiam-managed', array('check' => true))));
    }

    public function testDetectInstallMethod(): void
    {
        $none = function ($p) {
            return false;
        };
        $git = function ($p) {
            return $p === '/srv/haxcms/.git';
        };
        $docker = function ($p) {
            return $p === '/.dockerenv';
        };
        $previous = getenv('HAXCMS_CONTAINER');
        putenv('HAXCMS_CONTAINER');
        try {
            $this->assertSame('php-archive', HAXCMSPlatformUpdateService::detectInstallMethod('/srv/haxcms', $none));
            $this->assertSame('php-git', HAXCMSPlatformUpdateService::detectInstallMethod('/srv/haxcms/', $git));
            $this->assertSame('php-docker', HAXCMSPlatformUpdateService::detectInstallMethod('/srv/haxcms', $docker));
        } finally {
            if ($previous !== false) {
                putenv('HAXCMS_CONTAINER=' . $previous);
            }
        }
    }

    public function testVersionRowWithoutUpdateIsUnchanged(): void
    {
        $row = HAXCMSSystemStatusService::buildVersionRow('26.8.1', '26.8.1', 'https://x');
        $this->assertSame('ok', $row['tone']);
        $this->assertSame('Current: 26.8.1 · Latest: 26.8.1', $row['description']);
    }

    public function testVersionRowManagedOffAndSecurity(): void
    {
        $managed = HAXCMSSystemStatusService::buildVersionRow('26.8.1', 'unknown', 'https://x', array('managed' => true, 'checkEnabled' => false));
        $this->assertSame('info', $managed['tone']);
        $this->assertStringContainsString('managed by your host', $managed['description']);
        $off = HAXCMSSystemStatusService::buildVersionRow('26.8.1', 'unknown', 'https://x', array('managed' => false, 'checkEnabled' => false));
        $this->assertSame('info', $off['tone']);
        $this->assertStringContainsString('turned off', $off['description']);
        $fixture = self::fixture();
        $info = HAXCMSPlatformUpdateService::buildUpdateInfo(array(
            'currentVersion' => '26.8.1',
            'checkEnabled' => true,
            'release' => $fixture['release'],
            'advisories' => $fixture['advisories'],
            'installMethod' => 'php-archive',
        ));
        $security = HAXCMSSystemStatusService::buildVersionRow('26.8.1', $info['latest'], 'https://x', $info);
        $this->assertSame('error', $security['tone']);
        $this->assertStringContainsString('Security update available: GHSA-6w22-ghxf-x2pv', $security['description']);
        $this->assertSame('GHSA-6w22-ghxf-x2pv (high)', $security['links'][0]['label']);
    }

    public function testStatusReportCarriesUpdateObject(): void
    {
        $update = HAXCMSPlatformUpdateService::buildUpdateInfo(array('currentVersion' => '26.8.1', 'managed' => true));
        $report = HAXCMSSystemStatusService::buildStatusReport(array(
            'haxcmsVersionCurrent' => '26.8.1',
            'update' => $update,
        ));
        $this->assertSame($update, $report['update']);
        $without = HAXCMSSystemStatusService::buildStatusReport(array('haxcmsVersionCurrent' => '26.8.1'));
        $this->assertArrayNotHasKey('update', $without);
    }
}
