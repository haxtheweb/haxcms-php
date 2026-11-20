<?php

declare(strict_types=1);

use EndlessCreativity\ElephantPhp\Document\NoteType;
use EndlessCreativity\ElephantPhp\Reader\BodyReader;
use EndlessCreativity\ElephantPhp\Reader\NotesReader;
use EndlessCreativity\ElephantPhp\Reader\Xml\Element;
use EndlessCreativity\ElephantPhp\Reader\Xml\Text as XmlText;

function footnotesXml(Element ...$noteElements): Element
{
    return new Element(name: 'w:footnotes', children: array_values($noteElements));
}

function footnoteElement(string $id, string $text, ?string $type = null): Element
{
    $attributes = ['w:id' => $id];
    if ($type !== null) {
        $attributes['w:type'] = $type;
    }

    return new Element(name: 'w:footnote', attributes: $attributes, children: [
        new Element(name: 'w:p', children: [
            new Element(name: 'w:r', children: [
                new Element(name: 'w:t', children: [new XmlText(value: $text)]),
            ]),
        ]),
    ]);
}

it('reads footnotes into Note objects', function (): void {
    $reader = new NotesReader(new BodyReader());

    $result = $reader->readFromXml(
        footnotesXml(
            footnoteElement(id: '1', text: 'first'),
            footnoteElement(id: '2', text: 'second'),
        ),
        NoteType::Footnote,
    );

    expect($result->messages)->toBe([]);
    expect($result->value)->toHaveCount(2);
    expect($result->value[0]->noteId)->toBe('1');
    expect($result->value[0]->noteType)->toBe(NoteType::Footnote);
    expect($result->value[1]->noteId)->toBe('2');
});

it('skips separator and continuationSeparator notes', function (): void {
    $reader = new NotesReader(new BodyReader());

    $result = $reader->readFromXml(
        footnotesXml(
            footnoteElement(id: '-1', text: '', type: 'separator'),
            footnoteElement(id: '0', text: '', type: 'continuationSeparator'),
            footnoteElement(id: '1', text: 'real'),
        ),
        NoteType::Footnote,
    );

    expect($result->value)->toHaveCount(1);
    expect($result->value[0]->noteId)->toBe('1');
});

it('also reads endnotes when given the Endnote type', function (): void {
    $reader = new NotesReader(new BodyReader());

    $endnoteElement = new Element(name: 'w:endnote', attributes: ['w:id' => '1'], children: [
        new Element(name: 'w:p', children: [
            new Element(name: 'w:r', children: [
                new Element(name: 'w:t', children: [new XmlText(value: 'end')]),
            ]),
        ]),
    ]);
    $endnotesXml = new Element(name: 'w:endnotes', children: [$endnoteElement]);

    $result = $reader->readFromXml($endnotesXml, NoteType::Endnote);

    expect($result->value)->toHaveCount(1);
    expect($result->value[0]->noteType)->toBe(NoteType::Endnote);
});
