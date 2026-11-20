<?php

declare(strict_types=1);

use EndlessCreativity\ElephantPhp\Message;
use EndlessCreativity\ElephantPhp\Result;

it('exposes value and messages', function (): void {
    $result = Result::success('hello');

    expect($result->value)->toBe('hello');
    expect($result->messages)->toBe([]);
});

it('maps the value while preserving messages', function (): void {
    $messages = [Message::warning('careful')];
    $result = new Result(value: 1, messages: $messages);

    $mapped = $result->map(fn (int $value): int => $value * 2);

    expect($mapped->value)->toBe(2);
    expect($mapped->messages)->toEqual($messages);
});

it('flatMap concatenates messages from both results', function (): void {
    $result = new Result(value: 'a', messages: [Message::warning('first')]);

    $combined = $result->flatMap(fn (string $value): Result => new Result(
        value: strtoupper($value),
        messages: [Message::warning('second')],
    ));

    expect($combined->value)->toBe('A');
    expect($combined->messages)->toEqual([
        Message::warning('first'),
        Message::warning('second'),
    ]);
});

it('combine flattens list-typed values and dedupes equal messages', function (): void {
    $combined = Result::combine([
        new Result(value: ['a'], messages: [Message::warning('Warning...')]),
        new Result(value: ['b', 'c'], messages: [Message::warning('Warning...')]),
    ]);

    expect($combined->value)->toBe(['a', 'b', 'c']);
    expect($combined->messages)->toEqual([Message::warning('Warning...')]);
});
