<?php

declare(strict_types=1);

// Ported from mammoth.js: lib/results.js

namespace EndlessCreativity\ElephantPhp;

final readonly class Message
{
    public function __construct(
        public MessageType $type,
        public string $message,
    ) {
    }

    public static function warning(string $message): self
    {
        return new self(type: MessageType::Warning, message: $message);
    }

    public static function error(string $message): self
    {
        return new self(type: MessageType::Error, message: $message);
    }

    public function equals(self $other): bool
    {
        return $this->type === $other->type && $this->message === $other->message;
    }
}
