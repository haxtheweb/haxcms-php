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
        return array(array('op' => 'open', 'match' => $this->isSiteJsonWrite(), 'errno' => 'ENOSPC'));
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
        $this->assertErrorEnvelope($res, 500, 'Server error');
    }

    public function testFiOut01SiteJsonEnospcLeavesNoOrphanPageFolder(): void
    {
        $res = $this->outline($this->renameBAddN(), $this->siteJsonEnospc());
        $this->assertNotEmpty($res['fired'], 'fault fired');
        $this->assertSame(array(), $this->orphanPageFolders());
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
            array('op' => 'write', 'match' => $this->isSiteJsonWrite(), 'errno' => 'ENOSPC'),
        ));
        $this->assertNotEmpty($res['fired'], 'fault fired');
        $after = $this->siteJsonBytes();
        $this->assertIsObject(json_decode($after), 'site.json still parses');
        $this->assertSame($before, $after);
        $this->assertSame(array(), $this->leftoverTempFiles(), 'the temp file is removed');
        $this->assertErrorEnvelope($res, 500, 'Server error');
    }

    public function testFiOut05CorruptSiteJsonIsAnErrorAndIsNotRewritten(): void
    {
        $corrupt = substr($this->siteJsonBytes(), 0, 200);
        file_put_contents($this->siteJsonPath(), $corrupt);
        // the request loads site.json itself, as HAXCMS::loadSite() does
        $this->haxcms->loadSiteFromDisk = function () {
            return $this->loadSite();
        };
        $res = $this->outline(array(
            $this->item('a', 'A', null, 0),
            $this->item('b', 'B renamed', null, 1),
        ));
        $this->assertSame($corrupt, $this->siteJsonBytes(), 'corrupt site.json left as it was');
        $this->assertErrorEnvelope($res, 500, 'Server error');
        $this->assertSame(array('a', 'b', 'c'), array_values(array_diff(scandir($this->siteDir() . '/pages'), array('.', '..'))), 'no page folder created');
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
        foreach (array(null, 42, 'page', array(1)) as $bad) {
            $items = $this->renameBAddN();
            array_splice($items, 1, 0, array($bad));
            $res = $this->outline($items);
            $this->assertErrorEnvelope($res, 400, 'Each outline item must be an object');
            $this->assertSame($before, $this->siteJsonBytes());
            $this->assertSame(array(), $this->orphanPageFolders());
        }
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

    public function testFiPay05SameNewIdTwiceIs400(): void
    {
        $res = $this->outline(array(
            $this->item('a', 'A', null, 0),
            $this->item('b', 'B', null, 1),
            $this->item('c', 'C', null, 2),
            $this->item('client-x', 'X one', null, 3),
            $this->item('client-x', 'X two', null, 4),
        ));
        // both backends answer 400 (haxcms-nodejs fault-injection-save-outline)
        $this->assertErrorEnvelope($res, 400, 'Each new outline item requires its own id');
        $this->assertCount(3, $this->savedItems());
        $this->assertSame(array(), $this->orphanPageFolders());
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
        $this->assertErrorEnvelope($res, 400, 'Outline item parents cannot form a cycle');
        $this->assertSame($before, $this->siteJsonBytes());
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
        $this->assertErrorEnvelope($res, 400, 'Outline item parents cannot form a cycle');
        $this->assertSame($before, $this->siteJsonBytes());
    }
}
