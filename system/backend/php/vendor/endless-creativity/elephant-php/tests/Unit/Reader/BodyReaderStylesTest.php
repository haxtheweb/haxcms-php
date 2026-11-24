<?php

declare(strict_types=1);

use EndlessCreativity\ElephantPhp\Document\Paragraph;
use EndlessCreativity\ElephantPhp\Document\Run;
use EndlessCreativity\ElephantPhp\Reader\BodyReader;
use EndlessCreativity\ElephantPhp\Reader\Style;
use EndlessCreativity\ElephantPhp\Reader\Styles;
use EndlessCreativity\ElephantPhp\Reader\Xml\Element;
use EndlessCreativity\ElephantPhp\Result;

function paragraphWithStyleId(string $id): Element
{
    return new Element(name: 'w:p', children: [
        new Element(name: 'w:pPr', children: [
            new Element(name: 'w:pStyle', attributes: ['w:val' => $id]),
        ]),
    ]);
}

function runWithStyleId(string $id): Element
{
    return new Element(name: 'w:r', children: [
        new Element(name: 'w:rPr', children: [
            new Element(name: 'w:rStyle', attributes: ['w:val' => $id]),
        ]),
    ]);
}

/**
 * @param  Result<?\EndlessCreativity\ElephantPhp\Document\Node>  $result
 */
function asParagraph(Result $result): Paragraph
{
    if (! $result->value instanceof Paragraph) {
        throw new RuntimeException('Expected a Paragraph, got '.get_debug_type($result->value));
    }

    return $result->value;
}

/**
 * @param  Result<?\EndlessCreativity\ElephantPhp\Document\Node>  $result
 */
function asRun(Result $result): Run
{
    if (! $result->value instanceof Run) {
        throw new RuntimeException('Expected a Run, got '.get_debug_type($result->value));
    }

    return $result->value;
}

it('reads a paragraph with no properties as having no style', function (): void {
    $paragraph = asParagraph((new BodyReader())->readXmlElement(new Element(name: 'w:p')));

    expect($paragraph->styleId)->toBeNull();
    expect($paragraph->styleName)->toBeNull();
});

it('reads paragraph styleId and resolves styleName from the styles map', function (): void {
    $styles = new Styles(paragraphStyles: [
        'Heading1' => new Style(styleId: 'Heading1', name: 'Heading 1'),
    ]);
    $reader = new BodyReader(styles: $styles);

    $result = $reader->readXmlElement(paragraphWithStyleId('Heading1'));

    expect($result->messages)->toBe([]);
    expect(asParagraph($result)->styleId)->toBe('Heading1');
    expect(asParagraph($result)->styleName)->toBe('Heading 1');
});

it('emits a warning when the referenced paragraph style is not in the styles map', function (): void {
    $result = (new BodyReader())->readXmlElement(paragraphWithStyleId('Heading1'));

    expect(asParagraph($result)->styleId)->toBe('Heading1');
    expect(asParagraph($result)->styleName)->toBeNull();
    expect($result->messages)->toHaveCount(1);
    expect($result->messages[0]->message)
        ->toBe('Paragraph style with ID Heading1 was referenced but not defined in the document');
});

it('reads run styleId and resolves styleName from the styles map', function (): void {
    $styles = new Styles(characterStyles: [
        'Emphasis' => new Style(styleId: 'Emphasis', name: 'Emphasis'),
    ]);
    $reader = new BodyReader(styles: $styles);

    $result = $reader->readXmlElement(runWithStyleId('Emphasis'));

    expect($result->messages)->toBe([]);
    expect(asRun($result)->styleId)->toBe('Emphasis');
    expect(asRun($result)->styleName)->toBe('Emphasis');
});

it('emits a warning when the referenced run style is not in the styles map', function (): void {
    $result = (new BodyReader())->readXmlElement(runWithStyleId('Emphasis'));

    expect(asRun($result)->styleId)->toBe('Emphasis');
    expect(asRun($result)->styleName)->toBeNull();
    expect($result->messages)->toHaveCount(1);
    expect($result->messages[0]->message)
        ->toBe('Run style with ID Emphasis was referenced but not defined in the document');
});
