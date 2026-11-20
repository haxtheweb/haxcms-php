<?php

declare(strict_types=1);

use EndlessCreativity\ElephantPhp\Document\BreakElement;
use EndlessCreativity\ElephantPhp\Document\BreakType;
use EndlessCreativity\ElephantPhp\Document\Document;
use EndlessCreativity\ElephantPhp\Document\DocumentConverter;
use EndlessCreativity\ElephantPhp\Document\Paragraph;
use EndlessCreativity\ElephantPhp\Document\Run;
use EndlessCreativity\ElephantPhp\Document\Text;
use EndlessCreativity\ElephantPhp\Style\MatcherKind;
use EndlessCreativity\ElephantPhp\Style\StyleMap;
use EndlessCreativity\ElephantPhp\Style\StyleMapParser;

dataset('breakTypes', [
    'line' => ['line', BreakType::Line],
    'page' => ['page', BreakType::Page],
    'column' => ['column', BreakType::Column],
]);

it('parses br[type=...] as the BreakKind matcher carrying the BreakType', function (string $name, BreakType $type): void {
    $mapping = StyleMapParser::parse("br[type='{$name}'] => hr");

    expect($mapping->from->kind)->toBe(MatcherKind::BreakKind);
    expect($mapping->from->breakType)->toBe($type);
})->with('breakTypes');

it('rejects an unknown br type', function (): void {
    StyleMapParser::parse("br[type='gibberish'] => hr");
})->throws(InvalidArgumentException::class);

it('requires the type attribute on br', function (): void {
    StyleMapParser::parse('br => hr');
})->throws(InvalidArgumentException::class);

function htmlOfBreak(BreakElement $break, ?StyleMap $styleMap = null): string
{
    return (new DocumentConverter(styleMap: $styleMap))
        ->convertToHtml(new Document(children: [
            new Paragraph(children: [
                new Run(children: [new Text(value: 'a')]),
                $break,
                new Run(children: [new Text(value: 'b')]),
            ]),
        ]))
        ->value;
}

it('renders a page break as <hr> when br[type=page] is mapped', function (): void {
    $styleMap = StyleMap::default()->prepend([
        StyleMapParser::parse("br[type='page'] => hr"),
    ]);

    expect(htmlOfBreak(new BreakElement(breakType: BreakType::Page), $styleMap))
        ->toBe('<p>a<hr />b</p>');
});

it('still defaults a line break to <br> when no mapping is supplied', function (): void {
    expect(htmlOfBreak(new BreakElement(breakType: BreakType::Line)))
        ->toBe('<p>a<br />b</p>');
});

it('lets the user override the default line-break tag', function (): void {
    $styleMap = StyleMap::default()->prepend([
        StyleMapParser::parse("br[type='line'] => hr"),
    ]);

    expect(htmlOfBreak(new BreakElement(breakType: BreakType::Line), $styleMap))
        ->toBe('<p>a<hr />b</p>');
});
