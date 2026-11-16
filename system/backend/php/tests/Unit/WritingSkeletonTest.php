<?php
use PHPUnit\Framework\TestCase;

/**
 * The writing skeleton (haxtheweb/issues#3062) sets up the structure
 * writing-theme reads: Home, About and Archive as the nav row, and a Posts
 * page (out of the menu) that every post goes under. Same file and same
 * cases as haxcms-nodejs test/unit/writing-skeleton.test.cjs.
 */
class WritingSkeletonTest extends TestCase
{
    private static function coreConfig(): string
    {
        return dirname(__DIR__, 4) . '/coreConfig';
    }

    private static function skeleton(): array
    {
        return json_decode(file_get_contents(self::coreConfig() . '/skeletons/writing.json'), true);
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

    public function testUsesTheRegisteredWritingTheme(): void
    {
        $skeleton = self::skeleton();
        $themes = json_decode(file_get_contents(self::coreConfig() . '/themes.json'), true);
        $this->assertSame('writing', $skeleton['meta']['name']);
        $this->assertSame('writing-theme', $skeleton['site']['theme']);
        $this->assertArrayHasKey('writing-theme', $themes);
        $this->assertSame('@haxtheweb/writing-theme/writing-theme.js', $themes['writing-theme']['path']);
        $this->assertSame('from-skeleton', $skeleton['build']['structure']);
    }

    public function testTopLevelIsHomeAboutArchiveThenPostsOutOfTheMenu(): void
    {
        $items = self::skeleton()['build']['items'];
        $top = array_values(array_filter($items, function ($item) {
            return $item['parent'] === null;
        }));
        usort($top, function ($a, $b) {
            return $a['order'] <=> $b['order'];
        });
        $this->assertSame(array('home', 'about', 'archive', 'posts'), array_column($top, 'slug'));
        $this->assertTrue(self::bySlug($items, 'posts')['metadata']['hideInMenu']);
        // the home page's content stands in for a title, so the title is hidden
        $this->assertTrue(self::bySlug($items, 'home')['metadata']['hideTitle']);
    }

    public function testEveryOtherPageIsAPostUnderPosts(): void
    {
        $items = self::skeleton()['build']['items'];
        $postsId = self::bySlug($items, 'posts')['id'];
        $posts = array_filter($items, function ($item) {
            return $item['parent'] !== null;
        });
        $this->assertGreaterThanOrEqual(3, count($posts));
        foreach ($posts as $post) {
            $this->assertSame($postsId, $post['parent']);
            $this->assertStringStartsWith('posts/', $post['slug']);
            $this->assertIsArray($post['metadata']['tags']);
        }
    }

    public function testIdsAreUnique(): void
    {
        $items = self::skeleton()['build']['items'];
        $ids = array_column($items, 'id');
        $this->assertSame(count($ids), count(array_unique($ids)));
    }
}
