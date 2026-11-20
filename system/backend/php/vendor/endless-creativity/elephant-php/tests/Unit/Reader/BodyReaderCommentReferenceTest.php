<?php

declare(strict_types=1);

use EndlessCreativity\ElephantPhp\Document\CommentReference;
use EndlessCreativity\ElephantPhp\Reader\BodyReader;
use EndlessCreativity\ElephantPhp\Reader\Xml\Element;

it('reads w:commentReference into a CommentReference node', function (): void {
    $element = new Element(name: 'w:commentReference', attributes: ['w:id' => '7']);

    $result = (new BodyReader())->readXmlElement($element);

    expect($result->messages)->toBe([]);
    expect($result->value)->toBeInstanceOf(CommentReference::class);
    /** @var CommentReference $reference */
    $reference = $result->value;
    expect($reference->commentId)->toBe('7');
});

it('drops a w:commentReference with no w:id silently', function (): void {
    $element = new Element(name: 'w:commentReference');

    $result = (new BodyReader())->readXmlElement($element);

    expect($result->value)->toBeNull();
    expect($result->messages)->toBe([]);
});
