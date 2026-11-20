<?php

declare(strict_types=1);

// Ported from mammoth.js: lib/docx/document-xml-reader.js

namespace EndlessCreativity\ElephantPhp\Reader;

use EndlessCreativity\ElephantPhp\Document\Comments;
use EndlessCreativity\ElephantPhp\Document\Document;
use EndlessCreativity\ElephantPhp\Document\Notes;
use EndlessCreativity\ElephantPhp\Reader\Xml\Element;
use EndlessCreativity\ElephantPhp\Result;
use RuntimeException;

final readonly class DocumentXmlReader
{
    public function __construct(
        private BodyReader $bodyReader,
        private Notes $notes = new Notes(),
        private Comments $comments = new Comments(),
    ) {
    }

    /**
     * @return Result<Document>
     */
    public function convertXmlToDocument(Element $element): Result
    {
        $body = $element->first('w:body');
        if ($body === null) {
            throw new RuntimeException('Could not find the body element: are you sure this is a docx file?');
        }

        return $this->bodyReader->readXmlElements($body->children)
            ->map(fn (array $children): Document => new Document(
                children: $children,
                notes: $this->notes,
                comments: $this->comments,
            ));
    }
}
