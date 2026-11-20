<?php

declare(strict_types=1);

use EndlessCreativity\ElephantPhp\Document\Document;
use EndlessCreativity\ElephantPhp\Document\DocumentConverter;
use EndlessCreativity\ElephantPhp\Document\Paragraph;
use EndlessCreativity\ElephantPhp\Document\Run;
use EndlessCreativity\ElephantPhp\Document\Table;
use EndlessCreativity\ElephantPhp\Document\TableCell;
use EndlessCreativity\ElephantPhp\Document\TableRow;
use EndlessCreativity\ElephantPhp\Document\Text;

function cellWith(string $text): TableCell
{
    return new TableCell(children: [
        new Paragraph(children: [new Run(children: [new Text(value: $text)])]),
    ]);
}

function htmlOfTable(Table $table): string
{
    return (new DocumentConverter())
        ->convertToHtml(new Document(children: [$table]))
        ->value;
}

it('renders a 2x2 table as <table><tr><td><p>...</p></td>...</tr></table>', function (): void {
    $table = new Table(children: [
        new TableRow(children: [cellWith('A'), cellWith('B')]),
        new TableRow(children: [cellWith('C'), cellWith('D')]),
    ]);

    expect(htmlOfTable($table))->toBe(
        '<table>'
        .'<tr><td><p>A</p></td><td><p>B</p></td></tr>'
        .'<tr><td><p>C</p></td><td><p>D</p></td></tr>'
        .'</table>',
    );
});

it('wraps header rows in <thead> and body rows in <tbody>', function (): void {
    $table = new Table(children: [
        new TableRow(children: [cellWith('h1'), cellWith('h2')], isHeader: true),
        new TableRow(children: [cellWith('a'), cellWith('b')]),
    ]);

    expect(htmlOfTable($table))->toBe(
        '<table>'
        .'<thead><tr><th><p>h1</p></th><th><p>h2</p></th></tr></thead>'
        .'<tbody><tr><td><p>a</p></td><td><p>b</p></td></tr></tbody>'
        .'</table>',
    );
});

it('emits only <thead> when there are no body rows after the headers', function (): void {
    $table = new Table(children: [
        new TableRow(children: [cellWith('h1'), cellWith('h2')], isHeader: true),
    ]);

    expect(htmlOfTable($table))->toBe(
        '<table>'
        .'<thead><tr><th><p>h1</p></th><th><p>h2</p></th></tr></thead>'
        .'</table>',
    );
});

it('skips the thead/tbody split when the first row is not a header', function (): void {
    $table = new Table(children: [
        new TableRow(children: [cellWith('a'), cellWith('b')]),
        new TableRow(children: [cellWith('h1'), cellWith('h2')], isHeader: true),
    ]);

    // Mammoth's bodyIndex == 0 fast path: no thead/tbody, but the header
    // row's cells still render as <th>.
    expect(htmlOfTable($table))->toBe(
        '<table>'
        .'<tr><td><p>a</p></td><td><p>b</p></td></tr>'
        .'<tr><th><p>h1</p></th><th><p>h2</p></th></tr>'
        .'</table>',
    );
});

it('emits colspan and rowspan only when greater than 1', function (): void {
    $table = new Table(children: [
        new TableRow(children: [
            new TableCell(children: [new Paragraph(children: [new Run(children: [new Text(value: 'wide')])])], colSpan: 2),
        ]),
        new TableRow(children: [cellWith('a'), cellWith('b')]),
    ]);

    expect(htmlOfTable($table))->toBe(
        '<table>'
        .'<tr><td colspan="2"><p>wide</p></td></tr>'
        .'<tr><td><p>a</p></td><td><p>b</p></td></tr>'
        .'</table>',
    );
});

it('emits rowspan for a vertically merged anchor cell', function (): void {
    $table = new Table(children: [
        new TableRow(children: [
            new TableCell(children: [new Paragraph(children: [new Run(children: [new Text(value: 'tall')])])], rowSpan: 2),
            cellWith('a'),
        ]),
        new TableRow(children: [cellWith('b')]),
    ]);

    expect(htmlOfTable($table))->toBe(
        '<table>'
        .'<tr><td rowspan="2"><p>tall</p></td><td><p>a</p></td></tr>'
        .'<tr><td><p>b</p></td></tr>'
        .'</table>',
    );
});

it('preserves empty cells so columns stay aligned', function (): void {
    $table = new Table(children: [
        new TableRow(children: [cellWith(''), cellWith('Top right')]),
    ]);

    expect(htmlOfTable($table))->toBe(
        '<table><tr><td></td><td><p>Top right</p></td></tr></table>',
    );
});

it('preserves empty rows', function (): void {
    $table = new Table(children: [
        new TableRow(children: [cellWith('Row 1')]),
        new TableRow(children: []),
    ]);

    expect(htmlOfTable($table))->toBe(
        '<table><tr><td><p>Row 1</p></td></tr><tr></tr></table>',
    );
});

it('preserves an empty table', function (): void {
    expect(htmlOfTable(new Table(children: [])))->toBe('<table></table>');
});

it('preserves empty header cells', function (): void {
    $table = new Table(children: [
        new TableRow(children: [cellWith(''), cellWith('h')], isHeader: true),
        new TableRow(children: [cellWith('a'), cellWith('b')]),
    ]);

    expect(htmlOfTable($table))->toBe(
        '<table>'
        .'<thead><tr><th></th><th><p>h</p></th></tr></thead>'
        .'<tbody><tr><td><p>a</p></td><td><p>b</p></td></tr></tbody>'
        .'</table>',
    );
});
