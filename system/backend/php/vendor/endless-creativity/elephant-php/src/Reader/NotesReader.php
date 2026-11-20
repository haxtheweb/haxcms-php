<?php

declare(strict_types=1);

// Ported from mammoth.js: lib/docx/notes-reader.js

namespace EndlessCreativity\ElephantPhp\Reader;

use EndlessCreativity\ElephantPhp\Document\Note;
use EndlessCreativity\ElephantPhp\Document\NoteType;
use EndlessCreativity\ElephantPhp\Reader\Xml\Element;
use EndlessCreativity\ElephantPhp\Result;

final readonly class NotesReader
{
    public function __construct(private BodyReader $bodyReader)
    {
    }

    /**
     * @return Result<list<Note>>
     */
    public function readFromXml(Element $root, NoteType $type): Result
    {
        $tagName = 'w:'.$type->value;
        $perNote = [];
        foreach ($root->getElementsByTagName($tagName) as $noteElement) {
            // The continuation/separator pseudo-notes are layout, not content.
            $kind = $noteElement->attribute('w:type');
            if ($kind === 'separator' || $kind === 'continuationSeparator') {
                continue;
            }
            $noteId = $noteElement->attribute('w:id');
            if ($noteId === null) {
                continue;
            }

            $perNote[] = $this->bodyReader->readXmlElements($noteElement->children)
                ->map(fn (array $body): array => [new Note(
                    noteType: $type,
                    noteId: $noteId,
                    body: $body,
                )]);
        }

        return Result::combine($perNote);
    }
}
