<?php
use PHPUnit\Framework\TestCase;

/**
 * The department skeleton (haxtheweb/issues#3368): a university department,
 * office or center on polaris-flex-sidebar, with About, Services, People,
 * News, Contact, and a Privacy page out of the menu. Same file and same
 * checks as haxcms-nodejs test/unit/department-skeleton.test.cjs.
 */
class DepartmentSkeletonTest extends TestCase
{
    private static function coreConfig(): string
    {
        return dirname(__DIR__, 4) . '/coreConfig';
    }

    private static function skeleton(): array
    {
        return json_decode(file_get_contents(self::coreConfig() . '/skeletons/department.json'), true);
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

    public function testUsesTheRegisteredPolarisFlexSidebar(): void
    {
        $skeleton = self::skeleton();
        $themes = json_decode(file_get_contents(self::coreConfig() . '/themes.json'), true);
        $this->assertSame('department', $skeleton['meta']['name']);
        $this->assertSame('skeleton', $skeleton['meta']['type']);
        $this->assertSame('polaris-flex-sidebar', $skeleton['site']['theme']);
        $this->assertArrayHasKey('polaris-flex-sidebar', $themes);
        $this->assertSame('from-skeleton', $skeleton['build']['structure']);
        $this->assertStringContainsString('theme-polaris-flex-sidebar-thumb', $skeleton['meta']['useCaseImage']);
    }

    public function testTopLevelPagesInOrder(): void
    {
        $top = array_values(array_filter(self::skeleton()['build']['items'], function ($item) {
            return $item['parent'] === null;
        }));
        usort($top, function ($a, $b) {
            return $a['order'] <=> $b['order'];
        });
        $this->assertSame(
            array('home', 'about', 'services', 'people', 'news', 'contact', 'privacy'),
            array_column($top, 'slug')
        );
    }

    public function testServicesAndNewsHoldTheirPagesInOrder(): void
    {
        $items = self::skeleton()['build']['items'];
        foreach (array('services', 'news') as $section) {
            $parent = self::bySlug($items, $section);
            $children = array_values(array_filter($items, function ($item) use ($parent) {
                return $item['parent'] === $parent['id'];
            }));
            $this->assertGreaterThanOrEqual(2, count($children), $section);
            foreach ($children as $index => $child) {
                $this->assertStringStartsWith($section . '/', $child['slug']);
                $this->assertSame(1, $child['indent']);
                $this->assertSame($index, $child['order']);
            }
        }
    }

    public function testPrivacyIsOutOfTheMenuAndLinkedFromContact(): void
    {
        $items = self::skeleton()['build']['items'];
        $this->assertTrue(self::bySlug($items, 'privacy')['metadata']['hideInMenu']);
        $this->assertStringContainsString('href="privacy"', self::bySlug($items, 'contact')['content']);
        $this->assertStringContainsString('href="contact"', self::bySlug($items, 'privacy')['content']);
    }

    public function testEveryPersonOnPeopleUsesTheSameCard(): void
    {
        $people = self::bySlug(self::skeleton()['build']['items'], 'people')['content'];
        preg_match_all('/<collection-item [^>]*>/', $people, $cards);
        $this->assertGreaterThanOrEqual(3, count($cards[0]));
        foreach ($cards[0] as $card) {
            foreach (array('icon=', 'line1=', 'line2=', 'line3=', 'url="mailto:') as $attr) {
                $this->assertStringContainsString($attr, $card);
            }
        }
    }

    public function testIdsUniqueEveryPagePublishedAndNothingPulledFromOtherSites(): void
    {
        $items = self::skeleton()['build']['items'];
        $this->assertCount(count($items), array_unique(array_column($items, 'id')));
        $this->assertCount(count($items), array_unique(array_column($items, 'slug')));
        foreach ($items as $item) {
            $this->assertTrue($item['metadata']['published']);
            $this->assertIsArray($item['metadata']['tags']);
            $this->assertNotSame('', $item['content']);
            // links stay inside the site (relative to its base) or are mailto
            preg_match_all('/(href|link|src|source|image)="([^"]*)"/', $item['content'], $attrs);
            foreach ($attrs[2] as $value) {
                $this->assertTrue(
                    strpos($value, 'mailto:') === 0 || !preg_match('#^[a-z]+:|^//|^\.\.#i', $value),
                    $item['slug'] . ': ' . $value
                );
            }
        }
    }
}
