<?php

declare(strict_types=1);

use EndlessCreativity\ElephantPhp\Document\Hyperlink;
use EndlessCreativity\ElephantPhp\Document\Run;
use EndlessCreativity\ElephantPhp\Reader\BodyReader;
use EndlessCreativity\ElephantPhp\Reader\Xml\Element;
use EndlessCreativity\ElephantPhp\Reader\Xml\Text as XmlText;

function fldCharBegin(): Element
{
    return new Element(
        name: 'w:fldChar',
        attributes: ['w:fldCharType' => 'begin'],
    );
}

function fldCharSeparate(): Element
{
    return new Element(name: 'w:fldChar', attributes: ['w:fldCharType' => 'separate']);
}

function fldCharEnd(): Element
{
    return new Element(name: 'w:fldChar', attributes: ['w:fldCharType' => 'end']);
}

function instrText(string $text): Element
{
    return new Element(name: 'w:instrText', children: [new XmlText(value: $text)]);
}

function runOfText(string $text, ?Element $extraChild = null): Element
{
    $children = [];
    if ($extraChild !== null) {
        $children[] = $extraChild;
    }
    $children[] = new Element(name: 'w:t', children: [new XmlText(value: $text)]);

    return new Element(name: 'w:r', children: $children);
}

it('wraps a run between separate and end fldChars in a Hyperlink', function (): void {
    $reader = new BodyReader();

    // <w:r><w:fldChar begin/></w:r><w:r><w:instrText>HYPERLINK "http://example.com/"</w:instrText></w:r>
    // <w:r><w:fldChar separate/></w:r>
    // <w:r><w:t>linked</w:t></w:r>
    // <w:r><w:fldChar end/></w:r>
    $result = $reader->readXmlElements([
        new Element(name: 'w:r', children: [fldCharBegin()]),
        new Element(name: 'w:r', children: [instrText(' HYPERLINK "http://example.com/" ')]),
        new Element(name: 'w:r', children: [fldCharSeparate()]),
        runOfText('linked'),
        new Element(name: 'w:r', children: [fldCharEnd()]),
    ]);

    expect($result->messages)->toBe([]);

    // The middle run should have its children wrapped in a Hyperlink.
    $linkedRun = null;
    foreach ($result->value as $node) {
        if ($node instanceof Run && $node->children !== [] && $node->children[0] instanceof Hyperlink) {
            $linkedRun = $node;
        }
    }
    expect($linkedRun)->not->toBeNull();
    /** @var Run $linkedRun */
    $hyperlink = $linkedRun->children[0];
    expect($hyperlink)->toBeInstanceOf(Hyperlink::class);
    /** @var Hyperlink $hyperlink */
    expect($hyperlink->href)->toBe('http://example.com/');
});

it('handles HYPERLINK \\l for an internal anchor', function (): void {
    $reader = new BodyReader();

    $result = $reader->readXmlElements([
        new Element(name: 'w:r', children: [fldCharBegin()]),
        new Element(name: 'w:r', children: [instrText(' HYPERLINK \\l "section-2" ')]),
        new Element(name: 'w:r', children: [fldCharSeparate()]),
        runOfText('jump'),
        new Element(name: 'w:r', children: [fldCharEnd()]),
    ]);

    $linkedRun = null;
    foreach ($result->value as $node) {
        if ($node instanceof Run && $node->children !== [] && $node->children[0] instanceof Hyperlink) {
            $linkedRun = $node;
        }
    }
    expect($linkedRun)->not->toBeNull();
    /** @var Run $linkedRun */
    $hyperlink = $linkedRun->children[0];
    expect($hyperlink instanceof Hyperlink ? $hyperlink->anchor : null)->toBe('section-2');
});

it('does not wrap runs after the end fldChar', function (): void {
    $reader = new BodyReader();

    $result = $reader->readXmlElements([
        new Element(name: 'w:r', children: [fldCharBegin()]),
        new Element(name: 'w:r', children: [instrText(' HYPERLINK "http://example.com/" ')]),
        new Element(name: 'w:r', children: [fldCharSeparate()]),
        runOfText('inside'),
        new Element(name: 'w:r', children: [fldCharEnd()]),
        runOfText('outside'),
    ]);

    $outside = $result->value[count($result->value) - 1];
    expect($outside)->toBeInstanceOf(Run::class);
    /** @var Run $outside */
    expect($outside->children[0])->not->toBeInstanceOf(Hyperlink::class);
});
