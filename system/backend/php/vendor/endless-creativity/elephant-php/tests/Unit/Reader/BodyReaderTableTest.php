<?php

declare(strict_types=1);

use EndlessCreativity\ElephantPhp\Document\Node as DocumentNode;
use EndlessCreativity\ElephantPhp\Document\Table;
use EndlessCreativity\ElephantPhp\Document\TableCell;
use EndlessCreativity\ElephantPhp\Document\TableRow;
use EndlessCreativity\ElephantPhp\Reader\BodyReader;
use EndlessCreativity\ElephantPhp\Reader\Xml\Element;

/**
 * @param  list<Element>  $properties
 */
function tableCellXml(array $properties = []): Element
{
    $children = [];
    if ($properties !== []) {
        $children[] = new Element(name: 'w:tcPr', children: $properties);
    }

    return new Element(name: 'w:tc', children: $children);
}

function readTable(Element $tableXml): Table
{
    $reader = new BodyReader();
    $result = $reader->readXmlElement($tableXml);

    if (! $result->value instanceof Table) {
        throw new RuntimeException('Expected a Table, got '.get_debug_type($result->value));
    }

    return $result->value;
}

/**
 * @return list<TableRow>
 */
function rowsOf(Table $table): array
{
    return array_map(
        static function (DocumentNode $row): TableRow {
            if (! $row instanceof TableRow) {
                throw new RuntimeException('Expected TableRow, got '.get_debug_type($row));
            }

            return $row;
        },
        $table->children,
    );
}

/**
 * @return list<TableCell>
 */
function cellsOf(TableRow $row): array
{
    return array_map(
        static function (DocumentNode $cell): TableCell {
            if (! $cell instanceof TableCell) {
                throw new RuntimeException('Expected TableCell, got '.get_debug_type($cell));
            }

            return $cell;
        },
        $row->children,
    );
}

it('reads a table with rows and cells', function (): void {
    $tableXml = new Element(name: 'w:tbl', children: [
        new Element(name: 'w:tr', children: [tableCellXml(), tableCellXml()]),
        new Element(name: 'w:tr', children: [tableCellXml(), tableCellXml()]),
    ]);

    $rows = rowsOf(readTable($tableXml));

    expect($rows)->toHaveCount(2);
    expect(cellsOf($rows[0]))->toHaveCount(2);
});

it('reads w:gridSpan as colSpan', function (): void {
    $tableXml = new Element(name: 'w:tbl', children: [
        new Element(name: 'w:tr', children: [
            tableCellXml(properties: [
                new Element(name: 'w:gridSpan', attributes: ['w:val' => '3']),
            ]),
        ]),
    ]);

    $cell = cellsOf(rowsOf(readTable($tableXml))[0])[0];

    expect($cell->colSpan)->toBe(3);
    expect($cell->rowSpan)->toBe(1);
});

it('treats w:tblHeader on a row as a header row', function (): void {
    $tableXml = new Element(name: 'w:tbl', children: [
        new Element(name: 'w:tr', children: [
            new Element(name: 'w:trPr', children: [new Element(name: 'w:tblHeader')]),
            tableCellXml(),
        ]),
        new Element(name: 'w:tr', children: [tableCellXml()]),
    ]);

    $rows = rowsOf(readTable($tableXml));

    expect($rows[0]->isHeader)->toBeTrue();
    expect($rows[1]->isHeader)->toBeFalse();
});

it('drops a row marked as deleted', function (): void {
    $tableXml = new Element(name: 'w:tbl', children: [
        new Element(name: 'w:tr', children: [
            new Element(name: 'w:trPr', children: [new Element(name: 'w:del')]),
            tableCellXml(),
        ]),
        new Element(name: 'w:tr', children: [tableCellXml()]),
    ]);

    expect(rowsOf(readTable($tableXml)))->toHaveCount(1);
});

it('resolves vertical merges into rowSpan on the anchor cell', function (): void {
    $vMergeStart = tableCellXml(properties: [new Element(name: 'w:vMerge', attributes: ['w:val' => 'restart'])]);
    $vMergeContinue = tableCellXml(properties: [new Element(name: 'w:vMerge')]);

    $tableXml = new Element(name: 'w:tbl', children: [
        new Element(name: 'w:tr', children: [tableCellXml(), $vMergeStart]),
        new Element(name: 'w:tr', children: [tableCellXml(), $vMergeContinue]),
        new Element(name: 'w:tr', children: [tableCellXml(), $vMergeContinue]),
    ]);

    $rows = rowsOf(readTable($tableXml));
    $row0Cells = cellsOf($rows[0]);
    expect($row0Cells)->toHaveCount(2);
    expect($row0Cells[1]->rowSpan)->toBe(3);
    expect(cellsOf($rows[1]))->toHaveCount(1);
    expect(cellsOf($rows[2]))->toHaveCount(1);
});

it('clears the transient vMerge field after row-span resolution', function (): void {
    $tableXml = new Element(name: 'w:tbl', children: [
        new Element(name: 'w:tr', children: [
            tableCellXml(properties: [new Element(name: 'w:vMerge', attributes: ['w:val' => 'restart'])]),
        ]),
    ]);

    expect(cellsOf(rowsOf(readTable($tableXml))[0])[0]->vMerge)->toBeNull();
});

it('merges a vMerge continue into the cell directly above even when that cell was not a restart', function (): void {
    // Word's vMerge resolution is column-position-based: a continue cell
    // in row N at column C joins the cell in row N-1 at column C
    // regardless of whether that cell was declared as a restart. The
    // orphan continue ends up dropped, the cell above gains rowSpan=2.
    // (Same as mammoth: simpler than tracking explicit anchors and
    // matches how Word visually renders these malformed tables.)
    $tableXml = new Element(name: 'w:tbl', children: [
        new Element(name: 'w:tr', children: [tableCellXml()]),
        new Element(name: 'w:tr', children: [
            tableCellXml(properties: [new Element(name: 'w:vMerge')]),
        ]),
    ]);

    $rows = rowsOf(readTable($tableXml));
    expect($rows)->toHaveCount(2);
    expect(cellsOf($rows[0]))->toHaveCount(1);
    expect(cellsOf($rows[0])[0]->rowSpan)->toBe(2);
    expect(cellsOf($rows[1]))->toHaveCount(0);
});

it('leaves a lone vMerge restart with rowSpan=1 when no continue follows it in any row below', function (): void {
    // Row 0: cell A, cell B (declared as vMerge restart)
    // Row 1: nothing in column 1.
    // The restart never gets a partner; its rowSpan stays at 1.
    $tableXml = new Element(name: 'w:tbl', children: [
        new Element(name: 'w:tr', children: [
            tableCellXml(),
            tableCellXml(properties: [new Element(name: 'w:vMerge', attributes: ['w:val' => 'restart'])]),
        ]),
        new Element(name: 'w:tr', children: [tableCellXml()]),
    ]);

    $rows = rowsOf(readTable($tableXml));
    expect($rows)->toHaveCount(2);
    expect(cellsOf($rows[0])[1]->rowSpan)->toBe(1);
    expect(cellsOf($rows[1]))->toHaveCount(1);
});
