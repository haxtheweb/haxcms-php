<?php

declare(strict_types=1);

use EndlessCreativity\ElephantPhp\Document\Document;
use EndlessCreativity\ElephantPhp\Document\Paragraph;
use EndlessCreativity\ElephantPhp\Document\RawTextExtractor;
use EndlessCreativity\ElephantPhp\Document\Run;
use EndlessCreativity\ElephantPhp\Document\Text;

it('returns the value of a Text node directly', function (): void {
    expect(RawTextExtractor::extract(new Text(value: 'hello')))->toBe('hello');
});

it('joins child text nodes for a Run', function (): void {
    $run = new Run(children: [new Text(value: 'a'), new Text(value: 'b')]);

    expect(RawTextExtractor::extract($run))->toBe('ab');
});

it('appends two newlines after each paragraph', function (): void {
    $document = new Document(children: [
        new Paragraph(children: [new Run(children: [new Text(value: 'first')])]),
        new Paragraph(children: [new Run(children: [new Text(value: 'second')])]),
    ]);

    expect(RawTextExtractor::extract($document))->toBe("first\n\nsecond\n\n");
});

it('walks nested children recursively', function (): void {
    $document = new Document(children: [
        new Paragraph(children: [
            new Run(children: [new Text(value: 'A ')]),
            new Run(children: [new Text(value: 'B')]),
        ]),
    ]);

    expect(RawTextExtractor::extract($document))->toBe("A B\n\n");
});
