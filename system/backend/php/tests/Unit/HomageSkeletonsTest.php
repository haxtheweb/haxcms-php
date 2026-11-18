<?php
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The Academic and Landing Page skeletons (haxtheweb/issues#3365) start a
 * site on al-folio-theme and astrowind-theme. Both themes list the pages
 * under the active page, so each skeleton keeps one section page (Projects,
 * Services) whose pages become that list. Same files and same cases as
 * haxcms-nodejs test/unit/homage-skeletons.test.cjs.
 */
class HomageSkeletonsTest extends TestCase
{
    private static function coreConfig(): string
    {
        return dirname(__DIR__, 4) . '/coreConfig';
    }

    private static function skeleton(string $file): array
    {
        return json_decode(file_get_contents(self::coreConfig() . '/skeletons/' . $file), true);
    }

    private static function bySlug(array $items, string $slug): array
    {
        foreach ($items as $item) {
            if ($item['slug'] === $slug) {
                return $item;
            }
        }
        return array();
    }

    public static function cases(): array
    {
        return array(
            'academic' => array(
                'academic.json',
                'academic',
                'al-folio-theme',
                '@haxtheweb/papermod-theme/lib/al-folio-theme.js',
                array('about', 'publications', 'projects', 'teaching', 'people', 'contact', 'privacy'),
                'projects',
            ),
            'landing-page' => array(
                'landing-page.json',
                'landing-page',
                'astrowind-theme',
                '@haxtheweb/papermod-theme/lib/astrowind-theme.js',
                array('home', 'services', 'pricing', 'about', 'contact', 'privacy'),
                'services',
            ),
        );
    }

    #[DataProvider('cases')]
    public function testUsesTheRegisteredTheme(string $file, string $name, string $theme, string $themePath): void
    {
        $skeleton = self::skeleton($file);
        $themes = json_decode(file_get_contents(self::coreConfig() . '/themes.json'), true);
        $this->assertSame($name, $skeleton['meta']['name']);
        $this->assertSame('skeleton', $skeleton['meta']['type']);
        $this->assertSame($theme, $skeleton['site']['theme']);
        $this->assertArrayHasKey($theme, $themes);
        $this->assertSame($themePath, $themes[$theme]['path']);
        $this->assertSame('from-skeleton', $skeleton['build']['structure']);
        $this->assertStringContainsString('theme-' . $theme . '-thumb', $skeleton['meta']['useCaseImage']);
    }

    #[DataProvider('cases')]
    public function testTopLevelPagesInOrder(string $file, string $name, string $theme, string $themePath, array $top): void
    {
        $items = self::skeleton($file)['build']['items'];
        $topItems = array_values(array_filter($items, function ($item) {
            return $item['parent'] === null;
        }));
        usort($topItems, function ($a, $b) {
            return $a['order'] <=> $b['order'];
        });
        $this->assertSame($top, array_column($topItems, 'slug'));
    }

    // haxtheweb/issues#3368: Privacy stays out of the menu; Contact links to it
    #[DataProvider('cases')]
    public function testPrivacyIsOutOfTheMenuAndLinkedFromContact(string $file): void
    {
        $items = self::skeleton($file)['build']['items'];
        $this->assertTrue(self::bySlug($items, 'privacy')['metadata']['hideInMenu']);
        $this->assertFalse(self::bySlug($items, 'contact')['metadata']['hideInMenu']);
        $this->assertStringContainsString('href="privacy"', self::bySlug($items, 'contact')['content']);
        $this->assertStringContainsString('href="contact"', self::bySlug($items, 'privacy')['content']);
    }

    #[DataProvider('cases')]
    public function testTheSectionPageHasThePagesTheThemeLists(string $file, string $name, string $theme, string $themePath, array $top, string $section): void
    {
        $items = self::skeleton($file)['build']['items'];
        $sectionItem = self::bySlug($items, $section);
        $children = array_values(array_filter($items, function ($item) use ($sectionItem) {
            return $item['parent'] === $sectionItem['id'];
        }));
        $this->assertGreaterThanOrEqual(3, count($children));
        foreach ($children as $index => $child) {
            $this->assertStringStartsWith($section . '/', $child['slug']);
            $this->assertSame(1, $child['indent']);
            $this->assertSame($index, $child['order']);
        }
        // every other page is top level
        $nested = array_filter($items, function ($item) {
            return $item['parent'] !== null;
        });
        $this->assertCount(count($children), $nested);
    }

    #[DataProvider('cases')]
    public function testIdsAndSlugsAreUniqueAndEveryPageIsPublishedWithContent(string $file): void
    {
        $items = self::skeleton($file)['build']['items'];
        $this->assertCount(count($items), array_unique(array_column($items, 'id')));
        $this->assertCount(count($items), array_unique(array_column($items, 'slug')));
        foreach ($items as $item) {
            $this->assertTrue($item['metadata']['published']);
            $this->assertIsArray($item['metadata']['tags']);
            $this->assertNotSame('', $item['content']);
        }
    }

    // images aren't bundled with skeletons, and a starter must not hotlink them
    #[DataProvider('cases')]
    public function testNoMediaIsPulledFromOtherSites(string $file): void
    {
        foreach (self::skeleton($file)['build']['items'] as $item) {
            $this->assertDoesNotMatchRegularExpression('/<(img|media-image|video|iframe)\b/', $item['content']);
            $this->assertDoesNotMatchRegularExpression('/(src|source)="https?:/', $item['content']);
        }
    }

    public function testTheLandingPagesHomeHidesItsTitle(): void
    {
        $home = self::bySlug(self::skeleton('landing-page.json')['build']['items'], 'home');
        $this->assertTrue($home['metadata']['hideTitle']);
    }

    // the two backends ship the same skeleton files
    public function testSkeletonsMatchTheDocumentedShape(): void
    {
        foreach (array('academic.json', 'landing-page.json') as $file) {
            $skeleton = self::skeleton($file);
            $this->assertSame(array('meta', 'site', 'build', 'theme'), array_keys($skeleton));
            $this->assertSame(array(), $skeleton['build']['files']);
        }
    }
}
