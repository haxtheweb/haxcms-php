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
use EndlessCreativity\ElephantPhp\Style\MatcherKind;
use EndlessCreativity\ElephantPhp\Style\StyleMap;
use EndlessCreativity\ElephantPhp\Style\StyleMapParser;

it('parses bare table as the Table matcher with no style filter', function (): void {
    $mapping = StyleMapParser::parse('table => table.fancy');

    expect($mapping->from->kind)->toBe(MatcherKind::Table);
    expect($mapping->from->styleId)->toBeNull();
    expect($mapping->from->styleName)->toBeNull();
});

it("parses table.X / table[style-name='X'] as styleId and styleName matchers", function (): void {
    $a = StyleMapParser::parse('table.Grid => table.fancy');
    $b = StyleMapParser::parse("table[style-name='Grid'] => table.fancy");

    expect($a->from->styleId)->toBe('Grid');
    expect($b->from->styleName)->toBe('Grid');
});

/**
 * @param  list<\EndlessCreativity\ElephantPhp\Document\Node>  $rows
 */
function tableWith(array $rows, ?string $styleId = null, ?string $styleName = null): Table
{
    return new Table(children: $rows, styleId: $styleId, styleName: $styleName);
}

function htmlOfStyledTable(Table $table, ?StyleMap $styleMap = null): string
{
    return (new DocumentConverter(styleMap: $styleMap))
        ->convertToHtml(new Document(children: [$table]))
        ->value;
}

$row = new TableRow(children: [new TableCell(children: [
    new Paragraph(children: [new Run(children: [new Text(value: 'x')])]),
])]);

it('renders a styled table with the user-supplied path', function () use ($row): void {
    $styleMap = StyleMap::default()->prepend([
        StyleMapParser::parse('table.Grid => table.fancy'),
    ]);

    $table = tableWith([$row], styleId: 'Grid');

    expect(htmlOfStyledTable($table, $styleMap))
        ->toBe('<table class="fancy"><tr><td><p>x</p></td></tr></table>');
});

it('warns when a table has a styleId but no matching mapping', function (): void {
    $row = new TableRow(children: [new TableCell(children: [
        new Paragraph(children: [new Run(children: [new Text(value: 'x')])]),
    ])]);
    $table = tableWith([$row], styleId: 'Mystery');

    $result = (new DocumentConverter())->convertToHtml(new Document(children: [$table]));

    expect($result->messages)->toHaveCount(1);
    expect($result->messages[0]->message)
        ->toBe("Unrecognised table style: '' (Style ID: Mystery)");
});

it('still emits the default <table> when no style is set or mapped', function () use ($row): void {
    $table = tableWith([$row]);

    expect(htmlOfStyledTable($table))
        ->toBe('<table><tr><td><p>x</p></td></tr></table>');
});
