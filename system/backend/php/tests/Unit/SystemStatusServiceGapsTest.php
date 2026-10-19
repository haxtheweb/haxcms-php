<?php
use PHPUnit\Framework\TestCase;

/**
 * Gap tests for lib/SystemStatusService.php complementing ServicesTest:
 * buildInstallerStatusReport (install wizard) and the private
 * buildDirectoryStatusRow / helper branches. buildInstallerStatusReport
 * reaches fetchLatestReleaseVersionFromGitHub (4s timeout, statically
 * cached per process), so assertions never depend on the network result:
 * when the fetch fails the latest version falls back to the current one.
 */
class SystemStatusServiceGapsTest extends TestCase
{
    private $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/haxcms_status_gaps_' . uniqid();
        mkdir($this->tmp, 0777, true);
    }

    protected function tearDown(): void
    {
        @chmod($this->tmp . '/ro', 0777);
        exec('rm -rf ' . escapeshellarg($this->tmp));
    }

    private function call(string $method, ...$args)
    {
        $ref = new ReflectionMethod('HAXCMSSystemStatusService', $method);
        $ref->setAccessible(true);
        return $ref->invokeArgs(null, $args);
    }

    private function row(array $rows, string $key): ?array
    {
        foreach ($rows as $row) {
            if ($row['key'] === $key) {
                return $row;
            }
        }
        return null;
    }

    public function testInstallerReportBeforeInstall(): void
    {
        file_put_contents($this->tmp . '/VERSION.txt', "v9.8.7\n");
        mkdir($this->tmp . '/_config', 0777, true);
        file_put_contents($this->tmp . '/_config/config.php', "<?php // HAXTHEWEBPRIVATEKEY placeholder");
        file_put_contents($this->tmp . '/_config/SALT.txt', 'salt');

        $report = HAXCMSSystemStatusService::buildInstallerStatusReport($this->tmp . '/');

        $this->assertSame('9.8.7', $report['summary']['haxcmsVersionCurrent']);
        $this->assertNotSame('', $report['summary']['haxcmsVersionLatest']);
        $this->assertSame($this->tmp . '/_config', $report['summary']['configDirectory']);
        $this->assertStringStartsWith('Writable', $this->row($report['rows'], 'config-directory')['value']);
        $this->assertSame('Missing', $this->row($report['rows'], 'sites-directory')['value']);
        $this->assertSame("mkdir -p '" . $this->tmp . "/_sites'", $this->row($report['rows'], 'sites-directory')['suggestedCommand']);
        // placeholder keys in config.php => secrets not loaded (pending warning)
        $this->assertNotSame('ok', $this->row($report['rows'], 'security-secrets')['tone']);
    }

    public function testInstallerReportPrefersDotVersionAndDetectsRealKeys(): void
    {
        file_put_contents($this->tmp . '/.version', '1.0.0');
        file_put_contents($this->tmp . '/VERSION.txt', '2.0.0');
        mkdir($this->tmp . '/_config', 0777, true);
        file_put_contents($this->tmp . '/_config/config.php', "<?php \$key = 'real';");
        file_put_contents($this->tmp . '/_config/SALT.txt', 'salt');
        $report = HAXCMSSystemStatusService::buildInstallerStatusReport($this->tmp);
        $this->assertSame('1.0.0', $report['summary']['haxcmsVersionCurrent']);
        $this->assertSame('ok', $this->row($report['rows'], 'security-secrets')['tone']);
    }

    public function testInstallerReportWithoutVersionFileIsUnknown(): void
    {
        $report = HAXCMSSystemStatusService::buildInstallerStatusReport($this->tmp);
        $this->assertSame('unknown', $report['summary']['haxcmsVersionCurrent']);
    }

    public function testDirectoryRowWithoutPathIsUnavailable(): void
    {
        $owner = array('uid' => null, 'gid' => null);
        $required = $this->call('buildDirectoryStatusRow', array('key' => 'k', 'title' => 'T'), $owner);
        $this->assertSame(array('key' => 'k', 'tone' => 'error', 'title' => 'T', 'value' => 'Unavailable',
            'description' => 'Directory path could not be determined.', 'required' => true), $required);
        $optional = $this->call('buildDirectoryStatusRow', array('required' => false), $owner);
        $this->assertSame('warning', $optional['tone']);
        $this->assertSame('directory', $optional['key']);
        $this->assertSame('Directory', $optional['title']);
    }

    public function testDirectoryRowReadOnlySuggestsChmod(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root can write to read-only directories');
        }
        $dir = $this->tmp . '/ro';
        mkdir($dir, 0555);
        $row = $this->call('buildDirectoryStatusRow', array('key' => 'ro', 'path' => $dir, 'required' => false),
            array('uid' => null, 'gid' => null));
        $this->assertSame('Read-only', $row['value']);
        $this->assertSame('warning', $row['tone']);
        $this->assertSame("chmod 0755 '" . $dir . "'", $row['suggestedCommand']);
    }

    public function testDirectoryRowOwnerMismatchSuggestsChown(): void
    {
        $row = $this->call('buildDirectoryStatusRow', array('key' => 'mm', 'path' => $this->tmp),
            array('uid' => 987654, 'gid' => 876543));
        $this->assertSame('Writable (owner mismatch)', $row['value']);
        $this->assertSame('warning', $row['tone']);
        $this->assertSame("chown -R 987654:876543 '" . $this->tmp . "'", $row['suggestedCommand']);
    }

    public function testSmallHelpers(): void
    {
        $this->assertSame('', $this->call('resolveServerVersionLink', '  '));
        $this->assertSame('https://httpd.apache.org/', $this->call('resolveServerVersionLink', 'httpd/2'));
        $this->assertSame('/seg', $this->call('joinPath', '', '/seg'));
        $this->assertSame('/a/b', $this->call('joinPath', '/a/', 'b'));
    }
}
