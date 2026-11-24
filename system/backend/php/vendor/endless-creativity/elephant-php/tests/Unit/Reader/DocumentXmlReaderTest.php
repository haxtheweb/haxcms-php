<?php

declare(strict_types=1);

use EndlessCreativity\ElephantPhp\Document\Document;
use EndlessCreativity\ElephantPhp\Document\Paragraph;
use EndlessCreativity\ElephantPhp\Reader\BodyReader;
use EndlessCreativity\ElephantPhp\Reader\DocumentXmlReader;
use EndlessCreativity\ElephantPhp\Reader\Xml\Element;

it('wraps the body children inside a Document', function (): void {
    $documentXml = new Element(name: 'w:document', children: [
        new Element(name: 'w:body', children: [
            new Element(name: 'w:p'),
            new Element(name: 'w:p'),
        ]),
    ]);

    $result = (new DocumentXmlReader(new BodyReader()))->convertXmlToDocument($documentXml);

    expect($result->messages)->toBe([]);
    expect($result->value)->toBeInstanceOf(Document::class);
    expect($result->value->children)->toHaveCount(2);
    expect($result->value->children[0])->toBeInstanceOf(Paragraph::class);
});

it('throws when the w:body element is missing', function (): void {
    (new DocumentXmlReader(new BodyReader()))
        ->convertXmlToDocument(new Element(name: 'w:document'));
})->throws(RuntimeException::class, 'Could not find the body element');
