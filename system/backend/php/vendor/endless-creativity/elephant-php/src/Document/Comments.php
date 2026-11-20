<?php

declare(strict_types=1);

// Ported from mammoth.js: lib/document-to-html.js (comments index)

namespace EndlessCreativity\ElephantPhp\Document;

final readonly class Comments
{
    /** @var array<string, Comment> */
    private array $byId;

    /**
     * @param  list<Comment>  $comments
     */
    public function __construct(public array $comments = [])
    {
        $byId = [];
        foreach ($comments as $comment) {
            $byId[$comment->commentId] = $comment;
        }
        $this->byId = $byId;
    }

    public static function default(): self
    {
        return new self();
    }

    public function findById(string $commentId): ?Comment
    {
        return $this->byId[$commentId] ?? null;
    }
}
