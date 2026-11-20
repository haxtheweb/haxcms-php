<?php

declare(strict_types=1);

use EndlessCreativity\ElephantPhp\Document\Run;
use EndlessCreativity\ElephantPhp\Reader\BodyReader;
use EndlessCreativity\ElephantPhp\Reader\Xml\Element;

/**
 * @param  list<Element>  $properties
 */
function runWithRPr(array $properties): Element
{
    return new Element(name: 'w:r', children: [
        new Element(name: 'w:rPr', children: $properties),
    ]);
}

function readRunForHighlight(Element $run): Run
{
    $result = (new BodyReader())->readXmlElement($run);
    if (! $result->value instanceof Run) {
        throw new RuntimeException('Expected Run');
    }

    return $result->value;
}

it('reads w:highlight as the highlight color', function (): void {
    $run = readRunForHighlight(runWithRPr([
        new Element(name: 'w:highlight', attributes: ['w:val' => 'yellow']),
    ]));

    expect($run->highlight)->toBe('yellow');
});

it('treats w:highlight w:val="none" as no highlight', function (): void {
    $run = readRunForHighlight(runWithRPr([
        new Element(name: 'w:highlight', attributes: ['w:val' => 'none']),
    ]));

    expect($run->highlight)->toBeNull();
});

it('reads w:rFonts/@w:ascii as the run font', function (): void {
    $run = readRunForHighlight(runWithRPr([
        new Element(name: 'w:rFonts', attributes: ['w:ascii' => 'Helvetica']),
    ]));

    expect($run->font)->toBe('Helvetica');
});

it('reads w:sz/@w:val as half-points and stores it in points', function (): void {
    $run = readRunForHighlight(runWithRPr([
        new Element(name: 'w:sz', attributes: ['w:val' => '24']),
    ]));

    expect($run->fontSize)->toBe(12.0);
});

it('ignores a non-numeric w:sz value', function (): void {
    $run = readRunForHighlight(runWithRPr([
        new Element(name: 'w:sz', attributes: ['w:val' => 'big']),
    ]));

    expect($run->fontSize)->toBeNull();
});

it('ignores a w:sz with no w:val attribute', function (): void {
    $run = readRunForHighlight(runWithRPr([new Element(name: 'w:sz')]));

    expect($run->fontSize)->toBeNull();
});

it('ignores a w:sz with a decimal value (mammoth requires all-digits)', function (): void {
    // mammoth's regex /^[0-9]+$/ rejects "24.5"; we mirror it so a Word
    // export that misses the conversion to half-points doesn't produce a
    // bogus 12.25pt size.
    $run = readRunForHighlight(runWithRPr([
        new Element(name: 'w:sz', attributes: ['w:val' => '24.5']),
    ]));

    expect($run->fontSize)->toBeNull();
});

it('ignores a w:sz with a negative or signed value', function (): void {
    $run = readRunForHighlight(runWithRPr([
        new Element(name: 'w:sz', attributes: ['w:val' => '-12']),
    ]));

    expect($run->fontSize)->toBeNull();
});

it('returns null font when w:rFonts has no w:ascii attribute (only east-asian etc.)', function (): void {
    // A run-properties block with `<w:rFonts w:eastAsia="..."/>` but no
    // `w:ascii` should leave font null rather than picking up the eastAsia
    // value -- mammoth keys off w:ascii alone.
    $run = readRunForHighlight(runWithRPr([
        new Element(name: 'w:rFonts', attributes: ['w:eastAsia' => 'MS Mincho']),
    ]));

    expect($run->font)->toBeNull();
});

it('returns null highlight when w:highlight has no w:val attribute', function (): void {
    $run = readRunForHighlight(runWithRPr([new Element(name: 'w:highlight')]));

    expect($run->highlight)->toBeNull();
});

it('keeps an exotic highlight color (Word allows any token, not just the named set)', function (): void {
    // Word's UI lists ~16 names but the schema accepts any string. We
    // forward unmodified -- the user's style map can map it to a class.
    $run = readRunForHighlight(runWithRPr([
        new Element(name: 'w:highlight', attributes: ['w:val' => 'lightGoldenrodYellow']),
    ]));

    expect($run->highlight)->toBe('lightGoldenrodYellow');
});
