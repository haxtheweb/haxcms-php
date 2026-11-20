<?php

declare(strict_types=1);

use EndlessCreativity\ElephantPhp\Document\BookmarkStart;
use EndlessCreativity\ElephantPhp\Document\BreakElement;
use EndlessCreativity\ElephantPhp\Document\BreakType;
use EndlessCreativity\ElephantPhp\Document\Tab;
use EndlessCreativity\ElephantPhp\Document\Text;
use EndlessCreativity\ElephantPhp\Reader\BodyReader;
use EndlessCreativity\ElephantPhp\Reader\Xml\Element;

it('reads w:tab as a Tab node', function (): void {
    $result = (new BodyReader())->readXmlElement(new Element(name: 'w:tab'));

    expect($result->messages)->toBe([]);
    expect($result->value)->toBeInstanceOf(Tab::class);
});

it('reads w:noBreakHyphen as a non-breaking hyphen', function (): void {
    $result = (new BodyReader())->readXmlElement(new Element(name: 'w:noBreakHyphen'));

    expect($result->value)->toBeInstanceOf(Text::class);
    expect($result->value instanceof Text ? $result->value->value : null)->toBe("\u{2011}");
});

it('reads w:softHyphen as a soft hyphen', function (): void {
    $result = (new BodyReader())->readXmlElement(new Element(name: 'w:softHyphen'));

    expect($result->value)->toBeInstanceOf(Text::class);
    expect($result->value instanceof Text ? $result->value->value : null)->toBe("\u{00AD}");
});

it('defaults a w:br with no type to a line break', function (): void {
    $result = (new BodyReader())->readXmlElement(new Element(name: 'w:br'));

    expect($result->value)->toBeInstanceOf(BreakElement::class);
    expect($result->value instanceof BreakElement ? $result->value->breakType : null)
        ->toBe(BreakType::Line);
});

it('reads w:br[type=page] as a page break', function (): void {
    $result = (new BodyReader())->readXmlElement(
        new Element(name: 'w:br', attributes: ['w:type' => 'page']),
    );

    expect($result->value instanceof BreakElement ? $result->value->breakType : null)
        ->toBe(BreakType::Page);
});

it('reads w:br[type=column] as a column break', function (): void {
    $result = (new BodyReader())->readXmlElement(
        new Element(name: 'w:br', attributes: ['w:type' => 'column']),
    );

    expect($result->value instanceof BreakElement ? $result->value->breakType : null)
        ->toBe(BreakType::Column);
});

it('reads w:bookmarkStart with a name as a BookmarkStart node', function (): void {
    $result = (new BodyReader())->readXmlElement(
        new Element(name: 'w:bookmarkStart', attributes: ['w:name' => 'top']),
    );

    expect($result->value)->toBeInstanceOf(BookmarkStart::class);
    expect($result->value instanceof BookmarkStart ? $result->value->name : null)->toBe('top');
});

it('drops the synthetic _GoBack bookmark', function (): void {
    $result = (new BodyReader())->readXmlElement(
        new Element(name: 'w:bookmarkStart', attributes: ['w:name' => '_GoBack']),
    );

    expect($result->value)->toBeNull();
    expect($result->messages)->toBe([]);
});
