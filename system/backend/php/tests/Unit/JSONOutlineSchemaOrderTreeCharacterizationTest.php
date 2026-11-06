<?php
use PHPUnit\Framework\TestCase;

/**
 * Characterization: the indexed orderTree walk must return exactly what the
 * original O(n^2) orderRecurse returned (same objects, same order) for any
 * outline, including ties, orphans, cycles, duplicate ids, filtered lists and
 * ids whose loose comparison differs from strict (numeric strings, ints,
 * empty strings), which must take the original path.
 *
 * The reference below is the pre-change orderTree/orderRecurse, verbatim
 * apart from being closures over the manifest items. Mirrors
 * haxcms-nodejs test/unit/json-outline-schema-order-tree.test.cjs.
 */
class JSONOutlineSchemaOrderTreeCharacterizationTest extends TestCase
{
    private $seed = 0;

    private function next(int $max): int
    {
        $this->seed = ($this->seed * 1103515245 + 12345) % 2147483648;
        return $this->seed % $max;
    }

    private static function referenceOrderRecurse($manifestItems, $currentItems, &$sorted, &$idList): void
    {
        foreach ($currentItems as $item) {
            if (!in_array($item->id, $idList, true)) {
                array_push($idList, $item->id);
                array_push($sorted, $item);
                $children = array();
                foreach ($manifestItems as $child) {
                    if ($child->parent == $item->id) {
                        array_push($children, $child);
                    }
                }
                usort($children, function ($a, $b) {
                    if ($a->order == $b->order) {
                        return 0;
                    }
                    return ($a->order < $b->order) ? -1 : 1;
                });
                if (count($children) > 0) {
                    self::referenceOrderRecurse($manifestItems, $children, $sorted, $idList);
                }
            }
        }
    }

    private static function referenceOrderTree($manifestItems, $items): array
    {
        $sorted = array();
        $idList = array();
        usort($items, function ($a, $b) {
            if ($a->order == $b->order) {
                return 0;
            }
            return ($a->order < $b->order) ? -1 : 1;
        });
        self::referenceOrderRecurse($manifestItems, $items, $sorted, $idList);
        if (count($sorted) == count($items)) {
            return $sorted;
        }
        return $items;
    }

    private function randomOutline(int $maxItems, int $orderRange, bool $mixedTypes): JSONOutlineSchema
    {
        $jos = new JSONOutlineSchema();
        $count = $this->next($maxItems) + 1;
        $ids = array();
        for ($i = 0; $i < $count; $i++) {
            // occasional duplicate ids
            $ids[] = ($this->next(20) === 0 && count($ids) > 0) ? $ids[$this->next(count($ids))] : 'item-' . $i;
        }
        for ($i = 0; $i < $count; $i++) {
            $item = $jos->newItem();
            $item->id = $ids[$i];
            $roll = $this->next(10);
            if ($roll < 3 || $i === 0) {
                $item->parent = ($roll === 0) ? '' : null; // root
            } elseif ($roll === 3) {
                $item->parent = 'missing-' . $this->next(5); // orphan
            } elseif ($roll === 4) {
                $item->parent = $ids[$this->next($count)]; // anywhere: cycles, self-parent
            } else {
                $item->parent = $ids[$this->next($i)]; // earlier item: a real tree
            }
            // narrow order range so ties are common; some null
            $item->order = ($this->next(12) === 0) ? null : $this->next($orderRange) - 1;
            if ($mixedTypes && $this->next(6) === 0) {
                // loose == differs from === for these, so the original path runs
                $variants = array($i, (string) $i, '0' . $i, '', ' ' . $i);
                $pick = $variants[$this->next(count($variants))];
                if ($this->next(2) === 0) {
                    $item->id = $pick;
                } else {
                    $item->parent = $this->next(2) === 0 ? $pick : $this->next(3);
                }
            }
            $jos->items[] = $item;
        }
        return $jos;
    }

    private function assertSameOrder(JSONOutlineSchema $jos, array $items, string $label): void
    {
        $expected = self::referenceOrderTree($jos->items, $items);
        $actual = $jos->orderTree($items);
        $this->assertCount(count($expected), $actual, $label . ' length');
        foreach ($expected as $i => $item) {
            $this->assertSame($item, $actual[$i], $label . ' position ' . $i);
        }
    }

    public function testMatchesTheOriginalAlgorithmOnRandomOutlines(): void
    {
        $this->seed = 20261106;
        for ($run = 0; $run < 2000; $run++) {
            $jos = $this->randomOutline(40, 4, false);
            $this->assertSameOrder($jos, $jos->items, 'full run ' . $run);
            // routes order filtered lists; children still come from the manifest
            $subset = array_values(array_filter($jos->items, function () {
                return $this->next(3) !== 0;
            }));
            $this->assertSameOrder($jos, $subset, 'subset run ' . $run);
        }
    }

    public function testMatchesTheOriginalAlgorithmWithLooseComparisonIds(): void
    {
        $this->seed = 31415;
        for ($run = 0; $run < 1500; $run++) {
            $jos = $this->randomOutline(30, 3, true);
            $this->assertSameOrder($jos, $jos->items, 'mixed run ' . $run);
        }
    }

    public function testMatchesTheOriginalAlgorithmOnALargeDeepOutline(): void
    {
        $this->seed = 4242;
        $jos = new JSONOutlineSchema();
        for ($i = 0; $i < 2000; $i++) {
            $item = $jos->newItem();
            $item->id = 'page-' . $i;
            $item->parent = ($i === 0 || $this->next(8) === 0)
                ? null
                : 'page-' . ($i - 1 - $this->next(min($i, 5)));
            $item->order = $this->next(6);
            $jos->items[] = $item;
        }
        $this->assertSameOrder($jos, $jos->items, 'large');
    }

    public function testNumericStringIdsTakeTheOriginalPath(): void
    {
        // "1" == "01" under PHP 8 loose comparison: the child whose parent is
        // "01" belongs under page "1", which only the original walk sees
        $jos = new JSONOutlineSchema();
        foreach (array(array('1', null, 0), array('kid', '01', 0), array('2', null, 1)) as $row) {
            $item = $jos->newItem();
            $item->id = $row[0];
            $item->parent = $row[1];
            $item->order = $row[2];
            $jos->items[] = $item;
        }
        $this->assertSameOrder($jos, $jos->items, 'numeric strings');
    }
}
