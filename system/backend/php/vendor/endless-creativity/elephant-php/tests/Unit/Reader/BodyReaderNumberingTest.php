<?php

declare(strict_types=1);

use EndlessCreativity\ElephantPhp\Document\NumberingLevel;
use EndlessCreativity\ElephantPhp\Document\Paragraph;
use EndlessCreativity\ElephantPhp\Reader\BodyReader;
use EndlessCreativity\ElephantPhp\Reader\Numbering;
use EndlessCreativity\ElephantPhp\Reader\NumberingReader;
use EndlessCreativity\ElephantPhp\Reader\Xml\Element;

function numberedParagraph(string $numId, ?string $ilvl): Element
{
    $numPrChildren = [
        new Element(name: 'w:numId', attributes: ['w:val' => $numId]),
    ];
    if ($ilvl !== null) {
        $numPrChildren[] = new Element(name: 'w:ilvl', attributes: ['w:val' => $ilvl]);
    }

    return new Element(name: 'w:p', children: [
        new Element(name: 'w:pPr', children: [
            new Element(name: 'w:numPr', children: $numPrChildren),
        ]),
    ]);
}

function bulletNumbering(): Numbering
{
    return NumberingReader::readFromXml(numberingXml([
        abstractNumElement(abstractNumId: '0', levels: [
            ['ilvl' => '0', 'fmt' => 'bullet'],
            ['ilvl' => '1', 'fmt' => 'bullet'],
        ]),
        numElement(numId: '1', abstractNumId: '0'),
    ]));
}

function readParagraph(Element $element, ?Numbering $numbering = null): Paragraph
{
    $reader = new BodyReader(numbering: $numbering ?? new Numbering());
    $result = $reader->readXmlElement($element);

    if (! $result->value instanceof Paragraph) {
        throw new RuntimeException('Expected a Paragraph, got '.get_debug_type($result->value));
    }

    return $result->value;
}

it('reads numbering for a paragraph from numId + ilvl', function (): void {
    $paragraph = readParagraph(numberedParagraph(numId: '1', ilvl: '0'), bulletNumbering());

    expect($paragraph->numbering)->toBeInstanceOf(NumberingLevel::class);
    expect($paragraph->numbering?->level)->toBe(0);
    expect($paragraph->numbering?->isOrdered)->toBeFalse();
});

it('reads a deeper level for a paragraph', function (): void {
    $paragraph = readParagraph(numberedParagraph(numId: '1', ilvl: '1'), bulletNumbering());

    expect($paragraph->numbering?->level)->toBe(1);
});

it('assumes level 0 when w:ilvl is missing on a numbered paragraph', function (): void {
    $paragraph = readParagraph(numberedParagraph(numId: '1', ilvl: null), bulletNumbering());

    expect($paragraph->numbering?->level)->toBe(0);
});

it('returns null numbering when the numId is not defined', function (): void {
    $paragraph = readParagraph(numberedParagraph(numId: '99', ilvl: '0'), bulletNumbering());

    expect($paragraph->numbering)->toBeNull();
});

it('returns null numbering for a paragraph that has no numPr', function (): void {
    $paragraph = readParagraph(new Element(name: 'w:p'), bulletNumbering());

    expect($paragraph->numbering)->toBeNull();
});

it('falls back to findLevelByParagraphStyleId when the paragraph has only a styleId', function (): void {
    // Numbering definition ties level 0 to paragraph style "ListLevel1".
    // The paragraph itself carries only `<w:pStyle val="ListLevel1"/>` --
    // no `<w:numPr>` of its own -- and inherits the level transparently.
    $numbering = NumberingReader::readFromXml(numberingXml([
        new Element(
            name: 'w:abstractNum',
            attributes: ['w:abstractNumId' => '0'],
            children: [
                new Element(name: 'w:lvl', attributes: ['w:ilvl' => '0'], children: [
                    new Element(name: 'w:numFmt', attributes: ['w:val' => 'decimal']),
                    new Element(name: 'w:pStyle', attributes: ['w:val' => 'ListLevel1']),
                ]),
            ],
        ),
        numElement(numId: '1', abstractNumId: '0'),
    ]));

    $paragraphElement = new Element(name: 'w:p', children: [
        new Element(name: 'w:pPr', children: [
            new Element(name: 'w:pStyle', attributes: ['w:val' => 'ListLevel1']),
        ]),
    ]);

    $paragraph = readParagraph($paragraphElement, $numbering);

    expect($paragraph->numbering?->level)->toBe(0);
    expect($paragraph->numbering?->isOrdered)->toBeTrue();
});
