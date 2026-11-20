<?php

declare(strict_types=1);

use EndlessCreativity\ElephantPhp\Document\Text;
use EndlessCreativity\ElephantPhp\Reader\BodyReader;
use EndlessCreativity\ElephantPhp\Reader\Xml\Element;

it('maps a Wingdings dingbat character to its Unicode equivalent', function (): void {
    // Wingdings 0x4A is U+263A "white smiling face".
    $result = (new BodyReader())->readXmlElement(new Element(
        name: 'w:sym',
        attributes: ['w:font' => 'Wingdings', 'w:char' => '4A'],
    ));

    expect($result->messages)->toBe([]);
    expect($result->value)->toBeInstanceOf(Text::class);
    expect($result->value instanceof Text ? $result->value->value : null)
        ->toBe(mb_chr(0x263A));
});

it('strips a leading F0 from the Symbol font character when the direct lookup fails', function (): void {
    // Symbol font 0x4A maps to U+03D1 (theta-symbol). Word may write "F04A".
    $result = (new BodyReader())->readXmlElement(new Element(
        name: 'w:sym',
        attributes: ['w:font' => 'Symbol', 'w:char' => 'F04A'],
    ));

    expect($result->messages)->toBe([]);
    expect($result->value instanceof Text ? $result->value->value : null)->toBe(mb_chr(0x03D1));
});

it('warns when the (font, char) pair is unknown', function (): void {
    $result = (new BodyReader())->readXmlElement(new Element(
        name: 'w:sym',
        attributes: ['w:font' => 'NonExistentFont', 'w:char' => 'AA'],
    ));

    expect($result->value)->toBeNull();
    expect($result->messages)->toHaveCount(1);
    expect($result->messages[0]->message)
        ->toBe('A w:sym element with an unsupported character was ignored: char AA in font NonExistentFont');
});

it('drops a w:sym with no font or char silently', function (): void {
    $result = (new BodyReader())->readXmlElement(new Element(name: 'w:sym'));

    expect($result->value)->toBeNull();
    expect($result->messages)->toBe([]);
});
