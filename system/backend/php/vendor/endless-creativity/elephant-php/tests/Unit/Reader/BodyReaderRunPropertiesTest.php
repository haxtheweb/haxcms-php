<?php

declare(strict_types=1);

use EndlessCreativity\ElephantPhp\Document\Run;
use EndlessCreativity\ElephantPhp\Document\VerticalAlignment;
use EndlessCreativity\ElephantPhp\Reader\BodyReader;
use EndlessCreativity\ElephantPhp\Reader\Xml\Element;

/**
 * @param  list<Element>  $properties
 */
function runWithProperties(array $properties): Element
{
    return new Element(name: 'w:r', children: [
        new Element(name: 'w:rPr', children: $properties),
    ]);
}

function readRun(Element $runXml): Run
{
    $result = (new BodyReader())->readXmlElement($runXml);
    expect($result->messages)->toBe([]);

    $value = $result->value;
    if (! $value instanceof Run) {
        throw new RuntimeException('Expected a Run, got '.get_debug_type($value));
    }

    return $value;
}

it('reads a run with no rPr as having all flags false and Baseline alignment', function (): void {
    $run = readRun(new Element(name: 'w:r'));

    expect($run->isBold)->toBeFalse();
    expect($run->isItalic)->toBeFalse();
    expect($run->isUnderline)->toBeFalse();
    expect($run->isStrikethrough)->toBeFalse();
    expect($run->isAllCaps)->toBeFalse();
    expect($run->isSmallCaps)->toBeFalse();
    expect($run->isHidden)->toBeFalse();
    expect($run->verticalAlignment)->toBe(VerticalAlignment::Baseline);
});

dataset('booleanRunProperties', [
    'isBold' => ['isBold', 'w:b'],
    'isItalic' => ['isItalic', 'w:i'],
    'isUnderline' => ['isUnderline', 'w:u'],
    'isStrikethrough' => ['isStrikethrough', 'w:strike'],
    'isAllCaps' => ['isAllCaps', 'w:caps'],
    'isSmallCaps' => ['isSmallCaps', 'w:smallCaps'],
    'isHidden' => ['isHidden', 'w:vanish'],
]);

it('treats a bare property element as enabling the flag (except w:u which needs a value)', function (string $property, string $tagName): void {
    $run = readRun(runWithProperties([new Element(name: $tagName)]));

    $expected = $tagName !== 'w:u';
    expect($run->{$property})->toBe($expected);
})->with('booleanRunProperties');

it('treats w:val="false" as disabling the flag', function (string $property, string $tagName): void {
    $run = readRun(runWithProperties([
        new Element(name: $tagName, attributes: ['w:val' => 'false']),
    ]));

    expect($run->{$property})->toBeFalse();
})->with('booleanRunProperties');

it('treats w:val="0" as disabling the flag', function (string $property, string $tagName): void {
    $run = readRun(runWithProperties([
        new Element(name: $tagName, attributes: ['w:val' => '0']),
    ]));

    expect($run->{$property})->toBeFalse();
})->with('booleanRunProperties');

it('treats w:u with w:val="none" as not underlined', function (): void {
    $run = readRun(runWithProperties([
        new Element(name: 'w:u', attributes: ['w:val' => 'none']),
    ]));

    expect($run->isUnderline)->toBeFalse();
});

it('treats w:u with w:val="single" as underlined', function (): void {
    $run = readRun(runWithProperties([
        new Element(name: 'w:u', attributes: ['w:val' => 'single']),
    ]));

    expect($run->isUnderline)->toBeTrue();
});

it('reads w:vertAlign="superscript" as Superscript', function (): void {
    $run = readRun(runWithProperties([
        new Element(name: 'w:vertAlign', attributes: ['w:val' => 'superscript']),
    ]));

    expect($run->verticalAlignment)->toBe(VerticalAlignment::Superscript);
});

it('reads w:vertAlign="subscript" as Subscript', function (): void {
    $run = readRun(runWithProperties([
        new Element(name: 'w:vertAlign', attributes: ['w:val' => 'subscript']),
    ]));

    expect($run->verticalAlignment)->toBe(VerticalAlignment::Subscript);
});

it('falls back to Baseline for an unknown w:vertAlign value', function (): void {
    $run = readRun(runWithProperties([
        new Element(name: 'w:vertAlign', attributes: ['w:val' => 'gibberish']),
    ]));

    expect($run->verticalAlignment)->toBe(VerticalAlignment::Baseline);
});
