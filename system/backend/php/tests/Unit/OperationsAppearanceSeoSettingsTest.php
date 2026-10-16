<?php
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/OperationsTestHaxcms.php';

/**
 * Characterization tests for the deep write branches of
 * saveAppearanceSettings + saveSeoSettings (lib/operations/).
 * OperationsSettingsTest pins the gates + one happy-path shape per route;
 * this file pins the rest of each route's branch tree:
 *
 * saveAppearanceSettings:
 *   - missing site name 400, invalid site 400, themeManifest 403
 *   - payload key allow-lists (site / manifest / theme) -> 400s
 *   - theme element validation (non-string, blank, unknown) and the
 *     registry lookup that replaces manifest metadata.theme
 *   - image / imageAlt / imageLink writes (+ non-string 400s)
 *   - cssVariable normalize (valid, invalid 400, null unset)
 *   - palette rules (valid, trim-empty unset, null unset, bad 400, non-string 400)
 *   - icon write (+ non-string 400), region id writes (+ invalid 400)
 *   - full save: persisted theme variables + regions, both git commits
 *   - stdClass manifest payload conversion
 *
 * saveSeoSettings:
 *   - seoManifest 403
 *   - the whole author block (license/image/name/email/socialLink/
 *     socialLink2/phone/location/website/website2) -> manifest + author
 *   - dotted manifest.author / manifest.seo form-field forms
 *   - seo.logo / domain / lang / gaID writes
 *   - boolean settings via FILTER_VALIDATE_BOOLEAN + FILTER_NULL_ON_FAILURE
 *     (private/canonical/pathauto/publishPagesOn; junk stays untouched)
 *   - round trip: manifest returned, both commits, persisted values
 *
 * Expected values are read back from the persisted site.json (independent
 * source of truth), not re-derived from the implementation.
 */
class OperationsAppearanceSeoSettingsTest extends TestCase
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

        $this->tmpRoot = sys_get_temp_dir() . '/haxcms_ops_appseo_' . uniqid();
        $this->buildSiteFixture();

        $this->haxcms = new AppearanceSeoTestHaxcms();
        $this->haxcms->configDirectory = $this->tmpRoot . '/_config';
        mkdir($this->haxcms->configDirectory, 0777, true);
        $this->haxcms->validRequestToken = true;
        $GLOBALS['HAXCMS'] = $this->haxcms;

        $site = new AppearanceSeoTestSite();
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
     * Minimal site fixture: no metadata.theme/author/settings containers so
     * the routes' container bootstrap branches run.
     */
    private function buildSiteFixture(): void
    {
        $siteDir = $this->tmpRoot . '/' . $this->siteName;
        mkdir($siteDir, 0777, true);
        $manifest = (object)array(
            'id' => 'site-uuid',
            'title' => 'Test Site',
            'author' => '',
            'description' => 'Original description',
            'license' => 'by-sa',
            'metadata' => (object)array('site' => (object)array('name' => $this->siteName)),
            'items' => array(),
        );
        file_put_contents($siteDir . '/site.json', json_encode($manifest, JSON_PRETTY_PRINT));
    }

    private function siteJsonPath(): string
    {
        return $this->tmpRoot . '/' . $this->siteName . '/site.json';
    }

    private function persisted(): stdClass
    {
        return json_decode(file_get_contents($this->siteJsonPath()));
    }

    /** Run saveAppearanceSettings with manifest.theme set to $theme. */
    private function runAppearance(array $theme): array
    {
        $this->ops->params = array(
            'site_token' => 'good',
            'site' => array('name' => $this->siteName),
            'manifest' => array('theme' => $theme),
        );
        return $this->ops->saveAppearanceSettings();
    }

    // =========================================================================
    // saveAppearanceSettings — failure slices
    // =========================================================================

    public function testAppearanceMissingSiteNameFailsWith400(): void
    {
        $this->ops->params = array('site_token' => 'good');
        $result = $this->ops->saveAppearanceSettings();
        $this->assertSame(400, $result['__failed']['status']);
        $this->assertSame('missing site name', $result['__failed']['message']);
    }

    public function testAppearanceInvalidSiteFailsWith400(): void
    {
        $this->haxcms->loadedSite = null;
        $result = $this->runAppearance(array());
        $this->assertSame(400, $result['__failed']['status']);
        $this->assertSame('invalid site', $result['__failed']['message']);
    }

    public function testAppearanceRefusedWhenThemeManifestDisabled(): void
    {
        $site = $this->haxcms->loadedSite;
        $site->manifest->metadata->platform = (object)array(
            'features' => (object)array('themeManifest' => false),
        );
        $result = $this->runAppearance(array());
        $this->assertSame(403, $result['__failed']['status']);
        $this->assertSame('Theme settings are disabled for this site', $result['__failed']['message']);
    }

    public function testAppearanceExtraSiteKeyFailsWith400(): void
    {
        $this->ops->params = array(
            'site_token' => 'good',
            'site' => array('name' => $this->siteName, 'extra' => 'nope'),
            'manifest' => array('theme' => array()),
        );
        $result = $this->ops->saveAppearanceSettings();
        $this->assertSame(400, $result['__failed']['status']);
        $this->assertSame('invalid site payload', $result['__failed']['message']);
    }

    public function testAppearanceExtraManifestKeyFailsWith400(): void
    {
        $this->ops->params = array(
            'site_token' => 'good',
            'site' => array('name' => $this->siteName),
            'manifest' => array('bad' => array()),
        );
        $result = $this->ops->saveAppearanceSettings();
        $this->assertSame(400, $result['__failed']['status']);
        $this->assertSame('invalid manifest payload', $result['__failed']['message']);
    }

    public function testAppearanceDisallowedThemeKeyFailsWith400(): void
    {
        $result = $this->runAppearance(array('bad-key' => 'x'));
        $this->assertSame(400, $result['__failed']['status']);
        $this->assertSame('invalid appearance payload', $result['__failed']['message']);
    }

    // =========================================================================
    // saveAppearanceSettings — theme element validation + registry
    // =========================================================================

    public function testAppearanceNonStringThemeElementFailsWith400(): void
    {
        $result = $this->runAppearance(array('manifest-metadata-theme-element' => 42));
        $this->assertSame(400, $result['__failed']['status']);
        $this->assertSame('invalid theme element', $result['__failed']['message']);
    }

    public function testAppearanceBlankThemeElementFailsWith400(): void
    {
        $result = $this->runAppearance(array('manifest-metadata-theme-element' => '   '));
        $this->assertSame(400, $result['__failed']['status']);
        $this->assertSame('invalid theme element', $result['__failed']['message']);
    }

    public function testAppearanceUnknownThemeElementFailsWith400(): void
    {
        $this->haxcms->themes = array();
        $result = $this->runAppearance(array('manifest-metadata-theme-element' => 'ghost-theme'));
        $this->assertSame(400, $result['__failed']['status']);
        $this->assertSame('invalid theme element', $result['__failed']['message']);
    }

    public function testAppearanceThemeElementReplacesManifestThemeFromRegistry(): void
    {
        $this->haxcms->themes = array(
            'my-theme' => (object)array(
                'element' => 'my-theme',
                'variables' => (object)array('hexCode' => '#123456'),
            ),
        );
        $result = $this->runAppearance(array('manifest-metadata-theme-element' => 'my-theme'));
        $this->assertSame(200, $result['status']);
        // registry entry replaced the manifest theme metadata
        $theme = $this->persisted()->metadata->theme;
        $this->assertSame('my-theme', $theme->element);
        $this->assertSame('#123456', $theme->variables->hexCode);
    }

    // =========================================================================
    // saveAppearanceSettings — variable writes + normalization
    // =========================================================================

    public function testAppearanceImageFieldsWriteAndRejectNonStrings(): void
    {
        $result = $this->runAppearance(array(
            'manifest-metadata-theme-variables-image' => 'files/banner.png',
            'manifest-metadata-theme-variables-imageAlt' => 'A banner',
            'manifest-metadata-theme-variables-imageLink' => 'https://example.com/',
        ));
        $this->assertSame(200, $result['status']);
        $vars = $this->persisted()->metadata->theme->variables;
        $this->assertSame('files/banner.png', $vars->image);
        $this->assertSame('A banner', $vars->imageAlt);
        $this->assertSame('https://example.com/', $vars->imageLink);

        // non-string values are refused per field
        $bad = $this->runAppearance(array('manifest-metadata-theme-variables-image' => array('nope')));
        $this->assertSame(400, $bad['__failed']['status']);
        $this->assertSame('invalid image value', $bad['__failed']['message']);
    }

    public function testAppearanceCssVariableNormalizesAndValidates(): void
    {
        $result = $this->runAppearance(array(
            'manifest-metadata-theme-variables-cssVariable' => 'light-blue',
        ));
        $this->assertSame(200, $result['status']);
        $this->assertSame(
            '--simple-colors-default-theme-light-blue-7',
            $this->persisted()->metadata->theme->variables->cssVariable
        );

        // junk values are refused
        $bad = $this->runAppearance(array(
            'manifest-metadata-theme-variables-cssVariable' => 'Bad Value!',
        ));
        $this->assertSame(400, $bad['__failed']['status']);
        $this->assertSame('invalid cssVariable value', $bad['__failed']['message']);

        // null unsets a previously stored value
        $this->runAppearance(array('manifest-metadata-theme-variables-cssVariable' => 'light-blue'));
        $cleared = $this->runAppearance(array(
            'manifest-metadata-theme-variables-cssVariable' => null,
        ));
        $this->assertSame(200, $cleared['status']);
        $this->assertFalse(isset($this->persisted()->metadata->theme->variables->cssVariable));
    }

    public function testAppearancePaletteRules(): void
    {
        // valid slug writes lowercase
        $result = $this->runAppearance(array(
            'manifest-metadata-theme-variables-palette' => 'Cool-Thing',
        ));
        $this->assertSame(200, $result['status']);
        $this->assertSame('cool-thing', $this->persisted()->metadata->theme->variables->palette);

        // non-string refused
        $bad = $this->runAppearance(array(
            'manifest-metadata-theme-variables-palette' => array('x'),
        ));
        $this->assertSame(400, $bad['__failed']['status']);
        $this->assertSame('invalid palette value', $bad['__failed']['message']);

        // spaces make the slug invalid
        $bad2 = $this->runAppearance(array(
            'manifest-metadata-theme-variables-palette' => 'has space',
        ));
        $this->assertSame(400, $bad2['__failed']['status']);
        $this->assertSame('invalid palette value', $bad2['__failed']['message']);

        // trim-empty and null both unset a previously stored value
        $this->runAppearance(array('manifest-metadata-theme-variables-palette' => 'cool-thing'));
        $this->runAppearance(array('manifest-metadata-theme-variables-palette' => '   '));
        $this->assertFalse(isset($this->persisted()->metadata->theme->variables->palette));

        $this->runAppearance(array('manifest-metadata-theme-variables-palette' => 'cool-thing'));
        $this->runAppearance(array('manifest-metadata-theme-variables-palette' => null));
        $this->assertFalse(isset($this->persisted()->metadata->theme->variables->palette));
    }

    public function testAppearanceIconWritesAndRejectsNonStrings(): void
    {
        $result = $this->runAppearance(array(
            'manifest-metadata-theme-variables-icon' => 'icons:favorite',
        ));
        $this->assertSame(200, $result['status']);
        $this->assertSame('icons:favorite', $this->persisted()->metadata->theme->variables->icon);

        $bad = $this->runAppearance(array('manifest-metadata-theme-variables-icon' => array('x')));
        $this->assertSame(400, $bad['__failed']['status']);
        $this->assertSame('invalid icon value', $bad['__failed']['message']);
    }

    public function testAppearanceRegionIdsWriteAndRejectInvalidEntries(): void
    {
        $result = $this->runAppearance(array(
            'manifest-metadata-theme-regions-header' => array('block-one', 'block-two'),
            'manifest-metadata-theme-regions-sidebarFirst' => array('block-three'),
        ));
        $this->assertSame(200, $result['status']);
        $regions = $this->persisted()->metadata->theme->regions;
        $this->assertSame(array('block-one', 'block-two'), (array) $regions->header);
        $this->assertSame(array('block-three'), (array) $regions->sidebarFirst);

        // a blank entry inside the list is refused
        $bad = $this->runAppearance(array(
            'manifest-metadata-theme-regions-footerPrimary' => array('block-one', ''),
        ));
        $this->assertSame(400, $bad['__failed']['status']);
        $this->assertSame('invalid region value', $bad['__failed']['message']);
    }

    public function testAppearanceFullSaveCommitsTwice(): void
    {
        $result = $this->runAppearance(array(
            'manifest-metadata-theme-variables-image' => 'files/banner.png',
            'manifest-metadata-theme-variables-cssVariable' => 'light-blue',
            'manifest-metadata-theme-regions-header' => array('block-one'),
        ));
        $this->assertSame(200, $result['status']);
        $this->assertTrue($result['data']['saved']);

        $site = $this->haxcms->loadedSite;
        $this->assertSame(
            array('Appearance settings updated', 'Managed files updated'),
            $site->gitCommits
        );
    }

    public function testAppearanceStdClassManifestPayloadAccepted(): void
    {
        $this->ops->params = array(
            'site_token' => 'good',
            'site' => array('name' => $this->siteName),
            'manifest' => (object)array(
                'theme' => (object)array(
                    'manifest-metadata-theme-variables-image' => 'files/banner.png',
                ),
            ),
        );
        $result = $this->ops->saveAppearanceSettings();
        $this->assertSame(200, $result['status']);
        $this->assertSame('files/banner.png', $this->persisted()->metadata->theme->variables->image);
    }

    // =========================================================================
    // saveSeoSettings
    // =========================================================================

    public function testSeoRefusedWhenSeoManifestDisabled(): void
    {
        $site = $this->haxcms->loadedSite;
        $site->manifest->metadata->platform = (object)array(
            'features' => (object)array('seoManifest' => false),
        );
        $this->ops->params = array(
            'site_token' => 'good',
            'site' => array('name' => $this->siteName),
            'seo' => array('description' => 'x'),
        );
        $result = $this->ops->saveSeoSettings();
        $this->assertSame(403, $result['__failed']['status']);
        $this->assertSame('SEO settings are disabled for this site', $result['__failed']['message']);
    }

    public function testSeoAuthorBlockWritesEveryField(): void
    {
        $this->ops->params = array(
            'site_token' => 'good',
            'site' => array('name' => $this->siteName),
            'author' => array(
                'license' => 'by-nd',
                'image' => 'files/author.png',
                'name' => 'Author Name',
                'email' => 'author@example.com',
                'socialLink' => 'https://social.example/author',
                'socialLink2' => 'https://social2.example/author',
                'phone' => '814-555-0100',
                'location' => 'University Park, PA',
                'website' => 'https://author.example',
                'website2' => 'https://author2.example',
            ),
            'seo' => array('description' => 'New description'),
        );
        $result = $this->ops->saveSeoSettings();

        // the route returns the manifest itself
        $this->assertSame('by-nd', $result->license);
        $this->assertSame('New description', $result->description);

        $persisted = $this->persisted();
        $this->assertSame('by-nd', $persisted->license);
        $author = $persisted->metadata->author;
        $this->assertSame('files/author.png', $author->image);
        $this->assertSame('Author Name', $author->name);
        $this->assertSame('author@example.com', $author->email);
        $this->assertSame('https://social.example/author', $author->socialLink);
        $this->assertSame('https://social2.example/author', $author->socialLink2);
        $this->assertSame('814-555-0100', $author->phone);
        $this->assertSame('University Park, PA', $author->location);
        $this->assertSame('https://author.example', $author->website);
        $this->assertSame('https://author2.example', $author->website2);
    }

    public function testSeoDottedManifestFormsWrite(): void
    {
        $this->ops->params = array(
            'site_token' => 'good',
            'site' => array('name' => $this->siteName),
            'manifest' => array(
                'author' => array(
                    'manifest.metadata.author.name' => 'Dotted Name',
                    // the license dotted form lives in the author block
                    'manifest.license' => 'by-nc',
                ),
                'seo' => array(
                    'manifest.description' => 'Dotted description',
                    'manifest.metadata.site.logo' => 'files/dotted-logo.png',
                    'manifest.metadata.site.domain' => 'dotted.example.com',
                    'manifest.metadata.site.settings.lang' => 'fr-FR',
                    'manifest.metadata.site.settings.gaID' => 'UA-000000-1',
                ),
            ),
        );
        $result = $this->ops->saveSeoSettings();

        $this->assertSame('by-nc', $result->license);
        $this->assertSame('Dotted description', $result->description);

        $persisted = $this->persisted();
        $this->assertSame('Dotted Name', $persisted->metadata->author->name);
        $this->assertSame('files/dotted-logo.png', $persisted->metadata->site->logo);
        $this->assertSame('dotted.example.com', $persisted->metadata->site->domain);
        $this->assertSame('fr-FR', $persisted->metadata->site->settings->lang);
        $this->assertSame('UA-000000-1', $persisted->metadata->site->settings->gaID);
    }

    public function testSeoBooleanSettingsInterpretation(): void
    {
        $this->ops->params = array(
            'site_token' => 'good',
            'site' => array('name' => $this->siteName),
            'seo' => array(
                'private' => 'true',
                'canonical' => 'no',
                'pathauto' => 'yes',
                // junk input: FILTER_VALIDATE_BOOLEAN + NULL_ON_FAILURE -> not written
                'publishPagesOn' => 'maybe-perhaps',
            ),
        );
        $result = $this->ops->saveSeoSettings();
        $this->assertNotNull($result);

        $settings = $this->persisted()->metadata->site->settings;
        $this->assertTrue($settings->private);
        $this->assertFalse($settings->canonical);
        $this->assertTrue($settings->pathauto);
        // junk value was dropped instead of coerced
        $this->assertFalse(isset($settings->publishPagesOn));
    }

    public function testSeoCommitsAndPersistsRoundTrip(): void
    {
        $this->ops->params = array(
            'site_token' => 'good',
            'site' => array('name' => $this->siteName),
            'seo' => array('description' => 'Round trip description'),
        );
        $result = $this->ops->saveSeoSettings();

        // both commits ran in the documented order
        $site = $this->haxcms->loadedSite;
        $this->assertSame(
            array('Managed files updated', 'SEO settings updated'),
            $site->gitCommits
        );
        $this->assertSame('Round trip description', $this->persisted()->description);
    }
}

/**
 * OperationsTestHaxcms subclass with a configurable themes registry for the
 * appearance theme-element lookup (registry values are objects, matching
 * HAXCMS::getThemes(), so manifest theme assignment stays object-shaped).
 */
class AppearanceSeoTestHaxcms extends OperationsTestHaxcms
{
    public $themes = array();

    public function getThemes()
    {
        return $this->themes;
    }
}

/**
 * HAXCMSSite test subclass stubbing the git/rebuild collaborators; the real
 * manifest load/save runs against the temp fixture.
 */
class AppearanceSeoTestSite extends HAXCMSSite
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
