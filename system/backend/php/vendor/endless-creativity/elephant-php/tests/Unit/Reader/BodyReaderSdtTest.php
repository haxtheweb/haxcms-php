<?php

declare(strict_types=1);

use EndlessCreativity\ElephantPhp\Document\Paragraph;
use EndlessCreativity\ElephantPhp\Document\Run;
use EndlessCreativity\ElephantPhp\Document\Text;
use EndlessCreativity\ElephantPhp\Reader\BodyReader;
use EndlessCreativity\ElephantPhp\Reader\Xml\Element;
use EndlessCreativity\ElephantPhp\Reader\Xml\Text as XmlText;

it('unwraps a w:sdt as its w:sdtContent children', function (): void {
    $sdt = new Element(name: 'w:sdt', children: [
        new Element(name: 'w:sdtContent', children: [
            new Element(name: 'w:p', children: [
                new Element(name: 'w:r', children: [
                    new Element(name: 'w:t', children: [new XmlText(value: 'inside')]),
                ]),
            ]),
        ]),
    ]);

    $result = (new BodyReader())->readXmlElements([$sdt]);

    expect($result->messages)->toBe([]);
    expect($result->value)->toHaveCount(1);

    $paragraph = $result->value[0];
    expect($paragraph)->toBeInstanceOf(Paragraph::class);
    expect($paragraph instanceof Paragraph ? $paragraph->children : [])->toHaveCount(1);
});

it('drops a w:sdt with no w:sdtContent', function (): void {
    $sdt = new Element(name: 'w:sdt');

    $result = (new BodyReader())->readXmlElements([$sdt]);

    expect($result->value)->toBe([]);
    expect($result->messages)->toBe([]);
});

it('expands a w:sdt that wraps multiple paragraphs into siblings', function (): void {
    $sdt = new Element(name: 'w:sdt', children: [
        new Element(name: 'w:sdtContent', children: [
            new Element(name: 'w:p'),
            new Element(name: 'w:p'),
        ]),
    ]);

    $result = (new BodyReader())->readXmlElements([$sdt]);

    expect($result->value)->toHaveCount(2);
    expect($result->value[0])->toBeInstanceOf(Paragraph::class);
    expect($result->value[1])->toBeInstanceOf(Paragraph::class);
});

it('does not warn for w:sdt at any nesting depth', function (): void {
    $paragraph = new Element(name: 'w:p', children: [
        new Element(name: 'w:sdt', children: [
            new Element(name: 'w:sdtContent', children: [
                new Element(name: 'w:r', children: [
                    new Element(name: 'w:t', children: [new XmlText(value: 'hi')]),
                ]),
            ]),
        ]),
    ]);

    $result = (new BodyReader())->readXmlElement($paragraph);

    expect($result->messages)->toBe([]);
    expect($result->value)->toBeInstanceOf(Paragraph::class);

    $paragraph = $result->value;
    expect($paragraph)->toBeInstanceOf(Paragraph::class);
    $run = $paragraph instanceof Paragraph ? $paragraph->children[0] : null;
    expect($run)->toBeInstanceOf(Run::class);
    expect($run instanceof Run ? $run->children[0] : null)->toBeInstanceOf(Text::class);
});
