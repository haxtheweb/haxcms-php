<?php

declare(strict_types=1);

use EndlessCreativity\ElephantPhp\Converter;
use EndlessCreativity\ElephantPhp\Document\Document;
use EndlessCreativity\ElephantPhp\Document\Paragraph;
use EndlessCreativity\ElephantPhp\Document\Run;
use EndlessCreativity\ElephantPhp\Document\Text;

it('passes the parsed document through the transformDocument callback', function (): void {
    // Replace the body with a single paragraph saying "rewritten".
    $converter = new Converter(transformDocument: static fn (Document $doc): Document => new Document(
        children: [new Paragraph(children: [new Run(children: [new Text(value: 'rewritten')])])],
        notes: $doc->notes,
        comments: $doc->comments,
    ));

    expect($converter->convertToHtml(fixture('single-paragraph.docx'))->value)
        ->toBe('<p>rewritten</p>');
});

it('skips the callback when transformDocument is null (default)', function (): void {
    $converter = new Converter();

    expect($converter->convertToHtml(fixture('single-paragraph.docx'))->value)
        ->toBe('<p>Walking on imported air</p>');
});

it('throws when the callback returns something that is not a Document', function (): void {
    $converter = new Converter(transformDocument: static fn (Document $_) => 'not a document');

    expect(fn () => $converter->convertToHtml(fixture('single-paragraph.docx')))
        ->toThrow(InvalidArgumentException::class, 'transformDocument callback must return a Document');
});

it('also runs through transformDocument for Markdown conversion', function (): void {
    // Same callback should affect both writers since the transform sits
    // before the format-specific dispatch.
    $converter = new Converter(transformDocument: static fn (Document $_): Document => new Document(
        children: [new Paragraph(children: [new Run(children: [new Text(value: 'md')])])],
    ));

    expect($converter->convertToMarkdown(fixture('single-paragraph.docx'))->value)
        ->toBe("md\n\n");
});
