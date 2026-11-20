<?php

declare(strict_types=1);

use EndlessCreativity\ElephantPhp\Html\Element;
use EndlessCreativity\ElephantPhp\Html\HtmlWriter;
use EndlessCreativity\ElephantPhp\Html\Simplifier;
use EndlessCreativity\ElephantPhp\Html\Tag;
use EndlessCreativity\ElephantPhp\Html\Text;

it('removes elements with no children that are not void', function (): void {
    $simplified = Simplifier::simplify([
        new Element(tag: new Tag(tagName: 'p'), children: []),
    ]);

    expect($simplified)->toBe([]);
});

it('keeps void elements even when empty', function (): void {
    $br = new Element(tag: new Tag(tagName: 'br'));

    expect(HtmlWriter::write(Simplifier::simplify([$br])))->toBe('<br />');
});

it('drops empty text nodes', function (): void {
    $simplified = Simplifier::simplify([new Text(value: '')]);

    expect($simplified)->toBe([]);
});

it('collapses sibling non-fresh elements with the same tag', function (): void {
    $simplified = Simplifier::simplify([
        new Element(tag: new Tag(tagName: 'em', fresh: false), children: [new Text(value: 'a')]),
        new Element(tag: new Tag(tagName: 'em', fresh: false), children: [new Text(value: 'b')]),
    ]);

    expect(HtmlWriter::write($simplified))->toBe('<em>ab</em>');
});

it('does not collapse sibling fresh elements with the same tag', function (): void {
    $simplified = Simplifier::simplify([
        new Element(tag: new Tag(tagName: 'p', fresh: true), children: [new Text(value: 'a')]),
        new Element(tag: new Tag(tagName: 'p', fresh: true), children: [new Text(value: 'b')]),
    ]);

    expect(HtmlWriter::write($simplified))->toBe('<p>a</p><p>b</p>');
});
