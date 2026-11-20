<?php

declare(strict_types=1);

use EndlessCreativity\ElephantPhp\Document\Document;
use EndlessCreativity\ElephantPhp\Document\DocumentConverter;
use EndlessCreativity\ElephantPhp\Document\Paragraph;
use EndlessCreativity\ElephantPhp\Document\Run;
use EndlessCreativity\ElephantPhp\Document\Text;

it('renders a paragraph with a run with text as <p>...</p>', function (): void {
    $document = new Document(children: [
        new Paragraph(children: [
            new Run(children: [new Text(value: 'Hello.')]),
        ]),
    ]);

    $result = (new DocumentConverter())->convertToHtml($document);

    expect($result->messages)->toBe([]);
    expect($result->value)->toBe('<p>Hello.</p>');
});

it('drops empty paragraphs by default', function (): void {
    $document = new Document(children: [new Paragraph()]);

    expect((new DocumentConverter())->convertToHtml($document)->value)->toBe('');
});

it('renders consecutive paragraphs as separate <p> elements', function (): void {
    $document = new Document(children: [
        new Paragraph(children: [new Run(children: [new Text(value: 'one')])]),
        new Paragraph(children: [new Run(children: [new Text(value: 'two')])]),
    ]);

    expect((new DocumentConverter())->convertToHtml($document)->value)
        ->toBe('<p>one</p><p>two</p>');
});

it('escapes html special characters in text', function (): void {
    $document = new Document(children: [
        new Paragraph(children: [new Run(children: [new Text(value: '<script>')])]),
    ]);

    expect((new DocumentConverter())->convertToHtml($document)->value)
        ->toBe('<p>&lt;script&gt;</p>');
});
