<?php

declare(strict_types=1);

// Ported from mammoth.js: lib/documents.js (Notes)

namespace EndlessCreativity\ElephantPhp\Document;

final readonly class Notes
{
    /** @var array<string, Note>  Indexed by "{noteType}-{noteId}". */
    private array $byKey;

    /**
     * @param  list<Note>  $notes
     */
    public function __construct(public array $notes = [])
    {
        $byKey = [];
        foreach ($notes as $note) {
            $byKey[self::keyFor($note->noteType, $note->noteId)] = $note;
        }
        $this->byKey = $byKey;
    }

    public static function default(): self
    {
        return new self();
    }

    public function resolve(NoteReference $reference): ?Note
    {
        return $this->byKey[self::keyFor($reference->noteType, $reference->noteId)] ?? null;
    }

    private static function keyFor(NoteType $type, string $id): string
    {
        return $type->value.'-'.$id;
    }
}
