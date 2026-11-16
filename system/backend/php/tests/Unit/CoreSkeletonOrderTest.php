<?php
use PHPUnit\Framework\TestCase;

/**
 * The new-site dashboard lists skeletons by priority (lowest first), then by
 * title (app-hax-use-case-filter). Online Course and Blog are the featured
 * starters, so they come first on every HAXcms. Priority is read the way the
 * skeletons list route reads it (skeletonsList.php: a number, else 0).
 * haxcms-nodejs keeps the same skeleton files (featured-skeletons.test.cjs).
 */
class CoreSkeletonOrderTest extends TestCase
{
    private function coreSkeletons(): array
    {
        $dir = dirname(__DIR__, 4) . '/coreConfig/skeletons';
        $items = array();
        foreach (glob($dir . '/*.json') as $file) {
            $name = basename($file, '.json');
            if ($name === 'default-starter') {
                continue;
            }
            $skeleton = json_decode(file_get_contents($file));
            $meta = isset($skeleton->meta) ? $skeleton->meta : new stdClass();
            $priority = 0;
            if (isset($meta->priority) && is_numeric($meta->priority)) {
                $priority = 0 + $meta->priority;
            }
            $title = isset($meta->useCaseTitle) && $meta->useCaseTitle
                ? $meta->useCaseTitle
                : $name;
            $items[] = array('name' => $name, 'title' => $title, 'priority' => $priority);
        }
        usort($items, function ($a, $b) {
            if ($a['priority'] != $b['priority']) {
                return $a['priority'] < $b['priority'] ? -1 : 1;
            }
            return strcmp($a['title'], $b['title']);
        });
        return $items;
    }

    public function testOnlineCourseThenBlogLeadTheDashboard(): void
    {
        $items = $this->coreSkeletons();
        $this->assertGreaterThan(2, count($items));
        $this->assertSame(
            array('online-course', 'blog'),
            array($items[0]['name'], $items[1]['name'])
        );
        // every other core skeleton comes after the featured two
        for ($i = 2; $i < count($items); $i++) {
            $this->assertGreaterThan($items[1]['priority'], $items[$i]['priority'], $items[$i]['name']);
        }
    }
}
