<?php

declare(strict_types=1);

use EndlessCreativity\ElephantPhp\Document\Checkbox;
use EndlessCreativity\ElephantPhp\Document\Document;
use EndlessCreativity\ElephantPhp\Document\DocumentConverter;
use EndlessCreativity\ElephantPhp\Document\Paragraph;
use EndlessCreativity\ElephantPhp\Document\Run;
use EndlessCreativity\ElephantPhp\Document\Text;

function htmlOfDoc(Document $doc): string
{
    return (new DocumentConverter())->convertToHtml($doc)->value;
}

function mdOfDoc(Document $doc): string
{
    return (new DocumentConverter())->convertToMarkdown($doc)->value;
}

it('renders an unchecked checkbox as <input type="checkbox" /> in HTML', function (): void {
    $doc = new Document(children: [
        new Paragraph(children: [new Checkbox()]),
    ]);

    expect(htmlOfDoc($doc))->toBe('<p><input type="checkbox" /></p>');
});

it('renders a checked checkbox as <input type="checkbox" checked="checked" /> in HTML', function (): void {
    $doc = new Document(children: [
        new Paragraph(children: [new Checkbox(checked: true)]),
    ]);

    expect(htmlOfDoc($doc))->toBe('<p><input type="checkbox" checked="checked" /></p>');
});

it('renders a checked checkbox followed by text as `[x] text` in Markdown', function (): void {
    $doc = new Document(children: [
        new Paragraph(children: [
            new Checkbox(checked: true),
            new Run(children: [new Text(value: 'Done')]),
        ]),
    ]);

    expect(mdOfDoc($doc))->toBe("[x] Done\n\n");
});

it('renders an unchecked checkbox followed by text as `[ ] text` in Markdown', function (): void {
    $doc = new Document(children: [
        new Paragraph(children: [
            new Checkbox(),
            new Run(children: [new Text(value: 'Pending')]),
        ]),
    ]);

    expect(mdOfDoc($doc))->toBe("[ ] Pending\n\n");
});
