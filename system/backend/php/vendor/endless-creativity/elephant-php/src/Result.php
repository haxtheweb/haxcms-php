<?php

declare(strict_types=1);

// Ported from mammoth.js: lib/results.js

namespace EndlessCreativity\ElephantPhp;

/**
 * @template-covariant T
 */
final readonly class Result
{
    /**
     * @param  T  $value
     * @param  list<Message>  $messages
     */
    public function __construct(
        public mixed $value,
        public array $messages = [],
    ) {
    }

    /**
     * @template U
     *
     * @param  U  $value
     * @return self<U>
     */
    public static function success(mixed $value): self
    {
        return new self(value: $value);
    }

    /**
     * @template U
     *
     * @param  callable(T): U  $fn
     * @return self<U>
     */
    public function map(callable $fn): self
    {
        return new self(value: $fn($this->value), messages: $this->messages);
    }

    /**
     * @template U
     *
     * @param  callable(T): self<U>  $fn
     * @return self<U>
     */
    public function flatMap(callable $fn): self
    {
        $next = $fn($this->value);

        return new self(
            value: $next->value,
            messages: self::mergeMessages([$this->messages, $next->messages]),
        );
    }

    /**
     * Combines a list of results whose values are themselves lists, flattening
     * the values into a single list and de-duplicating equal messages.
     *
     * @template U
     *
     * @param  list<self<list<U>>>  $results
     * @return self<list<U>>
     */
    public static function combine(array $results): self
    {
        $values = [];
        $messageBuckets = [];
        foreach ($results as $result) {
            foreach ($result->value as $value) {
                $values[] = $value;
            }
            $messageBuckets[] = $result->messages;
        }

        return new self(value: $values, messages: self::mergeMessages($messageBuckets));
    }

    /**
     * @param  list<list<Message>>  $buckets
     * @return list<Message>
     */
    private static function mergeMessages(array $buckets): array
    {
        $merged = [];
        foreach ($buckets as $bucket) {
            foreach ($bucket as $message) {
                foreach ($merged as $seen) {
                    if ($seen->equals($message)) {
                        continue 2;
                    }
                }
                $merged[] = $message;
            }
        }

        return $merged;
    }
}
