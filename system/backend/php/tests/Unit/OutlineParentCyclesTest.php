<?php
use PHPUnit\Framework\TestCase;

/**
 * Parent cycles in an outline (haxtheweb/issues#3287, FI-PAY-07/08):
 * saveOutline rejects a payload that would create one before writing
 * anything (validateOutlineItems), and the parent walk in getUniqueSlugName
 * stops on a cycle that is already on disk instead of looping forever.
 * Mirrors haxcms-nodejs test/unit/outline-parent-cycles.test.cjs.
 */
class OutlineParentCyclesTest extends TestCase
{
    const CYCLE = 'Outline item parents cannot form a cycle';

    /** a site whose outline is the given [id, parent, slug] rows */
    private function siteWith(array $rows): HAXCMSSite
    {
        $site = new HAXCMSSite();
        $site->manifest = new JSONOutlineSchema();
        foreach ($rows as $row) {
            $item = $site->manifest->newItem();
            $item->id = $row[0];
            $item->parent = $row[1];
            $item->slug = isset($row[2]) ? $row[2] : $row[0];
            $site->manifest->addItem($item);
        }
        return $site;
    }

    private function sent(string $id, $parent): stdClass
    {
        return (object) array('id' => $id, 'parent' => $parent, 'title' => $id);
    }

    private function validate(HAXCMSSite $site, array $items)
    {
        $ops = new Operations();
        $method = new ReflectionMethod($ops, 'validateOutlineItems');
        return $method->invoke($ops, $site, $items);
    }

    public function testAValidOutlinePassesIncludingAMissingParent(): void
    {
        $site = $this->siteWith(array(array('a', null), array('b', 'a')));
        $this->assertNull($this->validate($site, array($this->sent('a', null), $this->sent('b', 'a'))));
        $this->assertNull($this->validate($site, array($this->sent('b', 'no-such-page'))));
        $this->assertNull($this->validate($site, array($this->sent('new-1', 'a'), $this->sent('new-2', 'new-1'))));
        // an existing id twice is one page (the last entry wins)
        $this->assertNull($this->validate($site, array($this->sent('a', null), $this->sent('a', null))));
    }

    public function testSelfParentAndCyclesAreRejected(): void
    {
        $site = $this->siteWith(array(array('a', null), array('b', 'a'), array('c', 'b')));
        $this->assertSame(self::CYCLE, $this->validate($site, array($this->sent('a', 'a'))));
        $this->assertSame(self::CYCLE, $this->validate($site, array($this->sent('a', 'b'), $this->sent('b', 'a'))));
        // a moved under its own grandchild c (c stays where it is on disk)
        $this->assertSame(self::CYCLE, $this->validate($site, array($this->sent('a', 'c'))));
        // two new pages under each other
        $this->assertSame(self::CYCLE, $this->validate($site, array($this->sent('x', 'y'), $this->sent('y', 'x'))));
    }

    public function testRepeatedNewIdsAreRejected(): void
    {
        $site = $this->siteWith(array(array('a', null)));
        $this->assertSame(
            'Each new outline item requires its own id',
            $this->validate($site, array($this->sent('n', null), $this->sent('n', null)))
        );
    }

    public function testGetUniqueSlugNameStopsOnAParentCycleOnDisk(): void
    {
        $site = $this->siteWith(array(array('a', 'b', 'a'), array('b', 'a', 'b'), array('s', 's', 's')));
        $this->assertSame('b/a/child', $site->getUniqueSlugName('child', (object) array('id' => 'c', 'parent' => 'a'), true));
        $this->assertSame('self', $site->getUniqueSlugName('self', (object) array('id' => 's', 'parent' => 's'), true));
    }
}
