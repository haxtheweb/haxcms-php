<?php

declare(strict_types=1);

use EndlessCreativity\ElephantPhp\Document\Document;
use EndlessCreativity\ElephantPhp\Document\DocumentConverter;
use EndlessCreativity\ElephantPhp\Document\Paragraph;
use EndlessCreativity\ElephantPhp\Document\Run;
use EndlessCreativity\ElephantPhp\Document\Text;

/**
 * @return \EndlessCreativity\ElephantPhp\Result<string>
 */
function convertParagraph(Paragraph $paragraph): \EndlessCreativity\ElephantPhp\Result
{
    return (new DocumentConverter())->convertToHtml(new Document(children: [$paragraph]));
}

dataset('headings', [
    'Heading 1 by name' => [['styleName' => 'Heading 1'], 'h1'],
    'Heading 2 by name' => [['styleName' => 'Heading 2'], 'h2'],
    'Heading 3 by name' => [['styleName' => 'Heading 3'], 'h3'],
    'Heading 4 by name' => [['styleName' => 'Heading 4'], 'h4'],
    'Heading 5 by name' => [['styleName' => 'Heading 5'], 'h5'],
    'Heading 6 by name' => [['styleName' => 'Heading 6'], 'h6'],
    'Heading1 by id' => [['styleId' => 'Heading1'], 'h1'],
    'Heading2 by id' => [['styleId' => 'Heading2'], 'h2'],
    'Heading3 by id' => [['styleId' => 'Heading3'], 'h3'],
    'Heading4 by id' => [['styleId' => 'Heading4'], 'h4'],
    'Heading5 by id' => [['styleId' => 'Heading5'], 'h5'],
    'Heading6 by id' => [['styleId' => 'Heading6'], 'h6'],
    'fallback Heading by name' => [['styleName' => 'Heading'], 'h1'],
    'fallback Heading by id' => [['styleId' => 'Heading'], 'h1'],
]);

it('renders Word heading styles using the default heading map', function (array $properties, string $expectedTag): void {
    $paragraph = new Paragraph(
        children: [new Run(children: [new Text(value: 'Hello.')])],
        styleId: $properties['styleId'] ?? null,
        styleName: $properties['styleName'] ?? null,
    );

    $result = convertParagraph($paragraph);

    expect($result->messages)->toBe([]);
    expect($result->value)->toBe("<{$expectedTag}>Hello.</{$expectedTag}>");
})->with('headings');

it('falls back to <p> with a warning when the paragraph styleId is not mapped', function (): void {
    $paragraph = new Paragraph(
        children: [new Run(children: [new Text(value: 'Hello.')])],
        styleId: 'Aside',
        styleName: 'Aside Heading',
    );

    $result = convertParagraph($paragraph);

    expect($result->value)->toBe('<p>Hello.</p>');
    expect($result->messages)->toHaveCount(1);
    expect($result->messages[0]->message)
        ->toBe("Unrecognised paragraph style: 'Aside Heading' (Style ID: Aside)");
});

it('does not warn for a paragraph that simply has no styleId', function (): void {
    $paragraph = new Paragraph(children: [new Run(children: [new Text(value: 'Hello.')])]);

    $result = convertParagraph($paragraph);

    expect($result->value)->toBe('<p>Hello.</p>');
    expect($result->messages)->toBe([]);
});

it('warns when a run has an unmapped styleId, still emitting the run inline', function (): void {
    $document = new Document(children: [new Paragraph(children: [
        new Run(
            children: [new Text(value: 'Hello.')],
            styleId: 'Heading1Char',
            styleName: 'Heading 1 Char',
        ),
    ])]);

    $result = (new DocumentConverter())->convertToHtml($document);

    expect($result->value)->toBe('<p>Hello.</p>');
    expect($result->messages)->toHaveCount(1);
    expect($result->messages[0]->message)
        ->toBe("Unrecognised run style: 'Heading 1 Char' (Style ID: Heading1Char)");
});

it('threads converter warnings through the public Result', function (): void {
    $paragraph = new Paragraph(
        children: [new Run(children: [new Text(value: 'x')])],
        styleId: 'Mystery',
    );

    $result = convertParagraph($paragraph);

    expect($result->messages)->toHaveCount(1);
    expect($result->messages[0]->message)
        ->toBe("Unrecognised paragraph style: '' (Style ID: Mystery)");
});
