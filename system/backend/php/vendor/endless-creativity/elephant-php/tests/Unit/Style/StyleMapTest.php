<?php

declare(strict_types=1);

use EndlessCreativity\ElephantPhp\Document\Document;
use EndlessCreativity\ElephantPhp\Document\DocumentConverter;
use EndlessCreativity\ElephantPhp\Document\Paragraph;
use EndlessCreativity\ElephantPhp\Document\Run;
use EndlessCreativity\ElephantPhp\Document\Text;
use EndlessCreativity\ElephantPhp\Style\StyleMap;
use EndlessCreativity\ElephantPhp\Style\StyleMapParser;

it('default style map renders Heading 1 as <h1>', function (): void {
    $document = new Document(children: [
        new Paragraph(
            children: [new Run(children: [new Text(value: 'Hello.')])],
            styleId: 'Heading1',
            styleName: 'Heading 1',
        ),
    ]);

    $result = (new DocumentConverter())->convertToHtml($document);

    expect($result->value)->toBe('<h1>Hello.</h1>');
});

it('user style map prepended to defaults wins over them', function (): void {
    $custom = StyleMap::default()->prepend([
        StyleMapParser::parse("p[style-name='Heading 1'] => h1.fancy:fresh"),
    ]);

    $document = new Document(children: [
        new Paragraph(
            children: [new Run(children: [new Text(value: 'Hi')])],
            styleId: 'Heading1',
            styleName: 'Heading 1',
        ),
    ]);

    $result = (new DocumentConverter(styleMap: $custom))->convertToHtml($document);

    expect($result->value)->toBe('<h1 class="fancy">Hi</h1>');
});

it('user mapping with ignore path drops the paragraph entirely', function (): void {
    $custom = StyleMap::default()->prepend([
        StyleMapParser::parse("p[style-name='Hidden'] => !"),
    ]);

    $document = new Document(children: [
        new Paragraph(
            children: [new Run(children: [new Text(value: 'secret')])],
            styleName: 'Hidden',
        ),
    ]);

    $result = (new DocumentConverter(styleMap: $custom))->convertToHtml($document);

    expect($result->value)->toBe('');
});

it('user run mapping wraps the existing inline-style wrappers', function (): void {
    $custom = StyleMap::default()->prepend([
        StyleMapParser::parse("r[style-name='Code'] => code"),
    ]);

    $document = new Document(children: [new Paragraph(children: [
        new Run(
            children: [new Text(value: 'x')],
            styleName: 'Code',
            isBold: true,
        ),
    ])]);

    $result = (new DocumentConverter(styleMap: $custom))->convertToHtml($document);

    expect($result->value)->toBe('<p><code><strong>x</strong></code></p>');
});
