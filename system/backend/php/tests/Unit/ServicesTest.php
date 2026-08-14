<?php
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Characterization tests for the pure/config methods of the service cluster.
 *
 * Each service is exercised through its PUBLIC static/instance seam against a
 * per-test temp configDirectory (rrmdir'd in tearDown). Expected values come
 * from the contract / independent literals, not by copying the implementation.
 *
 * Classes covered (file => class):
 *  lib/APIKeysService.php        => HAXCMSAPIKeysService
 *  lib/MediaSettingsService.php  => HAXCMSMediaSettingsService
 *  lib/ThemeSettingsService.php  => HAXCMSThemeSettingsService
 *  lib/SkeletonSettingsService.php => HAXCMSSkeletonSettingsService
 *  lib/SystemStatusService.php   => HAXCMSSystemStatusService
 *  lib/ReportHelpers.php         => HAXCMSReportHelpers
 *  lib/HAXAppStoreService.php    => HAXAppStoreService
 *  lib/SsrfGuard.php             => SsrfGuard + SsrfGuardException
 *
 * A ServicesTestHaxcms stub (declared at the bottom of this file) supplies the
 * generateMachineName / validateRequestToken / getActiveUserName collaborators
 * that Theme/Skeleton/ReportHelpers delegate to, plus a configurable
 * configDirectory + config. It is self-contained and does not depend on any
 * other test file.
 */
class ServicesTest extends TestCase
{
    private $tmpRoot;
    private $savedHaxcms;
    private $savedServerSoftware;

    protected function setUp(): void
    {
        $this->tmpRoot = sys_get_temp_dir() . '/haxcms_services_' . uniqid();
        mkdir($this->tmpRoot, 0777, true);
        if (isset($GLOBALS['HAXCMS'])) {
            $this->savedHaxcms = $GLOBALS['HAXCMS'];
        }
        $this->savedServerSoftware = $_SERVER['SERVER_SOFTWARE'] ?? null;
    }

    protected function tearDown(): void
    {
        if (isset($this->savedHaxcms)) {
            $GLOBALS['HAXCMS'] = $this->savedHaxcms;
            $this->savedHaxcms = null;
        } else {
            unset($GLOBALS['HAXCMS']);
        }
        if ($this->savedServerSoftware !== null) {
            $_SERVER['SERVER_SOFTWARE'] = $this->savedServerSoftware;
        } else {
            unset($_SERVER['SERVER_SOFTWARE']);
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
            if (is_dir($path)) {
                $this->rrmdir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    private function makeHaxcms(): ServicesTestHaxcms
    {
        $haxcms = new ServicesTestHaxcms();
        $haxcms->configDirectory = $this->tmpRoot;
        return $haxcms;
    }

    // ====================================================================
    // HAXCMSAPIKeysService (lib/APIKeysService.php)
    // ====================================================================

    public function testApiKeysGetSupportedProvidersReturnsCanonicalList(): void
    {
        // Independent source of truth: the SUPPORTED_PROVIDERS constant is the
        // public contract for which providers the AppStore wiring knows about.
        $this->assertSame(
            array('youtube', 'vimeo', 'giphy', 'unsplash', 'flickr', 'anthropic'),
            HAXCMSAPIKeysService::getSupportedProviders()
        );
    }

    public static function apiKeysNormalizeProvider(): array
    {
        // Contract: every supported provider key is present; missing => '';
        // string values trimmed; null => ''; non-strings stringified+trimmed;
        // unknown keys dropped.
        $full = array(
            'youtube' => 'yt-key', 'vimeo' => 'vm-key', 'giphy' => 'gp-key',
            'unsplash' => 'us-key', 'flickr' => 'fl-key', 'anthropic' => 'an-key',
        );
        return array(
            'empty array fills all with empty string' => array(
                array(),
                array(
                    'youtube' => '', 'vimeo' => '', 'giphy' => '',
                    'unsplash' => '', 'flickr' => '', 'anthropic' => '',
                ),
            ),
            'partial input fills missing providers' => array(
                array('youtube' => 'yt-key'),
                array(
                    'youtube' => 'yt-key', 'vimeo' => '', 'giphy' => '',
                    'unsplash' => '', 'flickr' => '', 'anthropic' => '',
                ),
            ),
            'trims whitespace from values' => array(
                array('youtube' => '  yt-key  '),
                array(
                    'youtube' => 'yt-key', 'vimeo' => '', 'giphy' => '',
                    'unsplash' => '', 'flickr' => '', 'anthropic' => '',
                ),
            ),
            'null value becomes empty string' => array(
                array('youtube' => null),
                array(
                    'youtube' => '', 'vimeo' => '', 'giphy' => '',
                    'unsplash' => '', 'flickr' => '', 'anthropic' => '',
                ),
            ),
            'unknown keys are dropped' => array(
                array('foo' => 'bar', 'youtube' => 'yt'),
                array(
                    'youtube' => 'yt', 'vimeo' => '', 'giphy' => '',
                    'unsplash' => '', 'flickr' => '', 'anthropic' => '',
                ),
            ),
            'full input preserved (trimmed)' => array(
                $full,
                array(
                    'youtube' => 'yt-key', 'vimeo' => 'vm-key', 'giphy' => 'gp-key',
                    'unsplash' => 'us-key', 'flickr' => 'fl-key', 'anthropic' => 'an-key',
                ),
            ),
            'object input treated like array' => array(
                (object) array('youtube' => 'yt-key'),
                array(
                    'youtube' => 'yt-key', 'vimeo' => '', 'giphy' => '',
                    'unsplash' => '', 'flickr' => '', 'anthropic' => '',
                ),
            ),
        );
    }

    #[DataProvider('apiKeysNormalizeProvider')]
    public function testApiKeysNormalizeAPIKeys(mixed $input, array $expected): void
    {
        $this->assertSame($expected, HAXCMSAPIKeysService::normalizeAPIKeys($input));
    }

    public static function apiKeysHasPayloadProvider(): array
    {
        return array(
            'supported key present' => array(array('youtube' => 'x'), true),
            'unsupported key only' => array(array('foo' => 'bar'), false),
            'empty array' => array(array(), false),
            'object with supported key' => array((object) array('vimeo' => 'x'), true),
            'null' => array(null, false),
        );
    }

    #[DataProvider('apiKeysHasPayloadProvider')]
    public function testApiKeysHasSupportedAPIKeyPayload(mixed $input, bool $expected): void
    {
        $this->assertSame($expected, HAXCMSAPIKeysService::hasSupportedAPIKeyPayload($input));
    }

    public function testApiKeysReadAPIKeysEmptyWhenNoFile(): void
    {
        $haxcms = $this->makeHaxcms();
        $this->assertSame(
            array(
                'youtube' => '', 'vimeo' => '', 'giphy' => '',
                'unsplash' => '', 'flickr' => '', 'anthropic' => '',
            ),
            HAXCMSAPIKeysService::readAPIKeys($haxcms)
        );
    }

    public function testApiKeysWriteAPIKeysPersistsJsonAndChmod0600(): void
    {
        $haxcms = $this->makeHaxcms();
        $input = array('youtube' => '  yt-secret  ', 'vimeo' => 'vm-secret');
        $result = HAXCMSAPIKeysService::writeAPIKeys($haxcms, $input);
        // returns the normalized map (trimmed)
        $this->assertSame('yt-secret', $result['youtube']);
        $this->assertSame('vm-secret', $result['vimeo']);
        // file exists on disk under <configDirectory>/settings/apiKeys.json
        $filePath = $this->tmpRoot . '/settings/apiKeys.json';
        $this->assertFileExists($filePath);
        // independent source of truth: read the file back and decode it
        $persisted = json_decode(file_get_contents($filePath), true);
        $this->assertSame('yt-secret', $persisted['youtube']);
        $this->assertSame('vm-secret', $persisted['vimeo']);
        // all providers present on disk
        $this->assertSame(
            array('youtube', 'vimeo', 'giphy', 'unsplash', 'flickr', 'anthropic'),
            array_keys($persisted)
        );
        // SEC-10: secrets file must be 0600
        $this->assertSame(0600, fileperms($filePath) & 0777);
    }

    public function testApiKeysReadAPIKeysRoundTripsThroughFile(): void
    {
        $haxcms = $this->makeHaxcms();
        HAXCMSAPIKeysService::writeAPIKeys($haxcms, array('youtube' => 'yt-key', 'giphy' => 'gp-key'));
        $read = HAXCMSAPIKeysService::readAPIKeys($haxcms);
        $this->assertSame('yt-key', $read['youtube']);
        $this->assertSame('gp-key', $read['giphy']);
        $this->assertSame('', $read['vimeo']);
    }

    public function testApiKeysReadEffectiveAPIKeysMergesConfigAndFile(): void
    {
        $haxcms = $this->makeHaxcms();
        // config provides youtube + vimeo
        $haxcms->config->appStore = new stdClass();
        $haxcms->config->appStore->apiKeys = (object) array(
            'youtube' => 'config-yt',
            'vimeo' => 'config-vm',
        );
        // file provides youtube (override) + giphy (new); vimeo empty (keep config)
        HAXCMSAPIKeysService::writeAPIKeys($haxcms, array(
            'youtube' => 'file-yt',
            'vimeo' => '',
            'giphy' => 'file-gp',
        ));
        $effective = HAXCMSAPIKeysService::readEffectiveAPIKeys($haxcms);
        // file non-empty overrides config
        $this->assertSame('file-yt', $effective['youtube']);
        // file empty => config value retained
        $this->assertSame('config-vm', $effective['vimeo']);
        // file-only provider surfaced
        $this->assertSame('file-gp', $effective['giphy']);
        // unsupported/absent providers stay empty
        $this->assertSame('', $effective['unsplash']);
        $this->assertSame('', $effective['flickr']);
        $this->assertSame('', $effective['anthropic']);
    }

    public function testApiKeysReadEffectiveAPIKeysReturnsConfigWhenNoFile(): void
    {
        $haxcms = $this->makeHaxcms();
        $haxcms->config->appStore = new stdClass();
        $haxcms->config->appStore->apiKeys = (object) array('youtube' => 'config-yt');
        $effective = HAXCMSAPIKeysService::readEffectiveAPIKeys($haxcms);
        $this->assertSame('config-yt', $effective['youtube']);
        $this->assertSame('', $effective['vimeo']);
    }

    // ====================================================================
    // HAXCMSMediaSettingsService (lib/MediaSettingsService.php)
    // ====================================================================

    public static function mediaJpegQualityProvider(): array
    {
        // Contract: null/'' => null; non-integer => null; clamped to [1,100].
        return array(
            'null => null' => array(null, null),
            'empty string => null' => array('', null),
            'non-numeric => null' => array('abc', null),
            'in range 50 => 50' => array(50, 50),
            'string 75 => 75' => array('75', 75),
            'below min 0 => 1 (clamped)' => array(0, 1),
            'below min -5 => 1 (clamped)' => array(-5, 1),
            'above max 150 => 100 (clamped)' => array(150, 100),
            'boundary min 1 => 1' => array(1, 1),
            'boundary max 100 => 100' => array(100, 100),
        );
    }

    #[DataProvider('mediaJpegQualityProvider')]
    public function testMediaNormalizeJpegQualityClamps(mixed $input, mixed $expected): void
    {
        $result = HAXCMSMediaSettingsService::normalizeMediaSettings(array('jpegQuality' => $input));
        $this->assertSame($expected, $result['jpegQuality']);
    }

    public static function mediaUploadSizeProvider(): array
    {
        // Contract: null/'' => null; non-integer => null; clamped to [1,10240].
        return array(
            'null => null' => array(null, null),
            'empty string => null' => array('', null),
            'non-numeric => null' => array('big', null),
            'in range 50 => 50' => array(50, 50),
            'string 100 => 100' => array('100', 100),
            'below min 0 => 1 (clamped)' => array(0, 1),
            'above max 20000 => 10240 (clamped)' => array(20000, 10240),
            'boundary min 1 => 1' => array(1, 1),
            'boundary max 10240 => 10240' => array(10240, 10240),
        );
    }

    #[DataProvider('mediaUploadSizeProvider')]
    public function testMediaNormalizeMaxUploadSizeClamps(mixed $input, mixed $expected): void
    {
        $result = HAXCMSMediaSettingsService::normalizeMediaSettings(array('maxUploadSizeMb' => $input));
        $this->assertSame($expected, $result['maxUploadSizeMb']);
    }

    public static function mediaAcceptedFormatsProvider(): array
    {
        // Contract: null => null; array or csv => lowercase, strip leading '.',
        // keep only [a-z0-9]+ tokens, dedup, order-preserving; empty => null.
        return array(
            'null => null' => array(null, null),
            'empty string => null' => array('', null),
            'simple array' => array(array('jpg', 'png'), 'jpg,png'),
            'csv string' => array('jpg,png', 'jpg,png'),
            'mixed case + dots + spaces' => array('JPG, .Png', 'jpg,png'),
            'dedup' => array(array('jpg', 'jpg', 'png'), 'jpg,png'),
            'empty entries skipped' => array(array('jpg', '', 'png'), 'jpg,png'),
            'invalid chars rejected => null' => array('jpg|png', null),
            'integer input => null' => array(123, null),
            'leading dot stripped' => array('.jpg', 'jpg'),
        );
    }

    #[DataProvider('mediaAcceptedFormatsProvider')]
    public function testMediaNormalizeAcceptedFormats(mixed $input, mixed $expected): void
    {
        $result = HAXCMSMediaSettingsService::normalizeMediaSettings(array('acceptedFormats' => $input));
        $this->assertSame($expected, $result['acceptedFormats']);
    }

    public function testMediaNormalizeEmptyInputAllNull(): void
    {
        $this->assertSame(
            array('jpegQuality' => null, 'maxUploadSizeMb' => null, 'acceptedFormats' => null),
            HAXCMSMediaSettingsService::normalizeMediaSettings(array())
        );
    }

    public function testMediaReadSettingsEmptyWhenNoFile(): void
    {
        $haxcms = $this->makeHaxcms();
        $this->assertSame(
            array('jpegQuality' => null, 'maxUploadSizeMb' => null, 'acceptedFormats' => null),
            HAXCMSMediaSettingsService::readMediaSettings($haxcms)
        );
    }

    public function testMediaWriteSettingsRoundTripsThroughFile(): void
    {
        $haxcms = $this->makeHaxcms();
        $result = HAXCMSMediaSettingsService::writeMediaSettings($haxcms, array(
            'jpegQuality' => 80,
            'maxUploadSizeMb' => 100,
            'acceptedFormats' => array('jpg', 'png'),
        ));
        $this->assertSame(80, $result['jpegQuality']);
        $this->assertSame(100, $result['maxUploadSizeMb']);
        $this->assertSame('jpg,png', $result['acceptedFormats']);
        $read = HAXCMSMediaSettingsService::readMediaSettings($haxcms);
        $this->assertSame($result, $read);
    }

    public function testMediaWriteSettingsMergesPartialUpdateWithExistingFile(): void
    {
        // Contract: writeMediaSettings is a PARTIAL update — it reads the
        // existing file first and only overwrites keys present in $settings.
        $haxcms = $this->makeHaxcms();
        HAXCMSMediaSettingsService::writeMediaSettings($haxcms, array('jpegQuality' => 80));
        $second = HAXCMSMediaSettingsService::writeMediaSettings($haxcms, array('maxUploadSizeMb' => 50));
        // jpegQuality from the first write is retained; maxUploadSizeMb is new
        $this->assertSame(80, $second['jpegQuality']);
        $this->assertSame(50, $second['maxUploadSizeMb']);
        $this->assertNull($second['acceptedFormats']);
        $read = HAXCMSMediaSettingsService::readMediaSettings($haxcms);
        $this->assertSame(80, $read['jpegQuality']);
        $this->assertSame(50, $read['maxUploadSizeMb']);
    }

    public static function mediaIsValidProvider(): array
    {
        return array(
            'jpegQuality empty valid' => array('jpegQuality', '', true),
            'jpegQuality numeric valid' => array('jpegQuality', '50', true),
            'jpegQuality non-numeric invalid' => array('jpegQuality', 'abc', false),
            'uploadSize empty valid' => array('maxUploadSizeMb', '', true),
            'uploadSize numeric valid' => array('maxUploadSizeMb', '50', true),
            'uploadSize non-numeric invalid' => array('maxUploadSizeMb', 'huge', false),
            'formats empty valid' => array('acceptedFormats', '', true),
            'formats csv valid' => array('acceptedFormats', 'jpg,png', true),
            'formats invalid chars' => array('acceptedFormats', 'jpg|png', false),
        );
    }

    #[DataProvider('mediaIsValidProvider')]
    public function testMediaIsValidPayloadValue(string $field, string $value, bool $expected): void
    {
        if ($field === 'jpegQuality') {
            $this->assertSame($expected, HAXCMSMediaSettingsService::isValidJpegQualityPayloadValue($value));
        } else if ($field === 'maxUploadSizeMb') {
            $this->assertSame($expected, HAXCMSMediaSettingsService::isValidMaxUploadSizeMbPayloadValue($value));
        } else {
            $this->assertSame($expected, HAXCMSMediaSettingsService::isValidAcceptedFormatsPayloadValue($value));
        }
    }

    // ====================================================================
    // HAXCMSThemeSettingsService (lib/ThemeSettingsService.php)
    // ====================================================================

    public static function booleanNormalizeProvider(): array
    {
        // Contract: bool as-is; int/float => nonzero; string keywords; else default.
        return array(
            'true bool' => array(true, true, true),
            'false bool' => array(false, true, false),
            'int 0 => false' => array(0, true, false),
            'int 1 => true' => array(1, true, true),
            'float 0.0 => false' => array(0.0, true, false),
            'float 2.5 => true' => array(2.5, true, true),
            'string true' => array('true', true, true),
            'string false' => array('false', true, false),
            'string 0' => array('0', true, false),
            'string 1' => array('1', true, true),
            'string off' => array('off', true, false),
            'string on' => array('on', true, true),
            'string no' => array('no', true, false),
            'string yes' => array('yes', true, true),
            'string disabled' => array('disabled', true, false),
            'string enabled' => array('enabled', true, true),
            'string TRUE uppercase' => array('TRUE', true, true),
            'string FALSE uppercase' => array('FALSE', true, false),
            'string with whitespace false' => array('  false  ', true, false),
            'unknown string => default true' => array('maybe', true, true),
            'unknown string => default false' => array('maybe', false, false),
            'null => default true' => array(null, true, true),
            'null => default false' => array(null, false, false),
            'empty string => default true' => array('', true, true),
            'empty string => default false' => array('', false, false),
            'array => default true' => array(array('x'), true, true),
        );
    }

    #[DataProvider('booleanNormalizeProvider')]
    public function testThemeNormalizeBoolean(mixed $value, bool $defaultValue, bool $expected): void
    {
        $this->assertSame($expected, HAXCMSThemeSettingsService::normalizeBoolean($value, $defaultValue));
    }

    public function testThemeReadEnabledThemeMapEmptyWhenNoFile(): void
    {
        $haxcms = $this->makeHaxcms();
        $this->assertSame(array(), HAXCMSThemeSettingsService::readEnabledThemeMap($haxcms));
    }

    public function testThemeWriteAndReadEnabledThemeMapRoundTrip(): void
    {
        $haxcms = $this->makeHaxcms();
        $input = array('my-theme' => true, 'other-theme' => false, 'Party Theme' => true);
        $written = HAXCMSThemeSettingsService::writeEnabledThemeMap($haxcms, $input);
        // keys are machine-named; 'Party Theme' => 'party-theme' by the stub
        $this->assertArrayHasKey('my-theme', $written);
        $this->assertArrayHasKey('other-theme', $written);
        $this->assertArrayHasKey('party-theme', $written);
        $this->assertTrue($written['my-theme']);
        $this->assertFalse($written['other-theme']);
        $this->assertTrue($written['party-theme']);
        // read back
        $read = HAXCMSThemeSettingsService::readEnabledThemeMap($haxcms);
        $this->assertSame($written, $read);
    }

    public function testThemeWriteEnabledThemeMapPersistsUnderSettingsDir(): void
    {
        $haxcms = $this->makeHaxcms();
        HAXCMSThemeSettingsService::writeEnabledThemeMap($haxcms, array('alpha' => true));
        $filePath = $this->tmpRoot . '/settings/enabledThemes.json';
        $this->assertFileExists($filePath);
        $persisted = json_decode(file_get_contents($filePath), true);
        $this->assertArrayHasKey('enabledThemes', $persisted);
        $this->assertTrue($persisted['enabledThemes']['alpha']);
    }

    public function testThemeNormalizeEnabledThemeMapFromListInput(): void
    {
        $haxcms = $this->makeHaxcms();
        // A sequential list of names => each maps to true.
        $map = HAXCMSThemeSettingsService::normalizeEnabledThemeMap($haxcms, array('My Theme', 'cool-theme'));
        $this->assertSame(array('my-theme' => true, 'cool-theme' => true), $map);
    }

    public function testThemeNormalizeEnabledThemeMapFromMapInput(): void
    {
        $haxcms = $this->makeHaxcms();
        $map = HAXCMSThemeSettingsService::normalizeEnabledThemeMap($haxcms, array(
            'my-theme' => true,
            'disabled-theme' => 'false',
            'flagged' => 0,
        ));
        $this->assertTrue($map['my-theme']);
        $this->assertFalse($map['disabled-theme']);
        $this->assertFalse($map['flagged']);
    }

    public static function themeIsEnabledProvider(): array
    {
        return array(
            'empty machine name => true (default-allow)' => array('', array(), true),
            'not in map => true (default-allow)' => array('unknown', array('other' => true), true),
            'in map true => true' => array('my-theme', array('my-theme' => true), true),
            'in map false => false' => array('my-theme', array('my-theme' => false), false),
            'in map string "false" => false' => array('my-theme', array('my-theme' => 'false'), false),
        );
    }

    #[DataProvider('themeIsEnabledProvider')]
    public function testThemeIsThemeEnabled(string $machineName, array $enabledThemes, bool $expected): void
    {
        $haxcms = $this->makeHaxcms();
        $this->assertSame($expected, HAXCMSThemeSettingsService::isThemeEnabled($haxcms, $machineName, $enabledThemes));
    }

    public function testThemeApplyDetectedThemeDefaultsAddsMissingAndReportsChange(): void
    {
        $haxcms = $this->makeHaxcms();
        $result = HAXCMSThemeSettingsService::applyDetectedThemeDefaults(
            $haxcms,
            array('existing' => true),
            array('New One', 'existing')
        );
        $this->assertTrue($result['changed']);
        $this->assertArrayHasKey('existing', $result['enabledThemes']);
        $this->assertArrayHasKey('new-one', $result['enabledThemes']);
        $this->assertTrue($result['enabledThemes']['new-one']);
    }

    public function testThemeApplyDetectedThemeDefaultsNoChangeWhenAllPresent(): void
    {
        $haxcms = $this->makeHaxcms();
        $result = HAXCMSThemeSettingsService::applyDetectedThemeDefaults(
            $haxcms,
            array('a' => true, 'b' => true),
            array('a', 'b')
        );
        $this->assertFalse($result['changed']);
    }

    // ====================================================================
    // HAXCMSSkeletonSettingsService (lib/SkeletonSettingsService.php)
    // ====================================================================

    #[DataProvider('booleanNormalizeProvider')]
    public function testSkeletonNormalizeBoolean(mixed $value, bool $defaultValue, bool $expected): void
    {
        $this->assertSame($expected, HAXCMSSkeletonSettingsService::normalizeBoolean($value, $defaultValue));
    }

    public function testSkeletonReadEnabledSkeletonMapEmptyWhenNoFile(): void
    {
        $haxcms = $this->makeHaxcms();
        $this->assertSame(array(), HAXCMSSkeletonSettingsService::readEnabledSkeletonMap($haxcms));
    }

    public function testSkeletonWriteAndReadRoundTrip(): void
    {
        $haxcms = $this->makeHaxcms();
        $input = array('my-skeleton' => true, 'off-skeleton' => false, 'Big Skeleton' => true);
        $written = HAXCMSSkeletonSettingsService::writeEnabledSkeletonMap($haxcms, $input);
        $this->assertArrayHasKey('my-skeleton', $written);
        $this->assertArrayHasKey('off-skeleton', $written);
        $this->assertArrayHasKey('big-skeleton', $written);
        $this->assertTrue($written['my-skeleton']);
        $this->assertFalse($written['off-skeleton']);
        $read = HAXCMSSkeletonSettingsService::readEnabledSkeletonMap($haxcms);
        $this->assertSame($written, $read);
    }

    public function testSkeletonWritePersistsUnderSettingsDir(): void
    {
        $haxcms = $this->makeHaxcms();
        HAXCMSSkeletonSettingsService::writeEnabledSkeletonMap($haxcms, array('alpha' => true));
        $filePath = $this->tmpRoot . '/settings/enabledSkeletons.json';
        $this->assertFileExists($filePath);
        $persisted = json_decode(file_get_contents($filePath), true);
        $this->assertArrayHasKey('enabledSkeletons', $persisted);
        $this->assertTrue($persisted['enabledSkeletons']['alpha']);
    }

    public function testSkeletonNormalizeFromListInput(): void
    {
        $haxcms = $this->makeHaxcms();
        $map = HAXCMSSkeletonSettingsService::normalizeEnabledSkeletonMap($haxcms, array('Course Skeleton', 'blog'));
        $this->assertSame(array('course-skeleton' => true, 'blog' => true), $map);
    }

    public static function skeletonIsEnabledProvider(): array
    {
        return array(
            'empty machine name => true' => array('', array(), true),
            'not in map => true' => array('unknown', array('other' => true), true),
            'in map true => true' => array('my-skel', array('my-skel' => true), true),
            'in map false => false' => array('my-skel', array('my-skel' => false), false),
        );
    }

    #[DataProvider('skeletonIsEnabledProvider')]
    public function testSkeletonIsSkeletonEnabled(string $machineName, array $enabledSkeletons, bool $expected): void
    {
        $haxcms = $this->makeHaxcms();
        $this->assertSame($expected, HAXCMSSkeletonSettingsService::isSkeletonEnabled($haxcms, $machineName, $enabledSkeletons));
    }

    // ====================================================================
    // HAXCMSSystemStatusService (lib/SystemStatusService.php)
    // ====================================================================

    public function testSystemStatusBuildStatusReportSummaryFromOptions(): void
    {
        // Pass full options so no network/exec/ini path is hit.
        $report = HAXCMSSystemStatusService::buildStatusReport(array(
            'programmingLanguage' => 'php8.3',
            'serverVersion' => 'apache-2.4',
            'haxcmsVersionCurrent' => '1.2.3',
            'haxcmsVersionLatest' => '1.2.3',
            'configDirectory' => '/some/config',
            'phpMemoryLimit' => '512M',
            'uploadLimit' => 'upload_max_filesize=10M',
            'gitVersion' => 'git version 2.40.0',
            'directories' => array(),
        ));
        $this->assertSame('php8.3', $report['summary']['programmingLanguage']);
        $this->assertSame('apache-2.4', $report['summary']['serverVersion']);
        $this->assertSame('1.2.3', $report['summary']['haxcmsVersionCurrent']);
        $this->assertSame('1.2.3', $report['summary']['haxcmsVersionLatest']);
        $this->assertSame('/some/config', $report['summary']['configDirectory']);
    }

    public function testSystemStatusBuildStatusReportStripsLeadingVFromVersion(): void
    {
        // normalizeVersion (private) strips a leading 'v' — exercised via the
        // public buildStatusReport summary seam.
        $report = HAXCMSSystemStatusService::buildStatusReport(array(
            'haxcmsVersionCurrent' => 'v2.0.1',
            'haxcmsVersionLatest' => 'v2.0.1',
            'gitVersion' => 'git version 1.0',
        ));
        $this->assertSame('2.0.1', $report['summary']['haxcmsVersionCurrent']);
        $this->assertSame('2.0.1', $report['summary']['haxcmsVersionLatest']);
    }

    public function testSystemStatusBuildStatusReportDefaultsLatestToCurrentWhenUnknown(): void
    {
        $report = HAXCMSSystemStatusService::buildStatusReport(array(
            'haxcmsVersionCurrent' => '1.0.0',
            'haxcmsVersionLatest' => '',
            'gitVersion' => 'git version 1.0',
        ));
        $this->assertSame('1.0.0', $report['summary']['haxcmsVersionCurrent']);
        // empty latest => falls back to current
        $this->assertSame('1.0.0', $report['summary']['haxcmsVersionLatest']);
    }

    public function testSystemStatusVersionRowToneOkWhenCurrentEqualsLatest(): void
    {
        $report = HAXCMSSystemStatusService::buildStatusReport(array(
            'haxcmsVersionCurrent' => '1.0.0',
            'haxcmsVersionLatest' => '1.0.0',
            'gitVersion' => 'git version 1.0',
        ));
        $versionRow = $this->findRowByKey($report['rows'], 'haxcms-version');
        $this->assertNotNull($versionRow);
        $this->assertSame('ok', $versionRow['tone']);
        $this->assertSame('1.0.0', $versionRow['value']);
    }

    public function testSystemStatusVersionRowToneWarningWhenOutOfDate(): void
    {
        $report = HAXCMSSystemStatusService::buildStatusReport(array(
            'haxcmsVersionCurrent' => '1.0.0',
            'haxcmsVersionLatest' => '2.0.0',
            'gitVersion' => 'git version 1.0',
        ));
        $versionRow = $this->findRowByKey($report['rows'], 'haxcms-version');
        $this->assertNotNull($versionRow);
        $this->assertSame('warning', $versionRow['tone']);
        $this->assertStringContainsString('Update:', $versionRow['description']);
    }

    public function testSystemStatusDirectoryRowOkForWritableDir(): void
    {
        $writableDir = $this->tmpRoot . '/writable';
        mkdir($writableDir, 0777, true);
        $report = HAXCMSSystemStatusService::buildStatusReport(array(
            'gitVersion' => 'git version 1.0',
            'directories' => array(
                array('key' => 'test-dir', 'title' => 'Test dir', 'path' => $writableDir, 'required' => true),
            ),
        ));
        $row = $this->findRowByKey($report['rows'], 'test-dir');
        $this->assertNotNull($row);
        $this->assertSame('Writable', $row['value']);
        // tone is 'ok' when writable + owner matches process (which it does,
        // since the test process created the dir). owner-mismatch => 'warning'.
        $this->assertContains($row['tone'], array('ok', 'warning'));
    }

    public function testSystemStatusDirectoryRowErrorForMissingRequiredDir(): void
    {
        $missingDir = $this->tmpRoot . '/does-not-exist';
        $report = HAXCMSSystemStatusService::buildStatusReport(array(
            'gitVersion' => 'git version 1.0',
            'directories' => array(
                array('key' => 'missing-dir', 'title' => 'Missing', 'path' => $missingDir, 'required' => true),
            ),
        ));
        $row = $this->findRowByKey($report['rows'], 'missing-dir');
        $this->assertNotNull($row);
        $this->assertSame('error', $row['tone']);
        $this->assertSame('Missing', $row['value']);
    }

    public function testSystemStatusDirectoryRowWarningForMissingOptionalDir(): void
    {
        $missingDir = $this->tmpRoot . '/does-not-exist';
        $report = HAXCMSSystemStatusService::buildStatusReport(array(
            'gitVersion' => 'git version 1.0',
            'directories' => array(
                array('key' => 'opt-dir', 'title' => 'Optional', 'path' => $missingDir, 'required' => false),
            ),
        ));
        $row = $this->findRowByKey($report['rows'], 'opt-dir');
        $this->assertNotNull($row);
        $this->assertSame('warning', $row['tone']);
        $this->assertSame('Missing', $row['value']);
    }

    public function testSystemStatusDirectoryRowInvalidWhenPathIsFile(): void
    {
        $filePath = $this->tmpRoot . '/a-file';
        file_put_contents($filePath, 'x');
        $report = HAXCMSSystemStatusService::buildStatusReport(array(
            'gitVersion' => 'git version 1.0',
            'directories' => array(
                array('key' => 'file-path', 'title' => 'File not dir', 'path' => $filePath, 'required' => true),
            ),
        ));
        $row = $this->findRowByKey($report['rows'], 'file-path');
        $this->assertNotNull($row);
        $this->assertSame('error', $row['tone']);
        $this->assertSame('Invalid', $row['value']);
    }

    public function testSystemStatusSecuritySecretsRowToneReflectsFlag(): void
    {
        $okReport = HAXCMSSystemStatusService::buildStatusReport(array(
            'gitVersion' => 'git version 1.0',
            'securitySecretsLoaded' => true,
        ));
        $okRow = $this->findRowByKey($okReport['rows'], 'security-secrets');
        $this->assertNotNull($okRow);
        $this->assertSame('ok', $okRow['tone']);
        $this->assertSame('Loaded', $okRow['value']);

        $badReport = HAXCMSSystemStatusService::buildStatusReport(array(
            'gitVersion' => 'git version 1.0',
            'securitySecretsLoaded' => false,
        ));
        $badRow = $this->findRowByKey($badReport['rows'], 'security-secrets');
        $this->assertNotNull($badRow);
        $this->assertSame('error', $badRow['tone']);
        $this->assertSame('Missing', $badRow['value']);
    }

    public function testSystemStatusJwtRowToneReflectsFlag(): void
    {
        $onReport = HAXCMSSystemStatusService::buildStatusReport(array(
            'gitVersion' => 'git version 1.0',
            'jwtChecksEnabled' => true,
        ));
        $onRow = $this->findRowByKey($onReport['rows'], 'jwt-security');
        $this->assertNotNull($onRow);
        $this->assertSame('ok', $onRow['tone']);
        $this->assertSame('Enabled', $onRow['value']);

        $offReport = HAXCMSSystemStatusService::buildStatusReport(array(
            'gitVersion' => 'git version 1.0',
            'jwtChecksEnabled' => false,
        ));
        $offRow = $this->findRowByKey($offReport['rows'], 'jwt-security');
        $this->assertNotNull($offRow);
        $this->assertSame('warning', $offRow['tone']);
        $this->assertSame('Disabled', $offRow['value']);
    }

    public function testSystemStatusCoreRowKeysPresent(): void
    {
        $report = HAXCMSSystemStatusService::buildStatusReport(array(
            'gitVersion' => 'git version 1.0',
        ));
        $keys = array();
        foreach ($report['rows'] as $row) {
            $keys[] = $row['key'];
        }
        $this->assertContains('runtime', $keys);
        $this->assertContains('server', $keys);
        $this->assertContains('config-directory-path', $keys);
        $this->assertContains('php-memory-limit', $keys);
        $this->assertContains('file-upload-limit', $keys);
        $this->assertContains('php-curl', $keys);
        $this->assertContains('git-installed', $keys);
        $this->assertContains('installation-state', $keys);
        $this->assertContains('haxcms-version', $keys);
        $this->assertContains('community-support', $keys);
    }

    /**
     * buildHAXCMSStatusReport / buildInstallerStatusReport both call
     * fetchLatestReleaseVersionFromGitHub() which hits the GitHub API over the
     * network. They are not pure/config methods, so we skip them rather than
     * make a live HTTP call in the unit suite.
     */
    public function testSystemStatusBuildHaxcmsStatusReportRequiresNetwork(): void
    {
        $this->markTestSkipped('buildHAXCMSStatusReport hits the GitHub releases API (network) — skipped');
    }

    public function testSystemStatusBuildInstallerStatusReportRequiresNetwork(): void
    {
        $this->markTestSkipped('buildInstallerStatusReport hits the GitHub releases API (network) — skipped');
    }

    private function findRowByKey(array $rows, string $key): ?array
    {
        foreach ($rows as $row) {
            if (isset($row['key']) && $row['key'] === $key) {
                return $row;
            }
        }
        return null;
    }

    // ====================================================================
    // HAXCMSReportHelpers (lib/ReportHelpers.php)
    // ====================================================================

    public static function reportGetSiteNameProvider(): array
    {
        return array(
            'nested site.name' => array(array('site' => array('name' => 'my-site')), 'my-site'),
            'nested site.name trimmed' => array(array('site' => array('name' => '  spaced  ')), 'spaced'),
            'top-level siteName' => array(array('siteName' => 'alt-site'), 'alt-site'),
            'siteName trimmed' => array(array('siteName' => '  pad  '), 'pad'),
            'nested site.name wins over siteName' => array(array('site' => array('name' => 'nested'), 'siteName' => 'top'), 'nested'),
            'missing both => empty' => array(array(), ''),
            'site present but no name key => falls to siteName' => array(array('site' => array(), 'siteName' => 'fallback'), 'fallback'),
        );
    }

    #[DataProvider('reportGetSiteNameProvider')]
    public function testReportHelpersGetSiteName(array $params, string $expected): void
    {
        $this->assertSame($expected, HAXCMSReportHelpers::getSiteName($params));
    }

    public static function reportNormalizeActiveIdProvider(): array
    {
        return array(
            'missing key => null' => array(array(), null),
            'null value => null' => array(array('activeId' => null), null),
            'empty string => null' => array(array('activeId' => ''), null),
            'string null => null' => array(array('activeId' => 'null'), null),
            'whitespace-only => null' => array(array('activeId' => '   '), null),
            'valid id trimmed' => array(array('activeId' => '  item-1  '), 'item-1'),
            'valid id unchanged' => array(array('activeId' => 'page-2'), 'page-2'),
        );
    }

    #[DataProvider('reportNormalizeActiveIdProvider')]
    public function testReportHelpersNormalizeActiveId(array $params, mixed $expected): void
    {
        $this->assertSame($expected, HAXCMSReportHelpers::normalizeActiveId($params));
    }

    public function testReportHelpersValidateSiteTokenFalseWhenTokenMissing(): void
    {
        // No $GLOBALS['HAXCMS'] needed — the missing-token short-circuit fires first.
        $this->assertFalse(HAXCMSReportHelpers::validateSiteToken(array(), 'my-site'));
    }

    public function testReportHelpersValidateSiteTokenFalseWhenSiteNameEmpty(): void
    {
        $this->assertFalse(HAXCMSReportHelpers::validateSiteToken(array('site_token' => 'tok'), ''));
    }

    public function testReportHelpersValidateSiteTokenTrueWhenMockAccepts(): void
    {
        $haxcms = $this->makeHaxcms();
        $haxcms->validRequestToken = true;
        $haxcms->activeUserName = 'alice';
        $GLOBALS['HAXCMS'] = $haxcms;
        $this->assertTrue(HAXCMSReportHelpers::validateSiteToken(array('site_token' => 'tok'), 'my-site'));
    }

    public function testReportHelpersValidateSiteTokenFalseWhenMockRejects(): void
    {
        $haxcms = $this->makeHaxcms();
        $haxcms->validRequestToken = false;
        $haxcms->activeUserName = 'alice';
        $GLOBALS['HAXCMS'] = $haxcms;
        $this->assertFalse(HAXCMSReportHelpers::validateSiteToken(array('site_token' => 'tok'), 'my-site'));
    }

    public function testReportHelpersBuildSummaryDataEmptySiteShape(): void
    {
        // Contract: with no manifest/items, all counts are zero, pages=0,
        // readTime=0, readability null, title empty, updatedItems empty.
        $site = new stdClass();
        $data = HAXCMSReportHelpers::buildSummaryData($site, array());
        $this->assertSame(0, $data['pages']);
        $this->assertSame(0, $data['objectives']);
        $this->assertSame(0, $data['authorNotes']);
        $this->assertSame(0, $data['specialTags']);
        $this->assertSame(0, $data['dataTables']);
        $this->assertSame(0, $data['headings']);
        $this->assertSame(0, $data['video']);
        $this->assertSame(0, $data['videoLength']);
        $this->assertSame(0, $data['h5p']);
        $this->assertSame(0, $data['audio']);
        $this->assertSame(0, $data['links']);
        $this->assertSame(0, $data['readTime']);
        $this->assertNull($data['readability']);
        $this->assertSame(array(), $data['updatedItems']);
        $this->assertSame('', $data['title']);
        $this->assertIsString($data['created']);
        $this->assertIsString($data['updated']);
    }

    public function testReportHelpersBuildLinkDataEmptySite(): void
    {
        $site = new stdClass();
        $this->assertSame(array('linkData' => array()), HAXCMSReportHelpers::buildLinkData($site, array()));
    }

    public function testReportHelpersBuildContentDataEmptySite(): void
    {
        $site = new stdClass();
        $this->assertSame(array('contentData' => array()), HAXCMSReportHelpers::buildContentData($site, array()));
    }

    public function testReportHelpersBuildMediaDataEmptySite(): void
    {
        $site = new stdClass();
        $this->assertSame(array('mediaData' => array()), HAXCMSReportHelpers::buildMediaData($site, array()));
    }

    // ====================================================================
    // HAXAppStoreService (lib/HAXAppStoreService.php)
    // ====================================================================

    public function testAppStoreLoadBaseAppStoreWithNoApiKeysReturnsFiveAlwaysOn(): void
    {
        $service = new HAXAppStoreService();
        $apps = $service->loadBaseAppStore(array());
        // Always-on providers: nasa, sketchfab, dailymotion, cc-mixter, wikipedia
        $this->assertCount(5, $apps);
        $titles = array();
        foreach ($apps as $app) {
            if (isset($app->details->title)) {
                $titles[] = $app->details->title;
            }
        }
        $this->assertContains('NASA', $titles);
        $this->assertContains('Sketchfab', $titles);
        $this->assertContains('Dailymotion', $titles);
        $this->assertContains('CC Mixter', $titles);
        $this->assertContains('Wikipedia', $titles);
    }

    public function testAppStoreLoadBaseAppStoreWithAllKeysReturnsTen(): void
    {
        $service = new HAXAppStoreService();
        $apps = $service->loadBaseAppStore(array(
            'youtube' => 'k1', 'vimeo' => 'k2', 'giphy' => 'k3',
            'unsplash' => 'k4', 'flickr' => 'k5',
        ));
        $this->assertCount(10, $apps);
    }

    public function testAppStoreLoadBaseAppStoreEmbedsApiKeyInConnection(): void
    {
        $service = new HAXAppStoreService();
        $apps = $service->loadBaseAppStore(array('youtube' => 'my-yt-secret'));
        $yt = $this->findAppByTitle($apps, 'Youtube');
        $this->assertNotNull($yt);
        $this->assertSame('my-yt-secret', $yt->connection->data->key);
    }

    public function testAppStoreLoadBaseAppStoreConnectionUrls(): void
    {
        // Independent source of truth: the connection.url literals from the
        // provider definitions in the file (read, not copied from code logic).
        $service = new HAXAppStoreService();
        $apps = $service->loadBaseAppStore(array(
            'youtube' => 'k', 'vimeo' => 'k', 'giphy' => 'k',
            'unsplash' => 'k', 'flickr' => 'k',
        ));
        $expected = array(
            'Youtube' => 'www.googleapis.com/youtube/v3',
            'Vimeo' => 'api.vimeo.com',
            'Giphy' => 'api.giphy.com',
            'Unsplash' => 'api.unsplash.com',
            'Flickr' => 'api.flickr.com',
            'NASA' => 'images-api.nasa.gov',
            'Sketchfab' => 'api.sketchfab.com',
            'Dailymotion' => 'api.dailymotion.com',
            'CC Mixter' => 'ccmixter.org',
            'Wikipedia' => 'en.wikipedia.org',
        );
        foreach ($expected as $title => $url) {
            $app = $this->findAppByTitle($apps, $title);
            $this->assertNotNull($app, 'Missing app with title ' . $title);
            $this->assertSame($url, $app->connection->url);
        }
    }

    public function testAppStoreLoadBaseStaxReturnsNonEmpty(): void
    {
        $service = new HAXAppStoreService();
        $stax = $service->loadBaseStax();
        $this->assertIsArray($stax);
        $this->assertNotEmpty($stax);
        $first = $stax[0];
        $this->assertTrue(isset($first->details));
        $this->assertTrue(isset($first->stax));
    }

    public function testAppStoreBaseSupportedAppsReturnsFiveKeyedApps(): void
    {
        $service = new HAXAppStoreService();
        $apps = $service->baseSupportedApps();
        $this->assertSame(array('youtube', 'vimeo', 'giphy', 'unsplash', 'flickr'), array_keys($apps));
        $this->assertSame('YouTube', $apps['youtube']['name']);
        $this->assertSame('Vimeo', $apps['vimeo']['name']);
        // each entry has name + docs
        foreach ($apps as $key => $entry) {
            $this->assertArrayHasKey('name', $entry);
            $this->assertArrayHasKey('docs', $entry);
        }
    }

    private function findAppByTitle(array $apps, string $title): ?stdClass
    {
        foreach ($apps as $app) {
            if (isset($app->details->title) && $app->details->title === $title) {
                return $app;
            }
        }
        return null;
    }

    // ====================================================================
    // SsrfGuard (lib/SsrfGuard.php)
    // ====================================================================

    public static function ssrfIpProvider(): array
    {
        // Independent source of truth: the SSRF spec reject set (RFC 1918,
        // loopback, link-local, metadata, ULA, IPv6 loopback/unspecified) vs
        // public allow set. Derived from the address-class contract, not the
        // implementation's string-prefix checks.
        return array(
            // --- reject (private/reserved/loopback/link-local/metadata) ---
            'loopback 127.0.0.1' => array('127.0.0.1', true),
            'private 10.x' => array('10.0.0.1', true),
            'private 192.168.x' => array('192.168.1.1', true),
            'private 172.16 start' => array('172.16.0.1', true),
            'private 172.31 end' => array('172.31.255.255', true),
            'link-local 169.254' => array('169.254.169.254', true),
            'unspecified 0.0.0.0' => array('0.0.0.0', true),
            'ipv6 loopback ::1' => array('::1', true),
            'ipv6 unspecified ::' => array('::', true),
            'ipv6 loopback long form' => array('0:0:0:0:0:0:0:1', true),
            'ipv6 ULA fc00' => array('fc00::1', true),
            'ipv6 ULA fd00' => array('fd00::1', true),
            'ipv6 link-local fe80' => array('fe80::1', true),
            'ipv4-mapped loopback' => array('::ffff:127.0.0.1', true),
            'ipv4-mapped private 10' => array('::ffff:10.0.0.1', true),
            'ipv4-mapped metadata' => array('::ffff:169.254.169.254', true),
            'empty string' => array('', true),
            'null' => array(null, true),
            // --- allow (public) ---
            'public 8.8.8.8' => array('8.8.8.8', false),
            'public 1.1.1.1' => array('1.1.1.1', false),
            'public 93.184.216.34' => array('93.184.216.34', false),
            '172.15 below private B range' => array('172.15.0.1', false),
            '172.32 above private B range' => array('172.32.0.1', false),
            'public ipv6 google' => array('2001:4860:4860::8888', false),
            'ipv4-mapped public' => array('::ffff:8.8.8.8', false),
        );
    }

    #[DataProvider('ssrfIpProvider')]
    public function testSsrfGuardIsPrivateOrReservedIP(mixed $ip, bool $expected): void
    {
        $this->assertSame($expected, SsrfGuard::isPrivateOrReservedIP($ip));
    }

    public static function ssrfUrlRejectProvider(): array
    {
        return array(
            'loopback literal' => array('http://127.0.0.1/', 'SSRF_PRIVATE'),
            'private 10 literal' => array('http://10.0.0.1/', 'SSRF_PRIVATE'),
            'metadata literal' => array('http://169.254.169.254/', 'SSRF_PRIVATE'),
            'ipv6 loopback literal' => array('http://[::1]/', 'SSRF_PRIVATE'),
            'ftp scheme rejected' => array('ftp://8.8.8.8/', 'SSRF_PROTOCOL'),
            'javascript scheme no host' => array('javascript:alert(1)', 'SSRF_INVALID_URL'),
            'empty string' => array('', 'SSRF_INVALID_URL'),
            'no scheme' => array('not a url', 'SSRF_INVALID_URL'),
            'http no host' => array('http://', 'SSRF_INVALID_URL'),
        );
    }

    #[DataProvider('ssrfUrlRejectProvider')]
    public function testSsrfGuardValidateUrlNotSSRFRejects(string $url, string $expectedCode): void
    {
        try {
            SsrfGuard::validateUrlNotSSRF($url);
            $this->fail('Expected SsrfGuardException was not thrown for url: ' . $url);
        } catch (SsrfGuardException $e) {
            $this->assertSame($expectedCode, $e->ssrfCode);
        }
    }

    public function testSsrfGuardValidateUrlNotSSRFAcceptsPublicLiteralIP(): void
    {
        // Public literal-IP URL — no DNS needed (literal-IP branch validates
        // directly). Returns the parse_url array.
        $result = SsrfGuard::validateUrlNotSSRF('https://1.1.1.1/path');
        $this->assertIsArray($result);
        $this->assertSame('https', $result['scheme']);
        $this->assertSame('1.1.1.1', $result['host']);
    }

    public function testSsrfGuardValidateUrlNotSSRFAcceptsPublicHttpLiteralIP(): void
    {
        $result = SsrfGuard::validateUrlNotSSRF('http://8.8.8.8/');
        $this->assertIsArray($result);
        $this->assertSame('http', $result['scheme']);
        $this->assertSame('8.8.8.8', $result['host']);
    }

    public function testSsrfGuardValidateUrlNotSSRFAcceptsPublicIPv6Literal(): void
    {
        // Public IPv6 literal URL — surrounding brackets are stripped before
        // the literal-IP check so the address validates directly. No DNS
        // needed (literal-IP branch).
        $result = SsrfGuard::validateUrlNotSSRF('http://[2001:4860:4860::8888]/');
        $this->assertIsArray($result);
        $this->assertSame('http', $result['scheme']);
    }

    public function testSsrfGuardSafeFileGetContentsThrowsBeforeFetchOnPrivate(): void
    {
        $this->expectException(SsrfGuardException::class);
        SsrfGuard::safeFileGetContents('http://127.0.0.1/');
    }

    public function testSsrfGuardSafeCurlExecThrowsBeforeFetchOnPrivate(): void
    {
        try {
            SsrfGuard::safeCurlExec('http://10.0.0.1/');
            $this->fail('Expected SsrfGuardException was not thrown');
        } catch (SsrfGuardException $e) {
            $this->assertSame('SSRF_PRIVATE', $e->ssrfCode);
        }
    }

    public function testSsrfGuardSafeGuzzleRequestThrowsBeforeDelegateOnPrivate(): void
    {
        // The client is never contacted because validateUrlNotSSRF throws first.
        $dummyClient = new stdClass();
        try {
            SsrfGuard::safeGuzzleRequest($dummyClient, 'GET', 'http://169.254.169.254/');
            $this->fail('Expected SsrfGuardException was not thrown');
        } catch (SsrfGuardException $e) {
            $this->assertSame('SSRF_PRIVATE', $e->ssrfCode);
        }
    }
}

/**
 * Minimal HAXCMS collaborator stub for the service tests.
 *
 * Provides the collaborators that Theme/Skeleton/ReportHelpers delegate to
 * (generateMachineName, validateRequestToken, getActiveUserName) plus a
 * configurable configDirectory + config. Self-contained — does not extend or
 * depend on any class declared in another test file.
 */
class ServicesTestHaxcms
{
    public $configDirectory;
    public $config;
    public $validRequestToken = true;
    public $activeUserName = 'testuser';

    public function __construct()
    {
        $this->config = new stdClass();
    }

    /**
     * Deterministic machine-name slug used by Theme/Skeleton normalization.
     * Lowercase, replace non-alphanumerics with hyphens, collapse, trim.
     */
    public function generateMachineName($value)
    {
        $clean = strtolower(trim((string) $value));
        $clean = preg_replace('/[^a-z0-9]+/i', '-', $clean);
        $clean = preg_replace('/-+/', '-', $clean);
        $clean = trim($clean, '-');
        return $clean;
    }

    public function validateRequestToken($token, $value)
    {
        return $this->validRequestToken;
    }

    public function getActiveUserName()
    {
        return $this->activeUserName;
    }
}
