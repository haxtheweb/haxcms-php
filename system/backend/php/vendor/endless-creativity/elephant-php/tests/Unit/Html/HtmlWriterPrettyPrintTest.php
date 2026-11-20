<?php

declare(strict_types=1);

use EndlessCreativity\ElephantPhp\Html\Element;
use EndlessCreativity\ElephantPhp\Html\HtmlWriter;
use EndlessCreativity\ElephantPhp\Html\Tag;
use EndlessCreativity\ElephantPhp\Html\Text;

/**
 * @param  array<string, string>  $attributes
 * @param  list<\EndlessCreativity\ElephantPhp\Html\Node>  $children
 */
function elem(string $tag, array $attributes = [], array $children = []): Element
{
    return new Element(tag: new Tag(tagName: $tag, attributes: $attributes), children: $children);
}

it('emits inline output by default (prettyPrint=false)', function (): void {
    expect(HtmlWriter::write([elem('p', children: [new Text(value: 'hi')])]))
        ->toBe('<p>hi</p>');
});

it('inserts newlines and indentation around block elements when prettyPrint=true', function (): void {
    $html = HtmlWriter::write(
        [elem('p', children: [new Text(value: 'hi')])],
        prettyPrint: true,
    );

    expect($html)->toBe("<p>\n  hi\n</p>");
});

it('indents nested block elements one level deeper per nesting', function (): void {
    $html = HtmlWriter::write([
        elem('ul', children: [
            elem('li', children: [new Text(value: 'A')]),
            elem('li', children: [new Text(value: 'B')]),
        ]),
    ], prettyPrint: true);

    expect($html)->toBe("<ul>\n  <li>\n    A\n  </li>\n  <li>\n    B\n  </li>\n</ul>");
});

it('keeps inline elements (a, strong, em, sup, br) on the same line', function (): void {
    // Only the listed block tags trigger indentation; <strong> and
    // similar wrappers flow with surrounding text so paragraph
    // content isn't broken into one-word lines.
    $html = HtmlWriter::write([
        elem('p', children: [
            new Text(value: 'see '),
            elem('a', attributes: ['href' => '#x'], children: [new Text(value: 'here')]),
            new Text(value: ' or '),
            elem('strong', children: [new Text(value: 'this')]),
        ]),
    ], prettyPrint: true);

    expect($html)->toBe("<p>\n  see <a href=\"#x\">here</a> or <strong>this</strong>\n</p>");
});

it('places sibling block elements on separate lines at top level', function (): void {
    $html = HtmlWriter::write([
        elem('p', children: [new Text(value: 'one')]),
        elem('p', children: [new Text(value: 'two')]),
    ], prettyPrint: true);

    expect($html)->toBe("<p>\n  one\n</p>\n<p>\n  two\n</p>");
});

it('still self-closes void elements like <img> and <br>', function (): void {
    $html = HtmlWriter::write([
        elem('p', children: [
            new Text(value: 'a'),
            elem('br'),
            new Text(value: 'b'),
        ]),
    ], prettyPrint: true);

    // <br> is not block-indented (not in INDENTED_ELEMENTS) but
    // self-closing tags always get an indent before them.
    expect($html)->toContain('<br />');
});
