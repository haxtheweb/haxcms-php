<?php

declare(strict_types=1);

use EndlessCreativity\ElephantPhp\Document\Document;
use EndlessCreativity\ElephantPhp\Document\DocumentConverter;
use EndlessCreativity\ElephantPhp\Document\Paragraph;
use EndlessCreativity\ElephantPhp\Document\Run;
use EndlessCreativity\ElephantPhp\Document\Text;

function emptyDoc(): Document
{
    // Two non-empty paragraphs around an empty one. The empty paragraph
    // is what changes shape with ignoreEmptyParagraphs.
    return new Document(children: [
        new Paragraph(children: [new Run(children: [new Text(value: 'before')])]),
        new Paragraph(children: []),
        new Paragraph(children: [new Run(children: [new Text(value: 'after')])]),
    ]);
}

it('drops empty paragraphs by default (ignoreEmptyParagraphs=true)', function (): void {
    $html = (new DocumentConverter())->convertToHtml(emptyDoc())->value;

    expect($html)->toBe('<p>before</p><p>after</p>');
});

it('preserves empty paragraphs as <p></p> when ignoreEmptyParagraphs=false', function (): void {
    $html = (new DocumentConverter(ignoreEmptyParagraphs: false))->convertToHtml(emptyDoc())->value;

    expect($html)->toBe('<p>before</p><p></p><p>after</p>');
});

it('preserves an empty paragraph that contains only a run with no text', function (): void {
    // Word emits these as `<w:p><w:r/></w:p>` (a run with no <w:t>) for
    // explicit blank lines; the simplifier treats them as empty too.
    $document = new Document(children: [
        new Paragraph(children: [new Run(children: [])]),
    ]);

    $html = (new DocumentConverter(ignoreEmptyParagraphs: false))->convertToHtml($document)->value;

    expect($html)->toBe('<p></p>');
});
