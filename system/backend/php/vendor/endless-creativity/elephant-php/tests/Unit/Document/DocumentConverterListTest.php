<?php

declare(strict_types=1);

use EndlessCreativity\ElephantPhp\Document\Document;
use EndlessCreativity\ElephantPhp\Document\DocumentConverter;
use EndlessCreativity\ElephantPhp\Document\NumberingLevel;
use EndlessCreativity\ElephantPhp\Document\Paragraph;
use EndlessCreativity\ElephantPhp\Document\Run;
use EndlessCreativity\ElephantPhp\Document\Text;

function listItem(int $level, bool $isOrdered, string $text, ?int $start = null): Paragraph
{
    return new Paragraph(
        children: [new Run(children: [new Text(value: $text)])],
        numbering: new NumberingLevel(level: $level, isOrdered: $isOrdered, start: $start),
    );
}

function htmlOf(Paragraph ...$paragraphs): string
{
    return (new DocumentConverter())
        ->convertToHtml(new Document(children: array_values($paragraphs)))
        ->value;
}

it('renders a single bullet item as <ul><li>...</li></ul>', function (): void {
    expect(htmlOf(listItem(level: 0, isOrdered: false, text: 'Apple')))
        ->toBe('<ul><li>Apple</li></ul>');
});

it('renders a single ordered item as <ol><li>...</li></ol>', function (): void {
    expect(htmlOf(listItem(level: 0, isOrdered: true, text: 'first')))
        ->toBe('<ol><li>first</li></ol>');
});

it('merges consecutive same-kind level-0 items into one <ul>', function (): void {
    expect(htmlOf(
        listItem(level: 0, isOrdered: false, text: 'Apple'),
        listItem(level: 0, isOrdered: false, text: 'Banana'),
    ))->toBe('<ul><li>Apple</li><li>Banana</li></ul>');
});

it('keeps unordered and ordered lists as separate sibling lists', function (): void {
    expect(htmlOf(
        listItem(level: 0, isOrdered: false, text: 'A'),
        listItem(level: 0, isOrdered: true, text: 'B'),
    ))->toBe('<ul><li>A</li></ul><ol><li>B</li></ol>');
});

it('nests a level-1 item inside the previous level-0 item', function (): void {
    expect(htmlOf(
        listItem(level: 0, isOrdered: false, text: 'A'),
        listItem(level: 1, isOrdered: false, text: 'a1'),
    ))->toBe('<ul><li>A<ul><li>a1</li></ul></li></ul>');
});

it('keeps multiple consecutive nested items inside the same nested list', function (): void {
    expect(htmlOf(
        listItem(level: 0, isOrdered: false, text: 'A'),
        listItem(level: 1, isOrdered: false, text: 'a1'),
        listItem(level: 1, isOrdered: false, text: 'a2'),
        listItem(level: 0, isOrdered: false, text: 'B'),
    ))->toBe('<ul><li>A<ul><li>a1</li><li>a2</li></ul></li><li>B</li></ul>');
});

it('nests an ordered child inside an unordered parent (mixed kinds)', function (): void {
    expect(htmlOf(
        listItem(level: 0, isOrdered: false, text: 'A'),
        listItem(level: 1, isOrdered: true, text: 'a1'),
        listItem(level: 1, isOrdered: true, text: 'a2'),
        listItem(level: 0, isOrdered: false, text: 'B'),
    ))->toBe('<ul><li>A<ol><li>a1</li><li>a2</li></ol></li><li>B</li></ul>');
});

it('nests an unordered child inside an ordered parent (mixed kinds)', function (): void {
    expect(htmlOf(
        listItem(level: 0, isOrdered: true, text: 'A'),
        listItem(level: 1, isOrdered: false, text: 'a1'),
    ))->toBe('<ol><li>A<ul><li>a1</li></ul></li></ol>');
});

it('breaks the list when a non-list paragraph appears between items', function (): void {
    $paragraph = new Paragraph(children: [new Run(children: [new Text(value: 'between')])]);

    expect(htmlOf(
        listItem(level: 0, isOrdered: false, text: 'A'),
        $paragraph,
        listItem(level: 0, isOrdered: false, text: 'B'),
    ))->toBe('<ul><li>A</li></ul><p>between</p><ul><li>B</li></ul>');
});

it('emits start="N" on <ol> when the numbering level declares an explicit start > 1', function (): void {
    // Word's exporter sometimes splits "1., 2., 3." into one
    // single-item abstractNum per line, each with its own <w:start>.
    // We forward that start as an attribute so the HTML renders the
    // intended number instead of restarting at 1.
    expect(htmlOf(listItem(level: 0, isOrdered: true, text: 'second', start: 2)))
        ->toBe('<ol start="2"><li>second</li></ol>');
});

it('omits start when start is 1 (the implicit default)', function (): void {
    // Avoid attribute noise when the declared start matches the default.
    expect(htmlOf(listItem(level: 0, isOrdered: true, text: 'first', start: 1)))
        ->toBe('<ol><li>first</li></ol>');
});

it('does not put a start attribute on <ul> even if the level carries one', function (): void {
    // start only makes sense for ordered lists.
    expect(htmlOf(listItem(level: 0, isOrdered: false, text: 'bullet', start: 5)))
        ->toBe('<ul><li>bullet</li></ul>');
});

it('keeps two adjacent ordered lists separate when their starts differ', function (): void {
    // Without a guard the simplifier would fold these into one <ol>
    // and the second start value would be lost.
    expect(htmlOf(
        listItem(level: 0, isOrdered: true, text: 'first', start: 1),
        listItem(level: 0, isOrdered: true, text: 'second', start: 2),
    ))->toBe('<ol><li>first</li></ol><ol start="2"><li>second</li></ol>');
});
