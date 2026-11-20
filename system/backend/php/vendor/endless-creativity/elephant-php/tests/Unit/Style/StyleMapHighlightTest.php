<?php

declare(strict_types=1);

use EndlessCreativity\ElephantPhp\Document\Document;
use EndlessCreativity\ElephantPhp\Document\DocumentConverter;
use EndlessCreativity\ElephantPhp\Document\Paragraph;
use EndlessCreativity\ElephantPhp\Document\Run;
use EndlessCreativity\ElephantPhp\Document\Text;
use EndlessCreativity\ElephantPhp\Style\MatcherKind;
use EndlessCreativity\ElephantPhp\Style\StyleMap;
use EndlessCreativity\ElephantPhp\Style\StyleMapParser;

it('parses bare highlight as the Highlight matcher with null color', function (): void {
    $mapping = StyleMapParser::parse('highlight => mark');

    expect($mapping->from->kind)->toBe(MatcherKind::Highlight);
    expect($mapping->from->highlightColor)->toBeNull();
});

it("parses highlight[color='X'] as the Highlight matcher with that color", function (): void {
    $mapping = StyleMapParser::parse("highlight[color='yellow'] => mark");

    expect($mapping->from->highlightColor)->toBe('yellow');
});

function htmlOfHighlightedRun(?string $highlight, ?StyleMap $styleMap = null): string
{
    return (new DocumentConverter(styleMap: $styleMap))
        ->convertToHtml(new Document(children: [
            new Paragraph(children: [
                new Run(children: [new Text(value: 'x')], highlight: $highlight),
            ]),
        ]))
        ->value;
}

it('drops highlight by default (no DSL mapping)', function (): void {
    expect(htmlOfHighlightedRun('yellow'))->toBe('<p>x</p>');
});

it('wraps a highlighted run in the path of a bare highlight matcher', function (): void {
    $styleMap = StyleMap::default()->prepend([StyleMapParser::parse('highlight => mark')]);

    expect(htmlOfHighlightedRun('yellow', $styleMap))->toBe('<p><mark>x</mark></p>');
});

it('only matches a highlight[color] mapping when the colors match', function (): void {
    $styleMap = StyleMap::default()->prepend([
        StyleMapParser::parse("highlight[color='yellow'] => mark.yellow"),
    ]);

    expect(htmlOfHighlightedRun('yellow', $styleMap))
        ->toBe('<p><mark class="yellow">x</mark></p>');
    expect(htmlOfHighlightedRun('green', $styleMap))->toBe('<p>x</p>');
});

it('wraps highlight as the innermost wrapper, with bold outside it', function (): void {
    $styleMap = StyleMap::default()->prepend([StyleMapParser::parse('highlight => mark')]);
    $document = new Document(children: [new Paragraph(children: [
        new Run(children: [new Text(value: 'x')], isBold: true, highlight: 'yellow'),
    ])]);

    expect((new DocumentConverter(styleMap: $styleMap))->convertToHtml($document)->value)
        ->toBe('<p><strong><mark>x</mark></strong></p>');
});
