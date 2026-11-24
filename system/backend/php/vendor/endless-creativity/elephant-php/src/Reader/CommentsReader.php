<?php

declare(strict_types=1);

// Ported from mammoth.js: lib/docx/comments-reader.js

namespace EndlessCreativity\ElephantPhp\Reader;

use EndlessCreativity\ElephantPhp\Document\Comment;
use EndlessCreativity\ElephantPhp\Reader\Xml\Element;
use EndlessCreativity\ElephantPhp\Result;

final readonly class CommentsReader
{
    public function __construct(private BodyReader $bodyReader)
    {
    }

    /**
     * @return Result<list<Comment>>
     */
    public function readFromXml(Element $root): Result
    {
        $perComment = [];
        foreach ($root->getElementsByTagName('w:comment') as $commentElement) {
            $commentId = $commentElement->attribute('w:id');
            if ($commentId === null) {
                continue;
            }

            $authorName = self::trimmedOrNull($commentElement->attribute('w:author'));
            $authorInitials = self::trimmedOrNull($commentElement->attribute('w:initials'));

            $perComment[] = $this->bodyReader->readXmlElements($commentElement->children)
                ->map(fn (array $body): array => [new Comment(
                    commentId: $commentId,
                    body: $body,
                    authorName: $authorName,
                    authorInitials: $authorInitials,
                )]);
        }

        return Result::combine($perComment);
    }

    private static function trimmedOrNull(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
