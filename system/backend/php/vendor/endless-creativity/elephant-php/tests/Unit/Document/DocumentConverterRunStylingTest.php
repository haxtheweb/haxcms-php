<?php

declare(strict_types=1);

use EndlessCreativity\ElephantPhp\Document\Document;
use EndlessCreativity\ElephantPhp\Document\DocumentConverter;
use EndlessCreativity\ElephantPhp\Document\Paragraph;
use EndlessCreativity\ElephantPhp\Document\Run;
use EndlessCreativity\ElephantPhp\Document\Text;
use EndlessCreativity\ElephantPhp\Document\VerticalAlignment;

function convert(Run $run): string
{
    $document = new Document(children: [new Paragraph(children: [$run])]);

    return (new DocumentConverter())->convertToHtml($document)->value;
}

it('wraps a bold run in <strong>', function (): void {
    $run = new Run(children: [new Text(value: 'hi')], isBold: true);

    expect(convert($run))->toBe('<p><strong>hi</strong></p>');
});

it('wraps an italic run in <em>', function (): void {
    $run = new Run(children: [new Text(value: 'hi')], isItalic: true);

    expect(convert($run))->toBe('<p><em>hi</em></p>');
});

it('wraps a strikethrough run in <s>', function (): void {
    $run = new Run(children: [new Text(value: 'hi')], isStrikethrough: true);

    expect(convert($run))->toBe('<p><s>hi</s></p>');
});

it('wraps a superscript run in <sup>', function (): void {
    $run = new Run(children: [new Text(value: 'hi')], verticalAlignment: VerticalAlignment::Superscript);

    expect(convert($run))->toBe('<p><sup>hi</sup></p>');
});

it('wraps a subscript run in <sub>', function (): void {
    $run = new Run(children: [new Text(value: 'hi')], verticalAlignment: VerticalAlignment::Subscript);

    expect(convert($run))->toBe('<p><sub>hi</sub></p>');
});

it('does not wrap an underline run when no style map is configured', function (): void {
    $run = new Run(children: [new Text(value: 'hi')], isUnderline: true);

    expect(convert($run))->toBe('<p>hi</p>');
});

it('layers wrappers from outermost bold inwards: bold > italic > sub|sup > strike', function (): void {
    $run = new Run(
        children: [new Text(value: 'hi')],
        isBold: true,
        isItalic: true,
        isStrikethrough: true,
        verticalAlignment: VerticalAlignment::Subscript,
    );

    expect(convert($run))->toBe('<p><strong><em><sub><s>hi</s></sub></em></strong></p>');
});

it('merges adjacent same-style runs into a single non-fresh wrapper', function (): void {
    $document = new Document(children: [new Paragraph(children: [
        new Run(children: [new Text(value: 'a')], isBold: true),
        new Run(children: [new Text(value: 'b')], isBold: true),
    ])]);

    expect((new DocumentConverter())->convertToHtml($document)->value)
        ->toBe('<p><strong>ab</strong></p>');
});
