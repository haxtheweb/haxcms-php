<?php
use PHPUnit\Framework\TestCase;

/**
 * Coverage tests for lib/ThemeSettingsService.php (HAXCMSThemeSettingsService):
 *
 *   - normalizeBoolean full branch matrix
 *   - normalizeMachineName / normalizeMachineNameList (missing harness, object
 *     input, empty-name skipping, dedupe)
 *   - normalizeEnabledThemeMap (list/map/object/wrapped/scalar inputs)
 *   - isThemeEnabled / applyDetectedThemeDefaults / reconcileDetectedThemeMap
 *     (prune, add, visible-theme rescue)
 *   - getEnabledThemesFilePath (configDirectory + getcwd fallback)
 *   - readEnabledThemeMap (missing/empty/invalid/scalar/list/wrapped payloads)
 *   - writeEnabledThemeMap (create dirs, normalize + ksort, roundtrip,
 *     mkdir / json_encode / file_put_contents failure paths)
 *   - normalizeThemeRecord (array + object input, defaults, category,
 *     priority, screenshot fallbacks, hidden/terrible flags, palettes)
 *   - discovery: config directory themes (scopes, machineName sources, junk
 *     skipping), filesystem detection via HAXCMS_ROOT candidate paths,
 *     config-theme fallback detection + merge, undetected fallback loop,
 *     haxcms-theme-dirs event override, getcwd default directories
 *   - themesToMap (sorting, junk skipping, non-array input)
 */
class ThemeSettingsServiceCoverageTest extends TestCase
{
    private $tmpRoot;
    private $savedCwd;
    private $haxcmsLeafDirs = array();

    protected function setUp(): void
    {
        $this->savedCwd = getcwd();
        $this->tmpRoot = sys_get_temp_dir() . '/haxcms_theme_settings_' . uniqid();
        mkdir($this->tmpRoot, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach ($this->haxcmsLeafDirs as $leaf) {
            $dir = $leaf;
            $root = $this->haxcmsRoot();
            while (is_string($dir) && $dir !== $root && strpos($dir, $root) === 0) {
                if (!@rmdir($dir)) {
                    break;
                }
                $parent = dirname($dir);
                if ($parent === $dir) {
                    break;
                }
                $dir = $parent;
            }
        }
        $this->haxcmsLeafDirs = array();
        if (is_string($this->savedCwd) && is_dir($this->savedCwd)) {
            chdir($this->savedCwd);
        }
        $this->rrmdir($this->tmpRoot);
    }

    private function haxcmsRoot()
    {
        return defined('HAXCMS_ROOT') ? HAXCMS_ROOT : (sys_get_temp_dir() . '/haxcms_theme_settings_root');
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

    private function makeConfigDirs($withUser = true, $withConfig = true)
    {
        $config = $this->tmpRoot . '/_config';
        if ($withUser) {
            mkdir($config . '/user/themes', 0777, true);
        }
        if ($withConfig) {
            mkdir($config . '/themes', 0777, true);
        }
        return $config;
    }

    public function testNormalizeBooleanCoversAllBranches()
    {
        $svc = 'HAXCMSThemeSettingsService';
        $this->assertTrue($svc::normalizeBoolean(true));
        $this->assertFalse($svc::normalizeBoolean(false));
        $this->assertTrue($svc::normalizeBoolean(1));
        $this->assertFalse($svc::normalizeBoolean(0));
        $this->assertTrue($svc::normalizeBoolean(1.5));
        $this->assertFalse($svc::normalizeBoolean(0.0));
        $this->assertFalse($svc::normalizeBoolean('false'));
        $this->assertFalse($svc::normalizeBoolean(' FALSE '));
        $this->assertFalse($svc::normalizeBoolean('0'));
        $this->assertFalse($svc::normalizeBoolean('off'));
        $this->assertFalse($svc::normalizeBoolean('no'));
        $this->assertFalse($svc::normalizeBoolean('disabled'));
        $this->assertTrue($svc::normalizeBoolean('true'));
        $this->assertTrue($svc::normalizeBoolean('1'));
        $this->assertTrue($svc::normalizeBoolean(' on '));
        $this->assertTrue($svc::normalizeBoolean('yes'));
        $this->assertTrue($svc::normalizeBoolean('ENABLED'));
        $this->assertTrue($svc::normalizeBoolean('junk'));
        $this->assertFalse($svc::normalizeBoolean('junk', false));
        $this->assertTrue($svc::normalizeBoolean(null));
        $this->assertFalse($svc::normalizeBoolean(array(), false));
    }

    public function testNormalizeMachineNameHandlesMissingHarness()
    {
        $svc = 'HAXCMSThemeSettingsService';
        $this->assertSame('', $svc::normalizeMachineName(null, 'x'));
        $this->assertSame('', $svc::normalizeMachineName('string', 'x'));
        $this->assertSame('', $svc::normalizeMachineName(new ThemeSettingsNoMethodHaxcms(), 'x'));
        $fake = new ThemeSettingsFakeHaxcms();
        $this->assertSame('my-theme', $svc::normalizeMachineName($fake, 'My Theme'));
        $this->assertSame('default', $svc::normalizeMachineName($fake, ''));
    }

    public function testNormalizeMachineNameListDedupesAndSkipsEmpty()
    {
        $svc = 'HAXCMSThemeSettingsService';
        $fake = new ThemeSettingsFakeHaxcms();
        $this->assertSame(
            array('my-theme', 'other-one'),
            $svc::normalizeMachineNameList($fake, array('My Theme', 'my-theme', '___skip___', 'Other One'))
        );
        $this->assertSame(
            array('a', 'b'),
            $svc::normalizeMachineNameList($fake, (object) array('a', 'b'))
        );
        $this->assertSame(array(), $svc::normalizeMachineNameList($fake, 'nope'));
        $this->assertSame(
            array(),
            $svc::normalizeMachineNameList(new ThemeSettingsNoMethodHaxcms(), array('x'))
        );
    }

    public function testNormalizeEnabledThemeMapVariants()
    {
        $svc = 'HAXCMSThemeSettingsService';
        $fake = new ThemeSettingsFakeHaxcms();
        // sequential list input
        $this->assertSame(
            array('a' => true, 'b' => true),
            $svc::normalizeEnabledThemeMap($fake, array('a', 'b'))
        );
        // assoc map input
        $this->assertSame(
            array('a' => true, 'b' => false),
            $svc::normalizeEnabledThemeMap($fake, array('a' => 'yes', 'b' => 'no'))
        );
        // wrapped in an enabledThemes array key
        $this->assertSame(
            array('a' => true),
            $svc::normalizeEnabledThemeMap($fake, array('enabledThemes' => array('a')))
        );
        // wrapped in an object property
        $this->assertSame(
            array('a' => false),
            $svc::normalizeEnabledThemeMap($fake, (object) array('enabledThemes' => array('a' => 'off')))
        );
        // plain object map gets cast to array
        $this->assertSame(
            array('a' => true, 'b' => false),
            $svc::normalizeEnabledThemeMap($fake, (object) array('a' => 1, 'b' => 'off'))
        );
        // scalar input yields nothing
        $this->assertSame(array(), $svc::normalizeEnabledThemeMap($fake, 'junk'));
        // keys normalizing to empty machine names are skipped
        $this->assertSame(
            array('ok' => true),
            $svc::normalizeEnabledThemeMap($fake, array('___skip___' => true, 'ok' => true))
        );
    }

    public function testIsThemeEnabled()
    {
        $svc = 'HAXCMSThemeSettingsService';
        $fake = new ThemeSettingsFakeHaxcms();
        $this->assertTrue($svc::isThemeEnabled($fake, 'a', array('a' => true)));
        $this->assertFalse($svc::isThemeEnabled($fake, 'a', array('a' => 'off')));
        $this->assertTrue($svc::isThemeEnabled($fake, 'b', array('a' => false)));
        $this->assertTrue($svc::isThemeEnabled($fake, '', array('a' => false)));
        $this->assertFalse(
            $svc::isThemeEnabled($fake, 'a', (object) array('enabledThemes' => array('a' => false)))
        );
        // machine names that normalize to empty are always enabled
        $this->assertTrue($svc::isThemeEnabled($fake, '___skip___', array('a' => false)));
    }

    public function testApplyDetectedThemeDefaults()
    {
        $svc = 'HAXCMSThemeSettingsService';
        $fake = new ThemeSettingsFakeHaxcms();
        $result = $svc::applyDetectedThemeDefaults($fake, array('a' => false), array('a', 'b'));
        $this->assertTrue($result['changed']);
        $this->assertSame(array('a' => false, 'b' => true), $result['enabledThemes']);
        $result2 = $svc::applyDetectedThemeDefaults($fake, array('a' => true), array('a'));
        $this->assertFalse($result2['changed']);
        $this->assertSame(array('a' => true), $result2['enabledThemes']);
    }

    public function testReconcileDetectedThemeMapPrunesAddsAndRescues()
    {
        $svc = 'HAXCMSThemeSettingsService';
        $fake = new ThemeSettingsFakeHaxcms();
        // prune undetected keys, add missing detected keys
        $result = $svc::reconcileDetectedThemeMap($fake, array('gone' => true, 'a' => true), array('a', 'b'));
        $this->assertTrue($result['changed']);
        $this->assertSame(array('a' => true, 'b' => true), $result['enabledThemes']);
        // nothing to change
        $result2 = $svc::reconcileDetectedThemeMap($fake, array('a' => true), array('a'));
        $this->assertFalse($result2['changed']);
        // all visible themes disabled -> rescue the visible one
        $result3 = $svc::reconcileDetectedThemeMap($fake, array('a' => false, 'b' => false), array('a', 'b'), array('b'));
        $this->assertTrue($result3['changed']);
        $this->assertSame(array('a' => false, 'b' => true), $result3['enabledThemes']);
        // visible list contains an enabled theme -> no rescue
        $result4 = $svc::reconcileDetectedThemeMap($fake, array('a' => true, 'b' => false), array('a', 'b'), array('a', 'b'));
        $this->assertFalse($result4['changed']);
        $this->assertSame(array('a' => true, 'b' => false), $result4['enabledThemes']);
    }

    public function testGetEnabledThemesFilePath()
    {
        $svc = 'HAXCMSThemeSettingsService';
        $tmp = $this->tmpRoot;
        $fake = new ThemeSettingsFakeHaxcms($tmp . '/_config/');
        $this->assertSame(
            $tmp . '/_config/settings/enabledThemes.json',
            $svc::getEnabledThemesFilePath($fake)
        );
        // getcwd() fallback when configDirectory is missing/empty
        chdir($tmp);
        $this->assertSame(
            $tmp . '/_config/settings/enabledThemes.json',
            $svc::getEnabledThemesFilePath(new ThemeSettingsNoMethodHaxcms())
        );
        $this->assertSame(
            $tmp . '/_config/settings/enabledThemes.json',
            $svc::getEnabledThemesFilePath(null)
        );
    }

    public function testReadEnabledThemeMapFileVariants()
    {
        $svc = 'HAXCMSThemeSettingsService';
        $dir = $this->tmpRoot . '/_config/settings';
        mkdir($dir, 0777, true);
        $fake = new ThemeSettingsFakeHaxcms($this->tmpRoot . '/_config');
        $filePath = $dir . '/enabledThemes.json';
        // missing file
        $this->assertSame(array(), $svc::readEnabledThemeMap($fake));
        // empty + whitespace-only files
        file_put_contents($filePath, '');
        $this->assertSame(array(), $svc::readEnabledThemeMap($fake));
        file_put_contents($filePath, "  \n ");
        $this->assertSame(array(), $svc::readEnabledThemeMap($fake));
        // invalid JSON
        file_put_contents($filePath, '{not json');
        $this->assertSame(array(), $svc::readEnabledThemeMap($fake));
        // JSON scalar
        file_put_contents($filePath, '"just a string"');
        $this->assertSame(array(), $svc::readEnabledThemeMap($fake));
        // sequential list payload
        file_put_contents($filePath, json_encode(array('A Theme', 'b')));
        $this->assertSame(array('a-theme' => true, 'b' => true), $svc::readEnabledThemeMap($fake));
        // wrapped payload
        file_put_contents($filePath, json_encode(array('enabledThemes' => array('a' => false))));
        $this->assertSame(array('a' => false), $svc::readEnabledThemeMap($fake));
    }

    public function testWriteEnabledThemeMapCreatesNormalizesAndRoundtrips()
    {
        $svc = 'HAXCMSThemeSettingsService';
        $fake = new ThemeSettingsFakeHaxcms($this->tmpRoot . '/_config');
        $result = $svc::writeEnabledThemeMap($fake, array('B Theme' => 'off', 'a' => true));
        $this->assertSame(array('a' => true, 'b-theme' => false), $result);
        $filePath = $this->tmpRoot . '/_config/settings/enabledThemes.json';
        $this->assertFileExists($filePath);
        $decoded = json_decode((string) file_get_contents($filePath), true);
        $this->assertSame(array('enabledThemes' => array('a' => true, 'b-theme' => false)), $decoded);
        $this->assertSame($result, $svc::readEnabledThemeMap($fake));
    }

    public function testWriteEnabledThemeMapThrowsWhenDirectoryCannotBeCreated()
    {
        $blocker = $this->tmpRoot . '/blocker';
        file_put_contents($blocker, 'x');
        $fake = new ThemeSettingsFakeHaxcms($blocker . '/config');
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Unable to create enabledThemes settings directory');
        HAXCMSThemeSettingsService::writeEnabledThemeMap($fake, array('a' => true));
    }

    public function testWriteEnabledThemeMapThrowsWhenJsonEncodingFails()
    {
        $fake = new ThemeSettingsBrokenMachineHaxcms();
        $fake->configDirectory = $this->tmpRoot . '/_config';
        set_error_handler(function ($errno, $errstr) {
            // swallow the expected malformed-UTF-8 warning from json_encode
            return true;
        });
        $threw = null;
        try {
            HAXCMSThemeSettingsService::writeEnabledThemeMap($fake, array('a' => true));
        } catch (Exception $e) {
            $threw = $e;
        } finally {
            restore_error_handler();
        }
        $this->assertNotNull($threw);
        $this->assertSame('Unable to encode enabledThemes settings', $threw->getMessage());
    }

    public function testWriteEnabledThemeMapThrowsWhenFileCannotBeWritten()
    {
        $settingsDir = $this->tmpRoot . '/settings';
        mkdir($settingsDir, 0777, true);
        chmod($settingsDir, 0555);
        $fake = new ThemeSettingsFakeHaxcms($this->tmpRoot);
        $threw = null;
        try {
            HAXCMSThemeSettingsService::writeEnabledThemeMap($fake, array('a' => true));
        } catch (Exception $e) {
            $threw = $e;
        } finally {
            chmod($settingsDir, 0777);
        }
        $this->assertNotNull($threw);
        $this->assertSame('Unable to write enabledThemes settings', $threw->getMessage());
    }

    public function testNormalizeThemeRecordArrayInput()
    {
        $svc = 'HAXCMSThemeSettingsService';
        $fake = new ThemeSettingsFakeHaxcms();
        $theme = array(
            'element' => '  my-element  ',
            'name' => 'My Theme',
            'description' => 'A theme',
            'path' => 'my-theme/dist',
            'thumbnail' => 'thumb.png',
            'screenshot' => 'shot.png',
            'category' => array('blog', '', 42, 'portfolio'),
            'hidden' => true,
            'terrible' => false,
            'priority' => '5.5',
            'supportedPalettes' => array('gray', 'blue'),
            'extra' => 'kept',
        );
        $record = $svc::normalizeThemeRecord($fake, 'my-element', $theme, 'registry');
        $this->assertSame('my-element', $record['machineName']);
        $this->assertSame('my-element', $record['machine-name']);
        $this->assertSame('registry', $record['scope']);
        $this->assertSame('my-element', $record['element']);
        $this->assertSame('my-theme/dist', $record['path']);
        $this->assertSame('My Theme', $record['name']);
        $this->assertSame('A theme', $record['description']);
        $this->assertSame('shot.png', $record['screenshot']);
        $this->assertSame('thumb.png', $record['thumbnail']);
        $this->assertSame(array('blog', 'portfolio'), $record['category']);
        $this->assertTrue($record['hidden']);
        $this->assertFalse($record['terrible']);
        $this->assertSame(5.5, $record['priority']);
        $this->assertSame(array('gray', 'blue'), $record['supportedPalettes']);
        $this->assertSame('kept', $record['extra']);
    }

    public function testNormalizeThemeRecordObjectAndDefaults()
    {
        $svc = 'HAXCMSThemeSettingsService';
        $fake = new ThemeSettingsFakeHaxcms();
        $record = $svc::normalizeThemeRecord(
            $fake,
            'default-theme',
            (object) array('element' => 'x-el', 'thumbnail' => 'thumb.png'),
            'config'
        );
        $this->assertSame('default-theme', $record['machineName']);
        $this->assertSame('x-el', $record['element']);
        $this->assertSame('default-theme', $record['name']);
        $this->assertSame('', $record['path']);
        $this->assertSame('', $record['description']);
        $this->assertSame('thumb.png', $record['screenshot']);
        $this->assertSame('thumb.png', $record['thumbnail']);
        $this->assertSame(array(), $record['category']);
        $this->assertFalse($record['hidden']);
        $this->assertFalse($record['terrible']);
        $this->assertSame(0, $record['priority']);
        $this->assertArrayNotHasKey('supportedPalettes', $record);
        // non-string theme input falls back to an empty source
        $record2 = $svc::normalizeThemeRecord($fake, 'junk-theme', 'nope', 'registry');
        $this->assertSame('junk-theme', $record2['machineName']);
        $this->assertSame('junk-theme', $record2['element']);
        $this->assertSame(0, $record2['priority']);
        // terrible machine name prefix forces the flag
        $record3 = $svc::normalizeThemeRecord($fake, 'terrible-one', array(), 'registry');
        $this->assertTrue($record3['terrible']);
        // junk category / priority values fall back to empty defaults
        $record4 = $svc::normalizeThemeRecord($fake, 'junk-fields', array('category' => 42, 'priority' => 'junk'), 'registry');
        $this->assertSame(array(), $record4['category']);
        $this->assertSame(0, $record4['priority']);
    }

    public function testThemeFlagHelpers()
    {
        $svc = 'HAXCMSThemeSettingsService';
        $this->assertTrue($svc::isThemeHidden(array('hidden' => 'yes')));
        $this->assertFalse($svc::isThemeHidden(array()));
        $this->assertFalse($svc::isThemeHidden((object) array('hidden' => 'no')));
        $this->assertFalse($svc::isThemeTerrible(array('terrible' => false), 'nice-one'));
        $this->assertTrue($svc::isThemeTerrible(array(), 'Terrible-one'));
        $this->assertTrue($svc::isThemeTerrible((object) array('terrible' => 1)));
        // machineName falls back through the theme fields when param empty
        $this->assertTrue($svc::isThemeTerrible(array('machineName' => 'terrible-two')));
        $this->assertTrue($svc::isThemeTerrible(array('machine-name' => 'terrible-three')));
        $this->assertTrue($svc::isThemeTerrible(array('element' => 'terrible-four')));
        $this->assertTrue($svc::isThemeTerrible(array('name' => 'terrible-five')));
        $this->assertFalse($svc::isThemeTerrible(array()));
        // screenshot candidate fallbacks
        $this->assertSame('shot.png', $svc::getThemeScreenshot(array('screenshot' => ' shot.png ')));
        $this->assertSame('thumb.png', $svc::getThemeScreenshot(array('thumbnail' => 'thumb.png')));
        $this->assertSame('preview.png', $svc::getThemeScreenshot((object) array('preview' => 'preview.png')));
        $this->assertSame('', $svc::getThemeScreenshot(array()));
        $this->assertSame('', $svc::getThemeScreenshot(array('screenshot' => 42)));
    }

    public function testDiscoverThemesReadsConfigDirectoriesWithScopes()
    {
        $config = $this->makeConfigDirs();
        file_put_contents($config . '/user/themes/user-theme.json', json_encode(array('name' => 'User Theme', 'description' => 'user one')));
        file_put_contents($config . '/user/themes/broken.json', '{oops');
        file_put_contents($config . '/user/themes/skipper.json', json_encode(array('machineName' => '___skip___')));
        file_put_contents($config . '/user/themes/element-name.json', json_encode(array('element' => 'el-from-element')));
        file_put_contents($config . '/themes/config-theme.json', json_encode(array('machineName' => 'explicit-name')));
        file_put_contents($config . '/themes/notjson.txt', 'x');
        file_put_contents($config . '/themes/scalar.json', '"scalar"');
        // duplicate machineName across dirs: first dir (user) wins, config skipped
        file_put_contents($config . '/user/themes/dup-theme.json', json_encode(array('name' => 'Dup Theme')));
        file_put_contents($config . '/themes/dup-theme.json', '{}');
        $fake = new ThemeSettingsFakeHaxcms($config);
        $discovered = HAXCMSThemeSettingsService::discoverThemes($fake);
        $byName = array();
        foreach ($discovered as $record) {
            $byName[$record['machineName']] = $record;
        }
        // records appended from settings dirs always surface scope 'config'
        $this->assertSame('config', $byName['user-theme']['scope']);
        $this->assertSame('user one', $byName['user-theme']['description']);
        $this->assertSame('el-from-element', $byName['el-from-element']['element']);
        $this->assertSame('config', $byName['explicit-name']['scope']);
        $this->assertSame('explicit-name', $byName['explicit-name']['machineName']);
        // internal map keeps per-directory scopes: user dirs are 'user-config'
        $ref = new ReflectionMethod('HAXCMSThemeSettingsService', 'discoverConfigDirectoryThemes');
        $ref->setAccessible(true);
        $configThemes = $ref->invoke(null, $fake);
        $this->assertSame('user-config', $configThemes['user-theme']['scope']);
        $this->assertSame('config', $configThemes['explicit-name']['scope']);
        $this->assertSame('user-config', $configThemes['dup-theme']['scope']);
        $dupCount = 0;
        foreach ($discovered as $record) {
            if ($record['machineName'] === 'dup-theme') {
                $dupCount++;
            }
        }
        $this->assertSame(1, $dupCount);
        $this->assertArrayNotHasKey('broken', $byName);
        $this->assertArrayNotHasKey('scalar', $byName);
        $this->assertArrayNotHasKey('notjson', $byName);
        $this->assertArrayNotHasKey('___skip___', $byName);
    }

    public function testDiscoverThemesDetectsRegistryThemesOnFileSystem()
    {
        $config = $this->makeConfigDirs(false);
        $root = $this->haxcmsRoot();
        // a directory at a build candidate path satisfies file_exists()
        $leaf = $root . '/build/es6/node_modules/cov-detect-el';
        @mkdir($leaf, 0777, true);
        $this->haxcmsLeafDirs[] = $leaf;
        $fake = new ThemeSettingsFakeHaxcms($config, array(
            'cov-detect-el' => array('name' => 'Detect Me', 'path' => 'cov-detect-el'),
            'cov-ghost-el' => array('name' => 'Ghost', 'path' => 'cov-ghost-el-nowhere'),
            'pathless-el' => array('name' => 'Pathless'),
            // duplicate machine name after normalization -> second entry skipped
            'My Theme' => array('name' => 'My Theme'),
            'my-theme' => array('name' => 'My Theme Again'),
        ));
        $discovered = HAXCMSThemeSettingsService::discoverThemes($fake);
        $names = array();
        foreach ($discovered as $record) {
            $names[] = $record['machineName'];
        }
        $this->assertContains('cov-detect-el', $names);
        $this->assertContains('pathless-el', $names);
        $this->assertContains('my-theme', $names);
        // undetected path + not present in config themes -> skipped
        $this->assertNotContains('cov-ghost-el', $names);
        $this->assertSame(1, count(array_keys($names, 'my-theme', true)));
    }

    public function testDiscoverThemesMergesConfigThemeOverRegistryWhenDetectedViaConfig()
    {
        $config = $this->makeConfigDirs();
        file_put_contents(
            $config . '/themes/merged-el.json',
            json_encode(array('description' => 'From config', 'category' => 'custom'))
        );
        $fake = new ThemeSettingsFakeHaxcms($config, array(
            'merged-el' => array('name' => 'Merged', 'path' => 'merged-el-not-on-disk'),
            'solo' => array('name' => 'Solo'),
        ));
        $discovered = HAXCMSThemeSettingsService::discoverThemes($fake);
        $byName = array();
        foreach ($discovered as $record) {
            $byName[$record['machineName']] = $record;
        }
        // detected via configThemes and config payload merged over the registry one
        $this->assertSame('registry', $byName['merged-el']['scope']);
        $this->assertSame('From config', $byName['merged-el']['description']);
        $this->assertSame(array('custom'), $byName['merged-el']['category']);
        $this->assertSame('Solo', $byName['solo']['name']);
        // no duplicate config-scope record for merged-el
        $this->assertCount(2, $discovered);
    }

    public function testDiscoverThemesFallsBackToUndetectedRegistryThemes()
    {
        $config = $this->makeConfigDirs();
        file_put_contents($config . '/themes/config-only.json', json_encode(array('name' => 'Config Only')));
        $fake = new ThemeSettingsFakeHaxcms($config, array(
            'ghost-a' => array('name' => 'Ghost A', 'path' => 'ghost/a/nowhere'),
            'ghost-b' => array('name' => 'Ghost B', 'path' => 'ghost/b/nowhere'),
            // junk + int-keyed entries exercise the fallback loop variants too
            'junk' => 'not a theme',
            array('machineName' => 'listed-ghost', 'path' => 'ghost/listed/nowhere'),
            array('element' => 'element-ghost', 'path' => 'ghost/element/nowhere'),
            array('name' => 'named ghost', 'path' => 'ghost/named/nowhere'),
            array('machineName' => '___skip___'),
        ));
        $discovered = HAXCMSThemeSettingsService::discoverThemes($fake);
        $names = array();
        foreach ($discovered as $record) {
            $names[] = $record['machineName'];
        }
        // nothing detected -> fallback loop re-adds registry themes anyway
        $this->assertContains('ghost-a', $names);
        $this->assertContains('ghost-b', $names);
        // config themes still appended after the fallback
        $this->assertContains('config-only', $names);
    }

    public function testDiscoverThemesSupportsObjectAndListRegistries()
    {
        $config = $this->makeConfigDirs(false);
        $svc = 'HAXCMSThemeSettingsService';
        $fake = new ThemeSettingsFakeHaxcms($config, (object) array(
            'obj-theme' => array('name' => 'Obj Theme'),
            'junk' => 'not a theme',
        ));
        $discovered = $svc::discoverThemes($fake);
        $names = array();
        foreach ($discovered as $record) {
            $names[] = $record['machineName'];
        }
        $this->assertSame(array('obj-theme'), $names);

        // numeric-keyed list registry: machine name sources fall back to fields
        $fake2 = new ThemeSettingsFakeHaxcms($config, array(
            array('machineName' => 'listed-one', 'name' => 'Listed One'),
            array('element' => 'listed-two'),
            array('name' => 'listed three'),
            'junk-string',
        ));
        $discovered2 = $svc::discoverThemes($fake2);
        $names2 = array();
        foreach ($discovered2 as $record) {
            $names2[] = $record['machineName'];
        }
        $this->assertSame(array('listed-one', 'listed-two', 'listed-three'), $names2);
    }

    public function testDiscoverThemesUsesCwdWhenNoConfigDirectory()
    {
        $config = $this->tmpRoot . '/_config';
        mkdir($config . '/themes', 0777, true);
        file_put_contents($config . '/themes/cwd-theme.json', json_encode(array('name' => 'CWD Theme')));
        chdir($this->tmpRoot);
        $fake = new ThemeSettingsFakeHaxcms('');
        $discovered = HAXCMSThemeSettingsService::discoverThemes($fake);
        $names = array();
        foreach ($discovered as $record) {
            $names[] = $record['machineName'];
        }
        $this->assertSame(array('cwd-theme'), $names);
    }

    public function testDiscoverThemesHonorsThemeDirsEvent()
    {
        $config = $this->makeConfigDirs(false);
        $tmp = $this->tmpRoot;
        $extraDir = $tmp . '/extra-themes';
        mkdir($extraDir, 0777, true);
        file_put_contents($extraDir . '/event-theme.json', json_encode(array('name' => 'Event Theme')));
        $fake = new ThemeSettingsFakeHaxcms($config);
        $fake->eventHandlers['haxcms-theme-dirs'] = array(function (&$context) use ($extraDir, $tmp) {
            $context->directories = array(
                $extraDir,
                $extraDir, // duplicate is dropped
                '/no/such/dir', // missing dir is dropped
                42, // non-string is dropped
                '   ', // trims to empty, dropped
                $tmp . '/missing-themes', // missing dir is dropped
            );
        });
        $discovered = HAXCMSThemeSettingsService::discoverThemes($fake);
        $names = array();
        foreach ($discovered as $record) {
            $names[] = $record['machineName'];
        }
        $this->assertSame(array('event-theme'), $names);
    }

    public function testThemesToMapSortsAndSkipsJunk()
    {
        $svc = 'HAXCMSThemeSettingsService';
        $fake = new ThemeSettingsFakeHaxcms();
        $records = array(
            $svc::normalizeThemeRecord($fake, 'zeta', array(), 'registry'),
            $svc::normalizeThemeRecord($fake, 'alpha', array(), 'registry'),
            'junk',
            array('no-machine-name' => true),
            array('machineName' => ''),
        );
        $map = $svc::themesToMap($records);
        $this->assertSame(array('alpha', 'zeta'), array_keys($map));
        $this->assertSame('alpha', $map['alpha']['machineName']);
        $this->assertSame(array(), $svc::themesToMap('junk'));
    }
}

/**
 * Fake harness implementing only what ThemeSettingsService consumes.
 * '___skip___' maps to '' so empty-machine-name branches can be driven.
 */
class ThemeSettingsFakeHaxcms
{
    public $configDirectory = '';
    public $themes = array();
    public $eventHandlers = array();

    public function __construct($configDirectory = '', $themes = array())
    {
        $this->configDirectory = $configDirectory;
        $this->themes = $themes;
    }

    public function generateMachineName($name)
    {
        if ($name === '___skip___') {
            return '';
        }
        $name = str_replace(chr(0), '', $name);
        $name = urldecode($name);
        $name = preg_replace('/\.{2,}/', '', $name);
        $name = preg_replace('/[\\\\\/]/', '', $name);
        $name = preg_replace('/[^a-zA-Z0-9_-]+/', '-', $name);
        $name = preg_replace('/[-_]{2,}/', '-', $name);
        $name = trim($name, '-_');
        $name = strtolower($name);
        if ($name === '') {
            $name = 'default';
        }
        return $name;
    }

    public function getThemes()
    {
        return $this->themes;
    }

    public function dispatchEvent($event, &$value = null)
    {
        if (isset($this->eventHandlers[$event])) {
            foreach ($this->eventHandlers[$event] as $handler) {
                call_user_func_array($handler, array(&$value));
            }
        }
        return $value;
    }
}

/** Harness without generateMachineName -> normalization yields '' */
class ThemeSettingsNoMethodHaxcms
{
}

/** Harness whose generateMachineName always returns invalid UTF-8 */
class ThemeSettingsBrokenMachineHaxcms
{
    public $configDirectory = '';

    public function generateMachineName($name)
    {
        return "\xB1\x31";
    }
}
