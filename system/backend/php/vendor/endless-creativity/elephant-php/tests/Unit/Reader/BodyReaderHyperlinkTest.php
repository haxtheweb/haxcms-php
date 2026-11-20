<?php

declare(strict_types=1);

use EndlessCreativity\ElephantPhp\Document\Hyperlink;
use EndlessCreativity\ElephantPhp\Reader\BodyReader;
use EndlessCreativity\ElephantPhp\Reader\Relationship;
use EndlessCreativity\ElephantPhp\Reader\Relationships;
use EndlessCreativity\ElephantPhp\Reader\Xml\Element;
use EndlessCreativity\ElephantPhp\Reader\Xml\Text as XmlText;

const HYPERLINK_TYPE = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/hyperlink';

/**
 * @param  array<string, string>  $attributes
 */
function hyperlinkXml(array $attributes, string $text = 'click'): Element
{
    return new Element(
        name: 'w:hyperlink',
        attributes: $attributes,
        children: [
            new Element(name: 'w:r', children: [
                new Element(name: 'w:t', children: [new XmlText(value: $text)]),
            ]),
        ],
    );
}

function readHyperlink(Element $element, ?Relationships $relationships = null): Hyperlink
{
    $reader = new BodyReader(relationships: $relationships ?? new Relationships());
    $result = $reader->readXmlElement($element);

    if (! $result->value instanceof Hyperlink) {
        throw new RuntimeException('Expected a Hyperlink, got '.get_debug_type($result->value));
    }

    return $result->value;
}

it('resolves r:id to an href via the relationships table', function (): void {
    $relationships = new Relationships([
        new Relationship(relationshipId: 'rId6', target: 'http://example.com/', type: HYPERLINK_TYPE),
    ]);

    $hyperlink = readHyperlink(hyperlinkXml(['r:id' => 'rId6']), $relationships);

    expect($hyperlink->href)->toBe('http://example.com/');
    expect($hyperlink->anchor)->toBeNull();
});

it('reads w:anchor as a bookmark link without an href', function (): void {
    $hyperlink = readHyperlink(hyperlinkXml(['w:anchor' => 'section-2']));

    expect($hyperlink->href)->toBeNull();
    expect($hyperlink->anchor)->toBe('section-2');
});

it('combines r:id href with w:anchor as a fragment', function (): void {
    $relationships = new Relationships([
        new Relationship(relationshipId: 'rId6', target: 'http://example.com/page', type: HYPERLINK_TYPE),
    ]);

    $hyperlink = readHyperlink(
        hyperlinkXml(['r:id' => 'rId6', 'w:anchor' => 'top']),
        $relationships,
    );

    expect($hyperlink->href)->toBe('http://example.com/page#top');
});

it('replaces an existing fragment when combining r:id href with w:anchor', function (): void {
    $relationships = new Relationships([
        new Relationship(relationshipId: 'rId6', target: 'http://example.com/page#old', type: HYPERLINK_TYPE),
    ]);

    $hyperlink = readHyperlink(
        hyperlinkXml(['r:id' => 'rId6', 'w:anchor' => 'new']),
        $relationships,
    );

    expect($hyperlink->href)->toBe('http://example.com/page#new');
});

it('captures w:tgtFrame as targetFrame', function (): void {
    $relationships = new Relationships([
        new Relationship(relationshipId: 'rId6', target: 'http://example.com/', type: HYPERLINK_TYPE),
    ]);

    $hyperlink = readHyperlink(
        hyperlinkXml(['r:id' => 'rId6', 'w:tgtFrame' => '_blank']),
        $relationships,
    );

    expect($hyperlink->targetFrame)->toBe('_blank');
});

it('produces a Hyperlink with both href and anchor null when neither attribute is set', function (): void {
    $hyperlink = readHyperlink(hyperlinkXml([]));

    expect($hyperlink->href)->toBeNull();
    expect($hyperlink->anchor)->toBeNull();
});
