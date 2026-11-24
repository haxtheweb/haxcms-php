<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/ReportHelpers.php';

/**
 * The overview report statistics have one definition in both backends
 * (haxtheweb/issues#3394). tests/fixtures/overview-stats-vectors.json is a
 * copy of haxcms-nodejs test/fixtures/overview-stats-vectors.json: the same
 * pages must give the same numbers on both.
 */
class OverviewStatsParityTest extends TestCase
{
    public function testOverviewStatisticsMatchTheSharedVectors(): void
    {
        $raw = file_get_contents(dirname(__DIR__) . '/fixtures/overview-stats-vectors.json');
        $this->assertIsString($raw);
        $vectors = json_decode($raw, true);
        $items = array();
        $contents = array();
        foreach ($vectors['items'] as $entry) {
            $item = new stdClass();
            $item->id = $entry['id'];
            $item->title = $entry['title'];
            $item->slug = $entry['id'];
            $item->location = 'pages/' . $entry['id'] . '/index.html';
            $item->parent = $entry['parent'];
            $item->metadata = new stdClass();
            if (array_key_exists('pageType', $entry)) {
                $item->metadata->pageType = $entry['pageType'];
            }
            if (array_key_exists('published', $entry)) {
                $item->metadata->published = $entry['published'];
            }
            $items[] = $item;
            $contents[$entry['id']] = $entry['content'];
        }
        $manifest = new stdClass();
        $manifest->items = $items;
        $site = new OverviewStatsFakeSite($manifest, $contents);
        foreach ($vectors['cases'] as $case) {
            $params = is_null($case['ancestor']) ? array() : array('activeId' => $case['ancestor']);
            $summary = HAXCMSReportHelpers::buildSummaryData($site, $params);
            $actual = array();
            foreach (array_keys($case['expected']) as $key) {
                $actual[$key] = $summary[$key];
            }
            $this->assertSame($case['expected'], $actual, 'ancestor ' . var_export($case['ancestor'], true));
        }
    }
}

class OverviewStatsFakeSite
{
    public $manifest;
    /** @var array<string, string> */
    private $contents;

    /**
     * @param array<string, string> $contents
     */
    public function __construct($manifest, array $contents)
    {
        $this->manifest = $manifest;
        $this->contents = $contents;
    }

    public function loadNode($id)
    {
        if (!array_key_exists($id, $this->contents)) {
            return null;
        }
        $page = new stdClass();
        $page->id = $id;
        return $page;
    }

    public function getPageContent($page)
    {
        return $this->contents[$page->id];
    }
}
