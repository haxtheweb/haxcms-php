<?php

declare(strict_types=1);

use EndlessCreativity\ElephantPhp\Reader\StylesReader;
use EndlessCreativity\ElephantPhp\Reader\Xml\Element;

function styleElement(string $type, string $id, ?string $name = null): Element
{
    $children = [];
    if ($name !== null) {
        $children[] = new Element(name: 'w:name', attributes: ['w:val' => $name]);
    }

    return new Element(
        name: 'w:style',
        attributes: ['w:type' => $type, 'w:styleId' => $id],
        children: $children,
    );
}

function stylesXml(Element ...$styleElements): Element
{
    return new Element(name: 'w:styles', children: array_values($styleElements));
}

it('returns null when looking up an unknown paragraph style', function (): void {
    $styles = StylesReader::readFromXml(stylesXml());

    expect($styles->findParagraphStyleById('Heading1'))->toBeNull();
});

it('finds a paragraph style by id', function (): void {
    $styles = StylesReader::readFromXml(stylesXml(
        styleElement(type: 'paragraph', id: 'Heading1', name: 'Heading 1'),
    ));

    $style = $styles->findParagraphStyleById('Heading1');
    expect($style)->not->toBeNull();
    expect($style?->styleId)->toBe('Heading1');
    expect($style?->name)->toBe('Heading 1');
});

it('finds a character style by id', function (): void {
    $styles = StylesReader::readFromXml(stylesXml(
        styleElement(type: 'character', id: 'Heading1Char', name: 'Heading 1 Char'),
    ));

    expect($styles->findCharacterStyleById('Heading1Char')?->styleId)->toBe('Heading1Char');
});

it('keeps paragraph and character styles in distinct namespaces', function (): void {
    $styles = StylesReader::readFromXml(stylesXml(
        styleElement(type: 'paragraph', id: 'Heading1', name: 'Heading 1'),
        styleElement(type: 'character', id: 'Heading1Char', name: 'Heading 1 Char'),
    ));

    expect($styles->findCharacterStyleById('Heading1'))->toBeNull();
    expect($styles->findParagraphStyleById('Heading1Char'))->toBeNull();
});

it('returns null for the name when the w:name element is missing', function (): void {
    $styles = StylesReader::readFromXml(stylesXml(
        styleElement(type: 'paragraph', id: 'Heading1'),
    ));

    expect($styles->findParagraphStyleById('Heading1')?->name)->toBeNull();
});

it('keeps only the first definition when multiple style elements share an id', function (): void {
    $styles = StylesReader::readFromXml(stylesXml(
        styleElement(type: 'paragraph', id: 'Heading1', name: 'Heading 1'),
        styleElement(type: 'paragraph', id: 'Heading1', name: 'Other'),
    ));

    expect($styles->findParagraphStyleById('Heading1')?->name)->toBe('Heading 1');
});

it('reads <w:style w:type="numbering"> and exposes its numId via findNumberingStyleNumIdById', function (): void {
    $numberingStyle = new Element(
        name: 'w:style',
        attributes: ['w:type' => 'numbering', 'w:styleId' => 'MyListStyle'],
        children: [
            new Element(name: 'w:pPr', children: [
                new Element(name: 'w:numPr', children: [
                    new Element(name: 'w:numId', attributes: ['w:val' => '7']),
                ]),
            ]),
        ],
    );

    $styles = StylesReader::readFromXml(stylesXml($numberingStyle));

    expect($styles->findNumberingStyleNumIdById('MyListStyle'))->toBe('7');
    expect($styles->findNumberingStyleNumIdById('Unknown'))->toBeNull();
});

it('ignores numbering styles that do not declare a numId', function (): void {
    $styles = StylesReader::readFromXml(stylesXml(
        new Element(
            name: 'w:style',
            attributes: ['w:type' => 'numbering', 'w:styleId' => 'Empty'],
            children: [],
        ),
    ));

    expect($styles->findNumberingStyleNumIdById('Empty'))->toBeNull();
});

it('skips a style without w:type silently', function (): void {
    // mammoth ignores anonymous-type styles, we should too rather than
    // crash. Common in malformed exports.
    $styles = StylesReader::readFromXml(stylesXml(new Element(
        name: 'w:style',
        attributes: ['w:styleId' => 'NoType'],
    )));

    expect($styles->findParagraphStyleById('NoType'))->toBeNull();
});

it('skips a style without w:styleId silently', function (): void {
    $styles = StylesReader::readFromXml(stylesXml(new Element(
        name: 'w:style',
        attributes: ['w:type' => 'paragraph'],
    )));

    // No styleId means nothing to look up by; assertion is just that the
    // reader didn't throw.
    expect($styles->findParagraphStyleById(''))->toBeNull();
});

it('skips an unknown style type without crashing', function (): void {
    // ECMA-376 only defines paragraph, character, table, numbering. Real
    // docs sometimes carry custom values from third-party tools; we silent
    // skip them just like mammoth.
    $styles = StylesReader::readFromXml(stylesXml(
        styleElement(type: 'paragraph', id: 'Body', name: 'Body Text'),
        new Element(
            name: 'w:style',
            attributes: ['w:type' => 'list', 'w:styleId' => 'CustomList'],
        ),
    ));

    expect($styles->findParagraphStyleById('Body')?->name)->toBe('Body Text');
});

it('does not crash on a style declaring w:basedOn (we simply ignore the chain)', function (): void {
    // mammoth documents that basedOn is unresolved; ours matches that.
    // The point of this test is that the parser tolerates the element and
    // still reads the style's own id / name.
    $styles = StylesReader::readFromXml(stylesXml(new Element(
        name: 'w:style',
        attributes: ['w:type' => 'paragraph', 'w:styleId' => 'Heading2'],
        children: [
            new Element(name: 'w:name', attributes: ['w:val' => 'Heading 2']),
            new Element(name: 'w:basedOn', attributes: ['w:val' => 'Heading1']),
        ],
    )));

    expect($styles->findParagraphStyleById('Heading2')?->name)->toBe('Heading 2');
});

it('keeps the first numbering style when the same styleId is repeated', function (): void {
    $styles = StylesReader::readFromXml(stylesXml(
        new Element(
            name: 'w:style',
            attributes: ['w:type' => 'numbering', 'w:styleId' => 'MyList'],
            children: [
                new Element(name: 'w:pPr', children: [
                    new Element(name: 'w:numPr', children: [
                        new Element(name: 'w:numId', attributes: ['w:val' => '1']),
                    ]),
                ]),
            ],
        ),
        new Element(
            name: 'w:style',
            attributes: ['w:type' => 'numbering', 'w:styleId' => 'MyList'],
            children: [
                new Element(name: 'w:pPr', children: [
                    new Element(name: 'w:numPr', children: [
                        new Element(name: 'w:numId', attributes: ['w:val' => '99']),
                    ]),
                ]),
            ],
        ),
    ));

    expect($styles->findNumberingStyleNumIdById('MyList'))->toBe('1');
});
