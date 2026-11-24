<?php

declare(strict_types=1);

use EndlessCreativity\ElephantPhp\Html\Element;
use EndlessCreativity\ElephantPhp\Html\HtmlWriter;
use EndlessCreativity\ElephantPhp\Html\Tag;
use EndlessCreativity\ElephantPhp\Html\Text;

it('writes a single text node', function (): void {
    expect(HtmlWriter::write([new Text(value: 'hi')]))->toBe('hi');
});

it('writes an element with text children', function (): void {
    $node = new Element(
        tag: new Tag(tagName: 'p'),
        children: [new Text(value: 'Hello.')],
    );

    expect(HtmlWriter::write([$node]))->toBe('<p>Hello.</p>');
});

it('writes attributes on an element', function (): void {
    $node = new Element(
        tag: new Tag(tagName: 'a', attributes: ['href' => 'https://example.com']),
        children: [new Text(value: 'link')],
    );

    expect(HtmlWriter::write([$node]))->toBe('<a href="https://example.com">link</a>');
});

it('escapes < > & in text', function (): void {
    expect(HtmlWriter::write([new Text(value: '<a> & <b>')]))->toBe('&lt;a&gt; &amp; &lt;b&gt;');
});

it('escapes < > & " in attributes', function (): void {
    $node = new Element(
        tag: new Tag(tagName: 'a', attributes: ['title' => '"x" & <y>']),
    );

    expect(HtmlWriter::write([$node]))->toBe('<a title="&quot;x&quot; &amp; &lt;y&gt;"></a>');
});

it('writes void elements as self-closing when empty', function (): void {
    $node = new Element(tag: new Tag(tagName: 'br'));

    expect(HtmlWriter::write([$node]))->toBe('<br />');
});
