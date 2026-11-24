<?php

declare(strict_types=1);

use EndlessCreativity\ElephantPhp\Reader\RelationshipsReader;
use EndlessCreativity\ElephantPhp\Reader\Xml\Element;

/**
 * @param  list<Element>  $children
 */
function relationshipsElement(array $children = []): Element
{
    return new Element(name: 'relationships:Relationships', children: $children);
}

function relationshipElement(string $id, string $target, string $type): Element
{
    return new Element(
        name: 'relationships:Relationship',
        attributes: ['Id' => $id, 'Target' => $target, 'Type' => $type],
    );
}

it('finds a relationship target by ID', function (): void {
    $relationships = RelationshipsReader::readFromXml(relationshipsElement([
        relationshipElement(
            id: 'rId1',
            target: 'http://example.com/',
            type: 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/hyperlink',
        ),
        relationshipElement(
            id: 'rId2',
            target: 'http://example.net/',
            type: 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/hyperlink',
        ),
    ]));

    expect($relationships->findTargetByRelationshipId('rId1'))->toBe('http://example.com/');
});

it('finds relationship targets by type, preserving document order', function (): void {
    $type = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument';
    $relationships = RelationshipsReader::readFromXml(relationshipsElement([
        relationshipElement(
            id: 'rId2',
            target: 'docProps/core.xml',
            type: 'http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties',
        ),
        relationshipElement(id: 'rId1', target: 'word/document.xml', type: $type),
        relationshipElement(id: 'rId3', target: 'word/document2.xml', type: $type),
    ]));

    expect($relationships->findTargetsByType($type))
        ->toBe(['word/document.xml', 'word/document2.xml']);
});

it('returns an empty array for an unknown relationship type', function (): void {
    $relationships = RelationshipsReader::readFromXml(relationshipsElement());

    expect($relationships->findTargetsByType('whatever'))->toBe([]);
});
