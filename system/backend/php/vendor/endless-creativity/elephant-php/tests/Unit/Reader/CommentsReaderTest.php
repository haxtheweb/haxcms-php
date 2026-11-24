<?php

declare(strict_types=1);

use EndlessCreativity\ElephantPhp\Reader\BodyReader;
use EndlessCreativity\ElephantPhp\Reader\CommentsReader;
use EndlessCreativity\ElephantPhp\Reader\Xml\Element;
use EndlessCreativity\ElephantPhp\Reader\Xml\Text as XmlText;

/**
 * @param  array<string, string>  $attributes
 */
function commentXml(array $attributes, string $text): Element
{
    return new Element(name: 'w:comment', attributes: $attributes, children: [
        new Element(name: 'w:p', children: [
            new Element(name: 'w:r', children: [
                new Element(name: 'w:t', children: [new XmlText(value: $text)]),
            ]),
        ]),
    ]);
}

it('reads comments with id, author, initials, and body', function (): void {
    $root = new Element(name: 'w:comments', children: [
        commentXml(
            attributes: ['w:id' => '0', 'w:author' => 'Michael Williamson', 'w:initials' => 'MW'],
            text: 'first',
        ),
        commentXml(
            attributes: ['w:id' => '2', 'w:author' => 'Michael Williamson', 'w:initials' => 'MW'],
            text: 'second',
        ),
    ]);

    $result = (new CommentsReader(new BodyReader()))->readFromXml($root);

    expect($result->messages)->toBe([]);
    expect($result->value)->toHaveCount(2);
    expect($result->value[0]->commentId)->toBe('0');
    expect($result->value[0]->authorName)->toBe('Michael Williamson');
    expect($result->value[0]->authorInitials)->toBe('MW');
    expect($result->value[1]->commentId)->toBe('2');
});

it('treats blank or missing author/initials as null', function (): void {
    $root = new Element(name: 'w:comments', children: [
        commentXml(attributes: ['w:id' => '1', 'w:author' => '   ', 'w:initials' => ''], text: 't'),
    ]);

    $result = (new CommentsReader(new BodyReader()))->readFromXml($root);

    expect($result->value[0]->authorName)->toBeNull();
    expect($result->value[0]->authorInitials)->toBeNull();
});

it('skips a w:comment without an id', function (): void {
    $root = new Element(name: 'w:comments', children: [
        new Element(name: 'w:comment'),
        commentXml(attributes: ['w:id' => '1'], text: 't'),
    ]);

    $result = (new CommentsReader(new BodyReader()))->readFromXml($root);

    expect($result->value)->toHaveCount(1);
    expect($result->value[0]->commentId)->toBe('1');
});
