<?php
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Characterization tests for the JSONOutlineSchema outline data-model seam.
 *
 * Expected values come from the JSON Outline Schema spec: items carry id,
 * indent, location, slug, order, parent, title, description, metadata; add /
 * remove / update operate by id; orderTree produces parent-grouped, order-
 * sorted tree sequence; save/load round-trip through the filesystem.
 */
class JSONOutlineSchemaTest extends TestCase
{
    public function testNewItemHasSpecDefaults(): void
    {
        $jos = new JSONOutlineSchema();
        $item = $jos->newItem();
        $this->assertStringStartsWith('item-', $item->id);
        $this->assertSame(0, $item->indent);
        $this->assertSame('', $item->location);
        $this->assertSame('', $item->slug);
        $this->assertSame(0, $item->order);
        $this->assertSame('', $item->parent);
        $this->assertSame('New item', $item->title);
        $this->assertSame('', $item->description);
        $this->assertEquals(new stdClass(), $item->metadata);
    }

    public function testConstructorHasSpecDefaults(): void
    {
        $jos = new JSONOutlineSchema();
        $this->assertNull($jos->file);
        $this->assertSame('New site', $jos->title);
        $this->assertSame('', $jos->author);
        $this->assertSame('by-sa', $jos->license);
        $this->assertSame([], $jos->items);
        $this->assertEquals(new stdClass(), $jos->metadata);
    }

    public function testAddItemReturnsCountAndIsRetrievableById(): void
    {
        $jos = new JSONOutlineSchema();
        $item = $jos->newItem();
        $item->id = 'abc';
        $item->title = 'First';
        $this->assertSame(1, $jos->addItem($item));
        $this->assertSame(1, count($jos->items));

        $item2 = $jos->newItem();
        $item2->id = 'def';
        $item2->title = 'Second';
        $this->assertSame(2, $jos->addItem($item2));

        $found = $jos->getItemById('abc');
        $this->assertNotFalse($found);
        $this->assertSame('First', $found->title);
        $this->assertFalse($jos->getItemById('missing'));
    }

    public function testRemoveItemReturnsItemAndRemovesIt(): void
    {
        $jos = new JSONOutlineSchema();
        $item = $jos->newItem();
        $item->id = 'x';
        $item->title = 'Remove me';
        $jos->addItem($item);

        $removed = $jos->removeItem('x');
        $this->assertNotFalse($removed);
        $this->assertSame('Remove me', $removed->title);
        $this->assertFalse($jos->getItemById('x'));
        $this->assertFalse($jos->removeItem('not-there'));
    }

    public function testUpdateItemOverwritesMatchingId(): void
    {
        $jos = new JSONOutlineSchema();
        $item = $jos->newItem();
        $item->id = 'y';
        $item->title = 'old';
        $jos->addItem($item);

        $updated = $jos->newItem();
        $updated->id = 'y';
        $updated->title = 'new';
        $this->assertTrue($jos->updateItem($updated));
        $this->assertSame('new', $jos->getItemById('y')->title);

        $ghost = $jos->newItem();
        $ghost->id = 'missing';
        $ghost->title = 'nope';
        $this->assertFalse($jos->updateItem($ghost));
    }

    public function testOrderTreeProducesParentGroupedOrderSequence(): void
    {
        // root: A(order=1), C(order=3); A has children D(order=1), B(order=2)
        // expected tree order: A, D, B, C
        $jos = new JSONOutlineSchema();
        $a = $jos->newItem(); $a->id = 'a'; $a->parent = ''; $a->order = 1; $a->title = 'A';
        $b = $jos->newItem(); $b->id = 'b'; $b->parent = 'a'; $b->order = 2; $b->title = 'B';
        $c = $jos->newItem(); $c->id = 'c'; $c->parent = ''; $c->order = 3; $c->title = 'C';
        $d = $jos->newItem(); $d->id = 'd'; $d->parent = 'a'; $d->order = 1; $d->title = 'D';
        $jos->addItem($a); $jos->addItem($b); $jos->addItem($c); $jos->addItem($d);

        $ordered = $jos->orderTree($jos->items);
        $ids = array_map(function ($i) { return $i->id; }, $ordered);
        $this->assertSame(['a', 'd', 'b', 'c'], $ids);
    }

    public function testSaveAndLoadRoundTripThroughFilesystem(): void
    {
        $tmp = sys_get_temp_dir() . '/jos_test_' . uniqid() . '.json';
        $jos = new JSONOutlineSchema();
        $jos->title = 'Round trip';
        $jos->author = 'tester';
        $item = $jos->newItem();
        $item->id = 'page-1';
        $item->title = 'Page One';
        $item->slug = 'page-1';
        $item->location = 'pages/page-1/index.html';
        $item->order = 1;
        $jos->addItem($item);
        $jos->file = $tmp;

        $written = $jos->save();
        $this->assertNotFalse($written);
        $this->assertFileExists($tmp);

        $reloaded = new JSONOutlineSchema();
        $this->assertTrue($reloaded->load($tmp));
        $this->assertSame('Round trip', $reloaded->title);
        $this->assertSame('tester', $reloaded->author);
        $this->assertSame(1, count($reloaded->items));
        $found = $reloaded->getItemById('page-1');
        $this->assertNotFalse($found);
        $this->assertSame('Page One', $found->title);
        $this->assertSame('page-1', $found->slug);
        $this->assertSame('pages/page-1/index.html', $found->location);

        unlink($tmp);
    }

    public function testLoadReturnsFalseForMissingFile(): void
    {
        $jos = new JSONOutlineSchema();
        $this->assertFalse($jos->load('/nonexistent/path/site.json'));
    }

    public function testGetItemKeyByIdReturnsArrayKey(): void
    {
        $jos = new JSONOutlineSchema();
        $item = $jos->newItem();
        $item->id = 'k';
        $jos->addItem($item);
        $this->assertSame(0, $jos->getItemKeyById('k'));
        $this->assertFalse($jos->getItemKeyById('nope'));
    }

    public static function licenseProvider(): array
    {
        return [
            'by-sa default' => ['by-sa', 'Creative Commons: Attribution Share a like'],
            'by' => ['by', 'Creative Commons: Attribution'],
            'unknown license empty' => ['not-a-license', []],
        ];
    }

    #[DataProvider('licenseProvider')]
    public function testGetLicenseDetails(string $license, $expectedNameOrEmpty): void
    {
        $jos = new JSONOutlineSchema();
        $jos->license = $license;
        $details = $jos->getLicenseDetails();
        if ($expectedNameOrEmpty === []) {
            $this->assertSame([], $details);
        } else {
            $this->assertSame($expectedNameOrEmpty, $details['name']);
        }
    }

    public function testGetLicenseDetailsAllReturnsFullMap(): void
    {
        $jos = new JSONOutlineSchema();
        $all = $jos->getLicenseDetails(true);
        $this->assertArrayHasKey('by', $all);
        $this->assertArrayHasKey('by-sa', $all);
        $this->assertArrayHasKey('by-nc-nd', $all);
    }
}
