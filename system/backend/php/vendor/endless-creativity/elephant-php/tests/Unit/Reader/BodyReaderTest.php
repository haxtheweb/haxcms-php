<?php

declare(strict_types=1);

use EndlessCreativity\ElephantPhp\Document\Paragraph;
use EndlessCreativity\ElephantPhp\Document\Run;
use EndlessCreativity\ElephantPhp\Document\Text;
use EndlessCreativity\ElephantPhp\Message;
use EndlessCreativity\ElephantPhp\MessageType;
use EndlessCreativity\ElephantPhp\Reader\BodyReader;
use EndlessCreativity\ElephantPhp\Reader\Xml\Element;
use EndlessCreativity\ElephantPhp\Reader\Xml\Text as XmlText;

function readSingle(Element $element): mixed
{
    $result = (new BodyReader())->readXmlElement($element);
    expect($result->messages)->toBe([]);

    return $result->value;
}

it('reads <w:t> as a Text node', function (): void {
    $node = readSingle(new Element(name: 'w:t', children: [new XmlText(value: 'Hello.')]));

    expect($node)->toBeInstanceOf(Text::class);
    expect($node->value)->toBe('Hello.');
});

it('reads an empty <w:p> as a Paragraph with no children', function (): void {
    $paragraph = readSingle(new Element(name: 'w:p'));

    expect($paragraph)->toBeInstanceOf(Paragraph::class);
    expect($paragraph->children)->toBe([]);
});

it('reads <w:p><w:r><w:t>...</w:t></w:r></w:p> as Paragraph[Run[Text]]', function (): void {
    $paragraphXml = new Element(name: 'w:p', children: [
        new Element(name: 'w:r', children: [
            new Element(name: 'w:t', children: [new XmlText(value: 'Hello.')]),
        ]),
    ]);

    $paragraph = readSingle($paragraphXml);

    expect($paragraph)->toBeInstanceOf(Paragraph::class);
    expect($paragraph->children)->toHaveCount(1);

    $run = $paragraph->children[0];
    expect($run)->toBeInstanceOf(Run::class);
    expect($run->children)->toHaveCount(1);

    $text = $run->children[0];
    expect($text)->toBeInstanceOf(Text::class);
    expect($text->value)->toBe('Hello.');
});

it('emits a warning for an unrecognised element', function (): void {
    $result = (new BodyReader())->readXmlElement(new Element(name: 'w:strangeThing'));

    expect($result->messages)->toHaveCount(1);
    expect($result->messages[0])->toBeInstanceOf(Message::class);
    expect($result->messages[0]->type)->toBe(MessageType::Warning);
    expect($result->messages[0]->message)->toBe('An unrecognised element was ignored: w:strangeThing');
});

it('readXmlElements combines results from multiple elements', function (): void {
    $reader = new BodyReader();

    $result = $reader->readXmlElements([
        new Element(name: 'w:p'),
        new Element(name: 'w:p'),
    ]);

    expect($result->value)->toHaveCount(2);
    expect($result->value[0])->toBeInstanceOf(Paragraph::class);
    expect($result->value[1])->toBeInstanceOf(Paragraph::class);
});
