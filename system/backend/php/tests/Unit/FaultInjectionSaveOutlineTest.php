<?php
require_once __DIR__ . '/FaultInjectionTestSupport.php';

/**
 * saveOutline (PATCH /x/api/v1/site/outline) under filesystem faults and
 * malformed payloads. Mirrors haxcms-nodejs
 * test/unit/fault-injection-save-outline.test.cjs; scenario IDs (FI-OUT-*,
 * FI-PAY-*) are shared so a result can be compared across backends.
 *
 * saveOutline is checkpointed, not atomic, on both backends: the new
 * structure is written to site.json once after the structure pass, so a
 * failure in the content / delete phase leaves that structure saved.
 * Tests that PHP does not pass today run through knownBug() and report as
 * skipped with the reason.
 */
class FaultInjectionSaveOutlineTest extends FaultInjectionTestCase
{
    private function outline(array $items, array $rules = array()): array
    {
        return $this->request('siteMutation.php', $this->context(array('items' => $items), 'PATCH', 'site/outline'), $rules);
    }

    /** writing site.json fails before any byte lands (fopen-time ENOSPC) */
    private function siteJsonEnospc(): array
    {
        return array(array('op' => 'open', 'match' => $this->isPath($this->siteJsonPath()), 'errno' => 'ENOSPC'));
    }

    private function item(string $id, string $title, ?string $parent, int $order, array $extra = array()): array
    {
        return array_merge(array(
            'id' => $id,
            'title' => $title,
            'parent' => $parent,
            'indent' => $parent === null ? 0 : 1,
            'order' => $order,
            'metadata' => array(),
        ), $extra);
    }

    /** rename existing page B and add a new page N */
    private function renameBAddN(): array
    {
        return array(
            $this->item('a', 'A', null, 0),
            $this->item('b', 'B renamed', null, 1),
            $this->item('c', 'C', null, 2),
            $this->item('client-n', 'N', null, 3),
        );
    }

    private function savedItems(): array
    {
        $out = array();
        foreach (json_decode($this->siteJsonBytes())->items as $item) {
            $out[$item->id] = $item;
        }
        return $out;
    }

    // ---- filesystem faults --------------------------------------------

    /** control: the wrapper with a rule that never fires changes nothing */
    public function testWrapperWithoutAFaultSavesNormally(): void
    {
        $res = $this->outline($this->renameBAddN(), array(
            array('op' => 'open', 'match' => function () {
                return false;
            }),
        ));
        $this->assertSame(array(), $res['fired']);
        $this->assertSame(200, $res['status'], $res['raw']);
        $items = $this->savedItems();
        $this->assertCount(4, $items);
        $this->assertSame('B renamed', $items['b']->title);
        $this->assertSame(array(), $this->orphanPageFolders());
    }

    public function testFiOut01SiteJsonEnospcLeavesSiteJsonUnchanged(): void
    {
        $before = $this->siteJsonBytes();
        $res = $this->outline($this->renameBAddN(), $this->siteJsonEnospc());
        $this->assertNotEmpty($res['fired'], 'fault fired');
        $this->assertSame($before, $this->siteJsonBytes());
        $this->assertStillServing();
    }

    public function testFiOut01SiteJsonEnospcIsAnErrorEnvelope(): void
    {
        $res = $this->outline($this->renameBAddN(), $this->siteJsonEnospc());
        $this->assertNotEmpty($res['fired'], 'fault fired');
        $this->knownBug(
            'FI-OUT-01 saveOutline ignores JSONOutlineSchema::save() returning false (site.json not written) and answers 200',
            function () use ($res) {
                $this->assertErrorEnvelope($res);
            }
        );
    }

    public function testFiOut01SiteJsonEnospcLeavesNoOrphanPageFolder(): void
    {
        $res = $this->outline($this->renameBAddN(), $this->siteJsonEnospc());
        $this->assertNotEmpty($res['fired'], 'fault fired');
        $this->knownBug(
            'FI-OUT-01 the new page folder is copied before site.json is written and is not removed when that write fails (same as haxcms-nodejs)',
            function () {
                $this->assertSame(array(), $this->orphanPageFolders());
            }
        );
    }

    public function testFiOut02PageContentEaccesIs500AndKeepsThePageAndTheNewStructure(): void
    {
        $pageBefore = file_get_contents($this->pageFile('b'));
        $items = $this->renameBAddN();
        $items[1]['contents'] = '<p>B new contents</p>';
        $res = $this->outline($items, array(
            array('op' => 'open', 'match' => $this->isPath($this->pageFile('b')), 'errno' => 'EACCES'),
        ));
        $this->assertNotEmpty($res['fired'], 'fault fired');
        $this->assertErrorEnvelope($res, 500, 'failed to write');
        $this->assertSame($pageBefore, file_get_contents($this->pageFile('b')));
        // checkpoint semantics: the structure pass was saved before the
        // content phase failed
        $this->assertSame('B renamed', $this->savedItems()['b']->title);
        $this->assertCount(4, $this->savedItems());
        $this->assertStillServing();
    }

    public function testFiOut03PartialSiteJsonWriteKeepsTheOldFileParseable(): void
    {
        $before = $this->siteJsonBytes();
        $res = $this->request('siteMutation.php', $this->context(array('items' => $this->renameBAddN()), 'PATCH', 'site/outline'), array(
            array('op' => 'write', 'match' => $this->isPath($this->siteJsonPath()), 'errno' => 'ENOSPC'),
        ));
        $this->assertNotEmpty($res['fired'], 'fault fired');
        $this->knownBug(
            'FI-OUT-03 site.json is written in place (file_put_contents), so a write that stops part-way leaves it truncated and unparseable (same as haxcms-nodejs)',
            function () use ($before) {
                $after = $this->siteJsonBytes();
                $this->assertIsObject(json_decode($after), 'site.json still parses');
                $this->assertSame($before, $after);
            }
        );
    }

    public function testFiOut05CorruptSiteJsonIsAnErrorAndIsNotRewritten(): void
    {
        $corrupt = substr($this->siteJsonBytes(), 0, 200);
        file_put_contents($this->siteJsonPath(), $corrupt);
        $this->site = $this->loadSite();
        $res = $this->outline(array(
            $this->item('a', 'A', null, 0),
            $this->item('b', 'B renamed', null, 1),
        ));
        $this->knownBug(
            'FI-OUT-05 a site.json that does not parse loads as an empty "New site" outline; saveOutline then replaces the corrupt file with that outline (original ids, titles, metadata and site name lost) and throws (plain 500)',
            function () use ($res, $corrupt) {
                $this->assertSame($corrupt, $this->siteJsonBytes(), 'corrupt site.json left as it was');
                $this->assertErrorEnvelope($res);
            }
        );
    }

    public function testFiOut06SlowPageFolderCopyStillCompletes(): void
    {
        // PHP serves each request in its own process, so a slow save cannot
        // hold up a concurrent GET the way it could on the NodeJS event loop;
        // that half of FI-OUT-06 has no PHP equivalent. The save itself must
        // still complete normally with a slow disk.
        $start = microtime(true);
        $res = $this->request('siteMutation.php', $this->context(array('items' => $this->renameBAddN()), 'PATCH', 'site/outline'), array(
            array('op' => 'mkdir', 'match' => function ($path) {
                return strpos($path, $this->siteDir() . '/pages/') === 0;
            }, 'delay' => 250000),
        ));
        $this->assertGreaterThanOrEqual(0.25, microtime(true) - $start);
        $this->assertNull($res['thrown']);
        $this->assertSame(200, $res['status'], $res['raw']);
        $this->assertCount(4, $this->savedItems());
        $this->assertSame(array(), $this->orphanPageFolders());
    }

    // ---- malformed payloads -------------------------------------------

    public function testFiPay01ItemsNotAnArrayIs400(): void
    {
        foreach (array('not-an-array', null) as $items) {
            $before = $this->siteJsonBytes();
            $body = $items === null ? array() : array('items' => $items);
            $res = $this->request('siteMutation.php', $this->context($body, 'PATCH', 'site/outline'));
            $this->assertErrorEnvelope($res, 400, 'Outline payload requires an items array');
            $this->assertSame($before, $this->siteJsonBytes());
        }
    }

    public function testFiPay02PartialOutlineLeavesUnsentPagesAlone(): void
    {
        $before = $this->savedItems();
        $res = $this->outline(array($this->item('b', 'B renamed', null, 1)));
        $this->assertSame(200, $res['status'], $res['raw']);
        $after = $this->savedItems();
        $this->assertSame('B renamed', $after['b']->title);
        foreach (array('a', 'c') as $id) {
            $this->assertSame($before[$id]->title, $after[$id]->title);
            $this->assertSame($before[$id]->parent, $after[$id]->parent);
            $this->assertSame($before[$id]->order, $after[$id]->order);
        }
    }

    public function testFiPay03NullItemIs400(): void
    {
        $before = $this->siteJsonBytes();
        $items = $this->renameBAddN();
        array_splice($items, 1, 0, array(null));
        $res = $this->outline($items);
        $this->knownBug(
            'FI-PAY-03 saveOutline skips null / non-object items and saves the rest with 200 instead of rejecting the payload (haxcms-nodejs throws a TypeError instead)',
            function () use ($res, $before) {
                $this->assertErrorEnvelope($res, 400);
                $this->assertSame($before, $this->siteJsonBytes());
            }
        );
    }

    public function testFiPay04SameExistingIdTwiceKeepsOnePage(): void
    {
        $res = $this->outline(array(
            $this->item('a', 'A first', null, 0),
            $this->item('b', 'B', null, 1),
            $this->item('c', 'C', null, 2),
            $this->item('a', 'A last', null, 3),
        ));
        $this->assertSame(200, $res['status'], $res['raw']);
        $items = $this->savedItems();
        $this->assertCount(3, $items);
        $this->assertSame('A last', $items['a']->title);
        $this->assertSame(array(), $this->orphanPageFolders());
    }

    public function testFiPay05SameNewIdTwiceCreatesOnePageOrIs400(): void
    {
        $res = $this->outline(array(
            $this->item('a', 'A', null, 0),
            $this->item('b', 'B', null, 1),
            $this->item('c', 'C', null, 2),
            $this->item('client-x', 'X one', null, 3),
            $this->item('client-x', 'X two', null, 4),
        ));
        $this->knownBug(
            'FI-PAY-05 a new client id sent twice creates two pages; idMap can only map one, so the other is unreachable for the client (same as haxcms-nodejs)',
            function () use ($res) {
                if ($res['status'] === 400) {
                    $this->assertErrorEnvelope($res, 400);
                    $this->assertCount(3, $this->savedItems());
                } else {
                    $this->assertSame(200, $res['status'], $res['raw']);
                    $this->assertCount(4, $this->savedItems(), 'exactly one page created');
                }
            }
        );
    }

    public function testFiPay06MissingParentIsRescuedToTheTopLevel(): void
    {
        $res = $this->outline(array(
            $this->item('a', 'A', null, 0),
            $this->item('b', 'B', 'no-such-page', 0),
            $this->item('c', 'C', null, 2),
        ));
        $this->assertSame(200, $res['status'], $res['raw']);
        $this->assertNull($this->savedItems()['b']->parent);
    }

    public function testFiPay07ParentCycleIs400(): void
    {
        $before = $this->siteJsonBytes();
        $res = $this->withDeadline(5, function () {
            return $this->outline(array(
                $this->item('a', 'A', 'b', 0),
                $this->item('b', 'B', 'a', 0),
                $this->item('c', 'C', null, 2),
            ));
        });
        $this->knownBug(
            'FI-PAY-07 a parent cycle never answers: HAXCMSSite::getUniqueSlugName() walks the parent chain without a visited set (lib/HAXCMSSite.php:3398), so the worker spins until max_execution_time (same as haxcms-nodejs)',
            function () use ($res, $before) {
                $this->assertErrorEnvelope($res, 400);
                $this->assertSame($before, $this->siteJsonBytes());
            }
        );
    }

    public function testFiPay08SelfParentIs400(): void
    {
        $before = $this->siteJsonBytes();
        $res = $this->withDeadline(5, function () {
            return $this->outline(array(
                $this->item('a', 'A', 'a', 0),
                $this->item('b', 'B', null, 1),
                $this->item('c', 'C', null, 2),
            ));
        });
        $this->knownBug(
            'FI-PAY-08 a page that is its own parent never answers: same parent-chain walk as FI-PAY-07 (lib/HAXCMSSite.php:3398)',
            function () use ($res, $before) {
                $this->assertErrorEnvelope($res, 400);
                $this->assertSame($before, $this->siteJsonBytes());
            }
        );
    }
}
