<?php

declare(strict_types=1);

use EndlessCreativity\ElephantPhp\Document\Document;
use EndlessCreativity\ElephantPhp\Document\DocumentConverter;
use EndlessCreativity\ElephantPhp\Document\Paragraph;
use EndlessCreativity\ElephantPhp\Document\Run;
use EndlessCreativity\ElephantPhp\Document\Text;

function htmlOfDocument(Document $document, bool $ignoreHiddenText = true): string
{
    return (new DocumentConverter(ignoreHiddenText: $ignoreHiddenText))
        ->convertToHtml($document)
        ->value;
}

/**
 * @param  list<Run>  $runs
 */
function paragraphWithRuns(array $runs): Document
{
    return new Document(children: [new Paragraph(children: $runs)]);
}

it('drops a hidden run by default, keeping surrounding visible text', function (): void {
    $document = paragraphWithRuns([
        new Run(children: [new Text(value: 'before')]),
        new Run(isHidden: true, children: [new Text(value: 'SECRET')]),
        new Run(children: [new Text(value: 'after')]),
    ]);

    expect(htmlOfDocument($document))->toBe('<p>beforeafter</p>');
});

it('emits a hidden run as ordinary text when ignoreHiddenText is false', function (): void {
    $document = paragraphWithRuns([
        new Run(children: [new Text(value: 'before')]),
        new Run(isHidden: true, children: [new Text(value: 'SECRET')]),
        new Run(children: [new Text(value: 'after')]),
    ]);

    expect(htmlOfDocument($document, ignoreHiddenText: false))
        ->toBe('<p>beforeSECRETafter</p>');
});

it('drops a paragraph whose only content is a hidden run', function (): void {
    $document = paragraphWithRuns([
        new Run(isHidden: true, children: [new Text(value: 'entirely hidden')]),
    ]);

    expect(htmlOfDocument($document))->toBe('');
});

it('leaves a visible run untouched', function (): void {
    $document = paragraphWithRuns([
        new Run(children: [new Text(value: 'visible')]),
    ]);

    expect(htmlOfDocument($document))->toBe('<p>visible</p>');
});
