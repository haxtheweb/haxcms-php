<?php

declare(strict_types=1);

use EndlessCreativity\ElephantPhp\Document\BookmarkStart;
use EndlessCreativity\ElephantPhp\Document\BreakElement;
use EndlessCreativity\ElephantPhp\Document\BreakType;
use EndlessCreativity\ElephantPhp\Document\Document;
use EndlessCreativity\ElephantPhp\Document\DocumentConverter;
use EndlessCreativity\ElephantPhp\Document\Paragraph;
use EndlessCreativity\ElephantPhp\Document\Run;
use EndlessCreativity\ElephantPhp\Document\Tab;
use EndlessCreativity\ElephantPhp\Document\Text;

/**
 * @param  list<\EndlessCreativity\ElephantPhp\Document\Node>  $children
 */
function htmlOfRunChildren(array $children): string
{
    return (new DocumentConverter())
        ->convertToHtml(new Document(children: [new Paragraph(children: [
            new Run(children: $children),
        ])]))
        ->value;
}

it('renders a Tab as a literal tab character in text', function (): void {
    expect(htmlOfRunChildren([
        new Text(value: 'before'),
        new Tab(),
        new Text(value: 'after'),
    ]))->toBe("<p>before\tafter</p>");
});

it('renders a line break as <br />', function (): void {
    expect(htmlOfRunChildren([
        new Text(value: 'a'),
        new BreakElement(breakType: BreakType::Line),
        new Text(value: 'b'),
    ]))->toBe('<p>a<br />b</p>');
});

it('drops a page break by default', function (): void {
    expect(htmlOfRunChildren([
        new Text(value: 'a'),
        new BreakElement(breakType: BreakType::Page),
        new Text(value: 'b'),
    ]))->toBe('<p>ab</p>');
});

it('drops a column break by default', function (): void {
    expect(htmlOfRunChildren([
        new Text(value: 'a'),
        new BreakElement(breakType: BreakType::Column),
        new Text(value: 'b'),
    ]))->toBe('<p>ab</p>');
});

it('renders a BookmarkStart as <a id> kept alive even when empty', function (): void {
    $document = new Document(children: [
        new Paragraph(children: [
            new BookmarkStart(name: 'top'),
            new Run(children: [new Text(value: 'after')]),
        ]),
    ]);

    expect((new DocumentConverter())->convertToHtml($document)->value)
        ->toBe('<p><a id="top"></a>after</p>');
});
