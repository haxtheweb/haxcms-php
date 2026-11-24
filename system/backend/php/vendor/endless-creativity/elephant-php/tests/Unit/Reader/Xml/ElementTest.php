<?php

declare(strict_types=1);

use EndlessCreativity\ElephantPhp\Reader\Xml\Element;
use EndlessCreativity\ElephantPhp\Reader\Xml\Text;

it('returns null from first() when no child matches', function (): void {
    $element = new Element(name: 'root');

    expect($element->first('child'))->toBeNull();
});

it('returns the first child element matching by name', function (): void {
    $element = new Element(name: 'root', children: [
        new Element(name: 'a', attributes: ['n' => '1']),
        new Element(name: 'a', attributes: ['n' => '2']),
        new Element(name: 'b'),
    ]);

    expect($element->first('a')?->attributes['n'])->toBe('1');
});

it('returns the empty element from firstOrEmpty when no child matches', function (): void {
    $element = new Element(name: 'root');

    expect($element->firstOrEmpty('child')->isEmpty())->toBeTrue();
});

it('chains firstOrEmpty without exploding', function (): void {
    $element = new Element(name: 'root');

    expect($element->firstOrEmpty('a')->firstOrEmpty('b')->attributes)->toBe([]);
});

it('returns all children matching by name from getElementsByTagName', function (): void {
    $element = new Element(name: 'root', children: [
        new Element(name: 'a', attributes: ['n' => '1']),
        new Element(name: 'b'),
        new Element(name: 'a', attributes: ['n' => '2']),
    ]);

    $matches = $element->getElementsByTagName('a');

    expect($matches)->toHaveCount(2);
    expect($matches[0]->attributes['n'])->toBe('1');
    expect($matches[1]->attributes['n'])->toBe('2');
});

it('returns the text value of an element with a single text child', function (): void {
    $element = new Element(name: 'w:t', children: [new Text(value: 'Hello')]);

    expect($element->text())->toBe('Hello');
});

it('returns empty string from text() when there are no children', function (): void {
    expect((new Element(name: 'w:t'))->text())->toBe('');
});

it('throws from text() when children are not a single text node', function (): void {
    $element = new Element(name: 'root', children: [
        new Element(name: 'a'),
    ]);

    $element->text();
})->throws(RuntimeException::class);
