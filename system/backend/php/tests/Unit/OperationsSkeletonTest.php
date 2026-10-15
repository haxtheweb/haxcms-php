<?php
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/OperationsTestHaxcms.php';

/**
 * Characterization tests for the skeleton/template helper family on
 * Operations.php (lib/Operations.php):
 *
 *   - skeleton directory discovery + the 'haxcms-skeleton-dirs' hook
 *     (getDefaultSkeletonDirectories, getSkeletonDirectories)
 *   - skeleton lookup + trusted-payload shaping
 *     (normalizeSkeletonMachineName, resolveSkeletonBuildByMachineName,
 *     resolveSkeletonBuildByThemeMachineName, buildItemsSignature,
 *     resolveSkeletonByBuildItems)
 *   - trusted payload accessors (getTrustedSkeletonSettings,
 *     getTrustedSkeletonPlatform, getTrustedSkeletonTheme)
 *   - template generation (buildSiteTemplateSkeleton + the small
 *     normalizeTemplate* helpers and getTemplateSourceUrl)
 *
 * Private methods are invoked via reflection (same technique as
 * OperationsImageOpsTest). Filesystem fixtures live under a per-test temp
 * root; HAXCMS_ROOT is NOT touched. getTrustedSkeletonTheme exercises the
 * getThemes() registry fallback via the configurable mock.
 */
class OperationsSkeletonTest extends TestCase
{
    private $haxcms;
    private $ops;
    private $savedHaxcms;
    private $savedServerSoftware;
    private $tmpRoot;
    private $siteName = 'my-site';

    protected function setUp(): void
    {
        if (isset($GLOBALS['HAXCMS'])) {
            $this->savedHaxcms = $GLOBALS['HAXCMS'];
        }
        if (isset($_SERVER['SERVER_SOFTWARE'])) {
            $this->savedServerSoftware = $_SERVER['SERVER_SOFTWARE'];
        }
        $_SERVER['SERVER_SOFTWARE'] = 'phpunit-test';

        $this->tmpRoot = sys_get_temp_dir() . '/haxcms_ops_skeleton_' . uniqid();
        mkdir($this->tmpRoot, 0777, true);

        $this->haxcms = new OperationsSkeletonTestHaxcms();
        $this->haxcms->configDirectory = $this->tmpRoot . '/_config';
        $this->haxcms->coreConfigPath = $this->tmpRoot . '/_core/';
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
        if (isset($this->savedServerSoftware)) {
            $_SERVER['SERVER_SOFTWARE'] = $this->savedServerSoftware;
            $this->savedServerSoftware = null;
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

    private function invokePrivate(string $method, array $args = [])
    {
        $ref = new ReflectionMethod('Operations', $method);
        $ref->setAccessible(true);
        return $ref->invokeArgs($this->ops, $args);
    }

    private function userSkeletonDir(): string
    {
        return $this->tmpRoot . '/_config/user/skeletons';
    }

    private function configSkeletonDir(): string
    {
        return $this->tmpRoot . '/_config/skeletons';
    }

    private function writeSkeleton(string $dir, string $file, $contents): string
    {
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        $path = $dir . '/' . $file;
        file_put_contents(
            $path,
            is_string($contents) ? $contents : json_encode($contents)
        );
        return $path;
    }

    /**
     * The default-starter fixture shared by the machine-name and theme
     * fallback tests. Carries a _skeleton.fullThemeConfig to pin that the
     * theme resolver strips it.
     */
    private function writeDefaultStarterSkeleton(): string
    {
        return $this->writeSkeleton($this->userSkeletonDir(), 'default-starter.json', array(
            'meta' => array(
                'name' => 'Default Starter',
                'machineName' => 'default-starter',
                'type' => 'skeleton',
            ),
            'site' => array('theme' => 'haxcms-clean-two'),
            'build' => array(
                'type' => 'skeleton',
                'structure' => 'from-skeleton',
                'items' => array(
                    array('id' => 'x1', 'title' => 'One'),
                    array('id' => 'x2', 'title' => 'Two'),
                ),
                'files' => array(),
            ),
            'theme' => array('element' => 'clean-two', 'name' => 'Default Starter'),
            '_skeleton' => array(
                'fullThemeConfig' => array(
                    'element' => 'old-element',
                    'variables' => array('a' => '1'),
                ),
            ),
        ));
    }

    private function writeMachineNameVariants(): void
    {
        $this->writeSkeleton($this->configSkeletonDir(), 'other-skeleton.json', array(
            'meta' => array('name' => 'Whatever', 'machineName' => 'other-skeleton'),
            'build' => array('type' => 'skeleton', 'items' => array()),
        ));
        $this->writeSkeleton($this->configSkeletonDir(), 'meta-name-match.json', array(
            'meta' => array('name' => 'Third Skeleton'),
            'build' => null,
        ));
        $this->writeSkeleton($this->configSkeletonDir(), 'broken.json', '{invalid json');
    }

    /**
     * Real temp site fixture for buildSiteTemplateSkeleton: two pages with
     * out-of-order entries so the sort + on-disk content reads are pinned.
     */
    private function buildTemplateSiteFixture(): array
    {
        $siteDir = $this->tmpRoot . '/' . $this->siteName;
        mkdir($siteDir . '/pages/item-a', 0777, true);
        mkdir($siteDir . '/pages/item-b', 0777, true);
        file_put_contents($siteDir . '/pages/item-a/index.html', '<p>Content A</p>');
        file_put_contents($siteDir . '/pages/item-b/index.html', '<p>Content B</p>');

        $manifestJson = json_encode(array(
            'id' => 'site-uuid',
            'title' => 'Test Site',
            'author' => 'Author Name',
            'description' => 'A test site description',
            'license' => 'cc-by',
            'metadata' => array(
                'site' => array(
                    'name' => $this->siteName,
                    'settings' => array(
                        'lang' => 'fr',
                        'publishPagesOn' => false,
                        'canonical' => false,
                        'gaID' => 'UA-OLD',
                    ),
                    'created' => 1000000,
                    'updated' => 1000000,
                ),
                'theme' => array('element' => 'clean-two'),
            ),
            'items' => array(
                array(
                    'id' => 'item-a',
                    'title' => 'Item A',
                    'slug' => 'item-a',
                    'order' => 1,
                    'parent' => null,
                    'indent' => 0,
                    'location' => 'pages/item-a/index.html',
                    'metadata' => array('created' => 1, 'updated' => 2),
                ),
                array(
                    'id' => 'item-b',
                    'title' => 'Item B',
                    'slug' => 'item-b',
                    'order' => 0,
                    'parent' => null,
                    'indent' => 0,
                    'location' => 'pages/item-b/index.html',
                    'metadata' => array(
                        'created' => 3,
                        'updated' => 4,
                        'tags' => array('alpha'),
                        'published' => false,
                        'hideInMenu' => true,
                    ),
                ),
            ),
        ));
        file_put_contents($siteDir . '/site.json', $manifestJson);

        $site = new stdClass();
        $site->manifest = json_decode($manifestJson);
        $site->directory = $this->tmpRoot;
        $site->name = $this->siteName;
        return array($site, $siteDir);
    }

    // =========================================================================
    // getDefaultSkeletonDirectories
    // =========================================================================

    public function testGetDefaultSkeletonDirectoriesReturnsPrecedenceMap(): void
    {
        $result = $this->invokePrivate('getDefaultSkeletonDirectories');
        $this->assertSame(
            array(
                'user' => $this->tmpRoot . '/_config/user/skeletons',
                'config' => $this->tmpRoot . '/_config/skeletons',
                'core' => $this->tmpRoot . '/_core/skeletons',
            ),
            $result
        );
    }

    // =========================================================================
    // getSkeletonDirectories + hook
    // =========================================================================

    public function testGetSkeletonDirectoriesKeepsOnlyExistingDirsInOrder(): void
    {
        mkdir($this->userSkeletonDir(), 0777, true);
        mkdir($this->configSkeletonDir(), 0777, true);
        // core dir intentionally absent

        $result = $this->invokePrivate('getSkeletonDirectories');
        $this->assertSame(
            array($this->userSkeletonDir(), $this->configSkeletonDir()),
            $result
        );

        // hook fired exactly once with the discovery context
        $this->assertCount(1, $this->haxcms->dispatchedEvents);
        $this->assertSame('haxcms-skeleton-dirs', $this->haxcms->dispatchedEvents[0]['event']);
        $this->assertSame(
            array($this->userSkeletonDir(), $this->configSkeletonDir()),
            $this->haxcms->dispatchedEvents[0]['directories']
        );
        $this->assertCount(3, $this->haxcms->dispatchedEvents[0]['defaultDirectories']);
    }

    public function testGetSkeletonDirectoriesHookCanAppendAndReorder(): void
    {
        mkdir($this->userSkeletonDir(), 0777, true);
        $extraDir = $this->tmpRoot . '/_extra-skeletons';
        mkdir($extraDir, 0777, true);
        $this->haxcms->mutateSkeletonDirContext = true;
        $this->haxcms->extraSkeletonDirs = array($extraDir);

        $result = $this->invokePrivate('getSkeletonDirectories');
        // hook-appended dir survives (exists + is_dir) and lands last
        $this->assertSame(array($this->userSkeletonDir(), $extraDir), $result);
    }

    public function testGetSkeletonDirectoriesFiltersHookNoiseAndDedupes(): void
    {
        mkdir($this->userSkeletonDir(), 0777, true);
        $this->haxcms->mutateSkeletonDirContext = true;
        // non-existent path, non-string entry, and a duplicate of an existing
        // dir must all be dropped
        $this->haxcms->extraSkeletonDirs = array(
            $this->tmpRoot . '/_does-not-exist',
            12345,
            $this->userSkeletonDir() . '///',
        );

        $result = $this->invokePrivate('getSkeletonDirectories');
        $this->assertSame(array($this->userSkeletonDir()), $result);
    }

    // =========================================================================
    // normalizeSkeletonMachineName
    // =========================================================================

    public function testNormalizeSkeletonMachineNameStripsJsonAndCasing(): void
    {
        $this->assertSame('default-starter', $this->invokePrivate('normalizeSkeletonMachineName', array('Default-Starter.JSON')));
        $this->assertSame('foo', $this->invokePrivate('normalizeSkeletonMachineName', array('  foo  ')));
        $this->assertSame('', $this->invokePrivate('normalizeSkeletonMachineName', array(123)));
        $this->assertSame('', $this->invokePrivate('normalizeSkeletonMachineName', array(array())));
    }

    // =========================================================================
    // resolveSkeletonBuildByMachineName
    // =========================================================================

    public function testResolveSkeletonByMachineNameMatchesFileNameWithBuildPayload(): void
    {
        $expectedPath = $this->writeDefaultStarterSkeleton();

        $result = $this->invokePrivate('resolveSkeletonBuildByMachineName', array('default-starter'));
        $this->assertNotNull($result);
        $this->assertSame($expectedPath, $result['filePath']);
        $this->assertSame('default-starter', $result['skeleton']['meta']['machineName']);
        $this->assertCount(2, $result['build']['items']);
    }

    public function testResolveSkeletonByMachineNameMatchesMetaMachineNameAndMetaName(): void
    {
        $this->writeMachineNameVariants();

        // match via meta.machineName
        $byMachineName = $this->invokePrivate('resolveSkeletonBuildByMachineName', array('other-skeleton'));
        $this->assertNotNull($byMachineName);
        $this->assertStringEndsWith('other-skeleton.json', $byMachineName['filePath']);

        // match via meta.name (spaces preserved by the normalizer)
        $byMetaName = $this->invokePrivate('resolveSkeletonBuildByMachineName', array('Third Skeleton'));
        $this->assertNotNull($byMetaName);
        $this->assertStringEndsWith('meta-name-match.json', $byMetaName['filePath']);
        // build key present but null in the fixture
        $this->assertNull($byMetaName['build']);
    }

    public function testResolveSkeletonByMachineNamePrefersUserDir(): void
    {
        $this->writeSkeleton($this->userSkeletonDir(), 'dual.json', array('meta' => array('name' => 'Dual'), 'marker' => 'user'));
        $this->writeSkeleton($this->configSkeletonDir(), 'dual.json', array('meta' => array('name' => 'Dual'), 'marker' => 'config'));

        $result = $this->invokePrivate('resolveSkeletonBuildByMachineName', array('dual'));
        $this->assertSame('user', $result['skeleton']['marker']);
    }

    public function testResolveSkeletonByMachineNameSkipsInvalidJsonAndReturnsNull(): void
    {
        $this->writeMachineNameVariants();
        // 'broken' exists as a filename but its content is not valid JSON
        $this->assertNull($this->invokePrivate('resolveSkeletonBuildByMachineName', array('broken')));
        $this->assertNull($this->invokePrivate('resolveSkeletonBuildByMachineName', array('missing-thing')));
    }

    public function testResolveSkeletonByMachineNameRejectsEmptyAndNonStringTargets(): void
    {
        mkdir($this->userSkeletonDir(), 0777, true);
        $this->writeDefaultStarterSkeleton();
        $this->assertNull($this->invokePrivate('resolveSkeletonBuildByMachineName', array('')));
        $this->assertNull($this->invokePrivate('resolveSkeletonBuildByMachineName', array(null)));
    }

    // =========================================================================
    // resolveSkeletonBuildByThemeMachineName
    // =========================================================================

    public function testResolveByThemeMachineNameMatchesThemeElementAndFallsBackToDefaultStarter(): void
    {
        $this->writeDefaultStarterSkeleton();
        $this->haxcms->themes = array(
            'haxcms-clean-two' => array('element' => 'clean-two', 'name' => 'Clean Two'),
        );

        // match by the theme's element, not the registry key
        $result = $this->invokePrivate('resolveSkeletonBuildByThemeMachineName', array('clean-two'));
        $this->assertNotNull($result);
        $this->assertStringEndsWith('default-starter.json', $result['filePath']);
        $this->assertSame('haxcms-clean-two', $result['skeleton']['meta']['machineName']);
        $this->assertSame('haxcms-clean-two', $result['skeleton']['meta']['name']);
        $this->assertSame('haxcms-clean-two', $result['skeleton']['site']['theme']);
        // trusted payload strips the source skeleton's fullThemeConfig
        $this->assertArrayNotHasKey('fullThemeConfig', $result['skeleton']['_skeleton']);
        // build items survive from the default-starter skeleton
        $this->assertCount(2, $result['build']['items']);

        // the registry key itself also resolves
        $byKey = $this->invokePrivate('resolveSkeletonBuildByThemeMachineName', array('haxcms-clean-two'));
        $this->assertNotNull($byKey);
        $this->assertSame('haxcms-clean-two', $byKey['skeleton']['meta']['machineName']);
    }

    public function testResolveByThemeMachineNameSupportsObjectThemeRegistry(): void
    {
        $this->haxcms->themes = (object) array(
            'haxcms-clean-two' => (object) array('element' => 'clean-two'),
        );
        $result = $this->invokePrivate('resolveSkeletonBuildByThemeMachineName', array('clean-two'));
        $this->assertNotNull($result);
        $this->assertSame('haxcms-clean-two', $result['skeleton']['meta']['machineName']);
    }

    public function testResolveByThemeMachineNameGeneratesFallbackWhenNoSkeletonResolvable(): void
    {
        $this->haxcms->themes = array(
            'haxcms-clean-two' => array('element' => 'clean-two'),
        );
        // no skeleton directories exist at all -> generated fallback

        $result = $this->invokePrivate('resolveSkeletonBuildByThemeMachineName', array('clean-two'));
        $this->assertNotNull($result);
        $this->assertSame('generated:theme-fallback', $result['filePath']);
        $this->assertSame(
            array('type' => 'skeleton', 'structure' => 'from-skeleton', 'items' => array(), 'files' => array()),
            $result['build']
        );
        $this->assertSame('haxcms-clean-two', $result['skeleton']['meta']['machineName']);
        $this->assertSame('haxcms-clean-two', $result['skeleton']['site']['theme']);
    }

    public function testResolveByThemeMachineNameReturnsNullForUnknownThemeAndBadRegistry(): void
    {
        $this->haxcms->themes = array(
            'haxcms-clean-two' => array('element' => 'clean-two'),
        );
        $this->assertNull($this->invokePrivate('resolveSkeletonBuildByThemeMachineName', array('not-a-theme')));
        $this->assertNull($this->invokePrivate('resolveSkeletonBuildByThemeMachineName', array('')));

        // a non-array/object registry short-circuits to null
        $this->haxcms->themes = 'bogus';
        $this->assertNull($this->invokePrivate('resolveSkeletonBuildByThemeMachineName', array('clean-two')));
    }

    // =========================================================================
    // buildItemsSignature + resolveSkeletonByBuildItems
    // =========================================================================

    public function testBuildItemsSignatureFormatAndIdCap(): void
    {
        $this->assertNull($this->invokePrivate('buildItemsSignature', array('not-an-array')));
        $this->assertNull($this->invokePrivate('buildItemsSignature', array(array())));
        $this->assertNull($this->invokePrivate('buildItemsSignature', array(array(array('title' => 'no id')))));

        $this->assertSame(
            '2:a|b',
            $this->invokePrivate('buildItemsSignature', array(array(array('id' => 'a'), array('id' => 'b'))))
        );

        // count reflects ALL items, but at most 5 ids are included
        $items = array();
        foreach (range(1, 7) as $i) {
            $items[] = array('id' => 'i' . $i);
        }
        $this->assertSame('7:i1|i2|i3|i4|i5', $this->invokePrivate('buildItemsSignature', array($items)));
    }

    public function testResolveSkeletonByBuildItemsMatchesSignature(): void
    {
        $path = $this->writeSkeleton($this->userSkeletonDir(), 'by-items.json', array(
            'meta' => array('name' => 'By Items'),
            'build' => array(
                'type' => 'skeleton',
                'items' => array(
                    array('id' => 'x1', 'title' => 'One'),
                    array('id' => 'x2', 'title' => 'Two'),
                ),
            ),
        ));

        $match = $this->invokePrivate(
            'resolveSkeletonByBuildItems',
            array(array(array('id' => 'x1'), array('id' => 'x2')))
        );
        $this->assertNotNull($match);
        $this->assertSame($path, $match['filePath']);
        $this->assertCount(2, $match['build']['items']);

        // different ids -> different signature -> no match
        $miss = $this->invokePrivate('resolveSkeletonByBuildItems', array(array(array('id' => 'y1'))));
        $this->assertNull($miss);
    }

    // =========================================================================
    // getTrustedSkeletonSettings / getTrustedSkeletonPlatform
    // =========================================================================

    public function testGetTrustedSkeletonSettingsFallbackChain(): void
    {
        $this->assertSame(
            array('lang' => 'fr'),
            $this->invokePrivate('getTrustedSkeletonSettings', array(array('site' => array('settings' => array('lang' => 'fr')))))
        );
        $this->assertSame(
            array('lang' => 'de'),
            $this->invokePrivate('getTrustedSkeletonSettings', array(array('_skeleton' => array('originalSettings' => array('lang' => 'de')))))
        );
        $this->assertSame(
            array('lang' => 'es'),
            $this->invokePrivate('getTrustedSkeletonSettings', array(array('_skeleton' => array('originalMetadata' => array('site' => array('settings' => array('lang' => 'es')))))))
        );
        $this->assertNull($this->invokePrivate('getTrustedSkeletonSettings', array(array('site' => array()))));
        $this->assertNull($this->invokePrivate('getTrustedSkeletonSettings', array('not-an-array')));
    }

    public function testGetTrustedSkeletonPlatformFallbackChain(): void
    {
        $this->assertSame(
            array('audience' => 'novice'),
            $this->invokePrivate('getTrustedSkeletonPlatform', array(array('site' => array('platform' => array('audience' => 'novice')))))
        );
        $this->assertSame(
            array('audience' => 'expert'),
            $this->invokePrivate('getTrustedSkeletonPlatform', array(array('_skeleton' => array('originalMetadata' => array('platform' => array('audience' => 'expert'))))))
        );
        $this->assertNull($this->invokePrivate('getTrustedSkeletonPlatform', array(array())));
        $this->assertNull($this->invokePrivate('getTrustedSkeletonPlatform', array(null)));
    }

    // =========================================================================
    // getTrustedSkeletonTheme
    // =========================================================================

    public function testGetTrustedSkeletonThemeUsesFullThemeConfigSettings(): void
    {
        $skeleton = array(
            '_skeleton' => array(
                'fullThemeConfig' => array(
                    'element' => 'clean-two',
                    'variables' => array('--ddd-primary' => 'blue'),
                    'settings' => array('path' => 'elements/clean-two/clean-two.js', 'name' => 'Clean Two'),
                ),
            ),
        );
        $theme = $this->invokePrivate('getTrustedSkeletonTheme', array($skeleton));
        $this->assertSame('elements/clean-two/clean-two.js', $theme['path']);
        $this->assertSame('Clean Two', $theme['name']);
        $this->assertSame('clean-two', $theme['element']);
        $this->assertSame(array('--ddd-primary' => 'blue'), $theme['variables']);
    }

    public function testGetTrustedSkeletonThemeFallsBackToTopLevelThemeWhenSettingsTrimmed(): void
    {
        $skeleton = array(
            'theme' => array('path' => 'elements/clean-two/clean-two.js', 'name' => 'Clean Two'),
            '_skeleton' => array(
                // settings deliberately absent -> skeleton.theme is the base
                'fullThemeConfig' => array('element' => 'clean-two'),
            ),
        );
        $theme = $this->invokePrivate('getTrustedSkeletonTheme', array($skeleton));
        $this->assertSame('elements/clean-two/clean-two.js', $theme['path']);
        $this->assertSame('clean-two', $theme['element']);
        $this->assertSame(array(), $theme['variables']);
    }

    public function testGetTrustedSkeletonThemeFallsBackToThemesRegistryBySiteTheme(): void
    {
        $this->haxcms->themes = array(
            'clean-two' => array('path' => 'elements/clean-two/clean-two.js'),
        );
        $skeleton = array('site' => array('theme' => 'clean-two'));
        $theme = $this->invokePrivate('getTrustedSkeletonTheme', array($skeleton));
        $this->assertSame('elements/clean-two/clean-two.js', $theme['path']);
        // element inferred from the registry path basename minus .js
        $this->assertSame('clean-two', $theme['element']);
        $this->assertSame(array(), $theme['variables']);
    }

    public function testGetTrustedSkeletonThemeFallsBackToTopLevelThemeObjectAndInfersElement(): void
    {
        $skeleton = array('theme' => (object) array('path' => 'elements/my-theme/my-theme.js'));
        $theme = $this->invokePrivate('getTrustedSkeletonTheme', array($skeleton));
        $this->assertSame('elements/my-theme/my-theme.js', $theme['path']);
        $this->assertSame('my-theme', $theme['element']);
        $this->assertSame(array(), $theme['variables']);
    }

    public function testGetTrustedSkeletonThemeReturnsNullWhenNothingUsable(): void
    {
        $this->assertNull($this->invokePrivate('getTrustedSkeletonTheme', array(array('meta' => array()))));
        $this->assertNull($this->invokePrivate('getTrustedSkeletonTheme', array('bogus')));
        $this->assertNull($this->invokePrivate('getTrustedSkeletonTheme', array(array())));
    }

    // =========================================================================
    // buildSiteTemplateSkeleton + small template helpers
    // =========================================================================

    public function testBuildSiteTemplateSkeletonBuildsFullPayloadFromRealSite(): void
    {
        list($site, $siteDir) = $this->buildTemplateSiteFixture();
        $this->haxcms->themes = array(
            'clean-two' => array('element' => 'clean-two', 'name' => 'Clean Two', 'thumbnail' => 'files/thumb.jpg'),
        );

        $skeleton = $this->invokePrivate('buildSiteTemplateSkeleton', array($site));

        // meta block
        $this->assertSame('my-site', $skeleton['meta']['machineName']);
        $this->assertSame('my-site', $skeleton['meta']['name']);
        $this->assertSame('1.0.0', $skeleton['meta']['version']);
        $this->assertSame('skeleton', $skeleton['meta']['type']);
        $this->assertSame('Template based on A test site description', $skeleton['meta']['description']);
        $this->assertSame('/_sites/my-site/', $skeleton['meta']['sourceUrl']);
        $this->assertSame('Test Site', $skeleton['meta']['useCaseTitle']);
        $this->assertSame('Template based on A test site description', $skeleton['meta']['useCaseDescription']);
        $this->assertSame('files/thumb.jpg', $skeleton['meta']['useCaseImage']);
        $this->assertSame(array(), $skeleton['meta']['tags']);
        $this->assertSame(array(), $skeleton['meta']['category']);

        // site block: source values kept, instance-specific settings reset
        $this->assertSame('my-site', $skeleton['site']['name']);
        $this->assertSame('Template based on A test site description', $skeleton['site']['description']);
        $this->assertSame('clean-two', $skeleton['site']['theme']);
        $this->assertSame('fr', $skeleton['site']['settings']['lang']);
        $this->assertFalse($skeleton['site']['settings']['publishPagesOn']);
        $this->assertTrue($skeleton['site']['settings']['canonical']);
        $this->assertSame('', $skeleton['site']['settings']['gaID']);

        // build block: items sorted by order, content read from disk
        $this->assertSame('skeleton', $skeleton['build']['type']);
        $this->assertSame('from-skeleton', $skeleton['build']['structure']);
        $this->assertSame(array(), $skeleton['build']['files']);
        $this->assertCount(2, $skeleton['build']['items']);
        $this->assertSame('item-b', $skeleton['build']['items'][0]['id']);
        $this->assertSame('<p>Content B</p>', $skeleton['build']['items'][0]['content']);
        $this->assertSame('item-a', $skeleton['build']['items'][1]['id']);
        $this->assertSame('<p>Content A</p>', $skeleton['build']['items'][1]['content']);
        // metadata defaults: provided values preserved, missing ones defaulted
        $this->assertSame(array('alpha'), $skeleton['build']['items'][0]['metadata']['tags']);
        $this->assertFalse($skeleton['build']['items'][0]['metadata']['published']);
        $this->assertTrue($skeleton['build']['items'][0]['metadata']['hideInMenu']);
        $this->assertSame(array(), $skeleton['build']['items'][1]['metadata']['tags']);
        $this->assertTrue($skeleton['build']['items'][1]['metadata']['published']);
        $this->assertFalse($skeleton['build']['items'][1]['metadata']['hideInMenu']);

        // theme block comes from the themes registry entry
        $this->assertSame(
            array('element' => 'clean-two', 'name' => 'Clean Two', 'thumbnail' => 'files/thumb.jpg'),
            $skeleton['theme']
        );
        $this->assertSame(
            array('element' => 'clean-two', 'variables' => array()),
            $skeleton['_skeleton']['fullThemeConfig']
        );
    }

    public function testBuildSiteTemplateSkeletonThrowsForInvalidSite(): void
    {
        try {
            $this->invokePrivate('buildSiteTemplateSkeleton', array(null));
            $this->fail('Expected Exception for null site');
        } catch (Exception $e) {
            $this->assertSame('Invalid site requested', $e->getMessage());
        }

        try {
            $this->invokePrivate('buildSiteTemplateSkeleton', array(new stdClass()));
            $this->fail('Expected Exception for site without manifest');
        } catch (Exception $e) {
            $this->assertSame('Invalid site requested', $e->getMessage());
        }
    }

    public function testCloneTemplateArrayCrushesMixedValues(): void
    {
        $this->assertSame(array('x' => '1'), $this->invokePrivate('cloneTemplateArray', array((object) array('x' => '1'))));
        $this->assertSame(array('d'), $this->invokePrivate('cloneTemplateArray', array(false, array('d'))));
    }

    public function testNormalizeTemplateArrayValueShapesTagLists(): void
    {
        $this->assertSame(array('tag one'), $this->invokePrivate('normalizeTemplateArrayValue', array('tag one')));
        $this->assertSame(array('a', 'b'), $this->invokePrivate('normalizeTemplateArrayValue', array(array('a', 'b'))));
        $this->assertSame(array(), $this->invokePrivate('normalizeTemplateArrayValue', array('   ')));
    }

    public function testGetTemplateSourceUrlNormalizesBasePathAndSitesDir(): void
    {
        $this->haxcms->basePath = '/base';
        $this->assertSame('/base/_sites/my-site/', $this->invokePrivate('getTemplateSourceUrl', array('my-site')));
        // empty base path falls back to '/'
        $this->haxcms->basePath = '';
        $this->assertSame('/_sites/my-site/', $this->invokePrivate('getTemplateSourceUrl', array('my-site')));
    }
}

/**
 * OperationsTestHaxcms subclass for the skeleton helpers:
 *  - declares coreConfigPath (getDefaultSkeletonDirectories reads it; the
 *    base mock does not declare it, which would raise a dynamic-property
 *    deprecation)
 *  - makes the themes registry + generateMachineName configurable
 *  - records 'haxcms-skeleton-dirs' hook dispatches and can optionally
 *    mutate the hook context to simulate integrations like HAXiam
 */
class OperationsSkeletonTestHaxcms extends OperationsTestHaxcms
{
    public $coreConfigPath = '';
    public $themes = array();
    public $dispatchedEvents = array();
    public $mutateSkeletonDirContext = false;
    public $extraSkeletonDirs = array();

    public function getThemes()
    {
        return $this->themes;
    }

    public function generateMachineName($name)
    {
        $name = str_replace(chr(0), '', (string) $name);
        $name = preg_replace('/\.{2,}/', '', $name);
        $name = preg_replace('/[\\\\\/]/', '', $name);
        $name = preg_replace('/[^a-zA-Z0-9_-]+/', '-', $name);
        $name = preg_replace('/[-_]{2,}/', '-', $name);
        $name = trim($name, '-_');
        $name = strtolower($name);
        return $name === '' ? 'default' : $name;
    }

    public function dispatchEvent($event, &$value = null)
    {
        if ($event === 'haxcms-skeleton-dirs' && $this->mutateSkeletonDirContext && is_object($value)) {
            foreach ($this->extraSkeletonDirs as $extra) {
                $value->directories[] = $extra;
            }
        }
        $this->dispatchedEvents[] = array(
            'event' => $event,
            'directories' => isset($value->directories) ? $value->directories : null,
            'defaultDirectories' => isset($value->defaultDirectories) ? $value->defaultDirectories : null,
        );
        return null;
    }
}
