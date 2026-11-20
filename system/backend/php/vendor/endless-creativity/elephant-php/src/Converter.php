<?php

declare(strict_types=1);

// Ported from mammoth.js: lib/index.js + lib/docx/office-xml-reader.js

namespace EndlessCreativity\ElephantPhp;

use Closure;
use EndlessCreativity\ElephantPhp\Document\Comments;
use EndlessCreativity\ElephantPhp\Document\Document;
use EndlessCreativity\ElephantPhp\Document\DocumentConverter;
use EndlessCreativity\ElephantPhp\Document\Notes;
use EndlessCreativity\ElephantPhp\Document\NoteType;
use EndlessCreativity\ElephantPhp\Document\RawTextExtractor;
use EndlessCreativity\ElephantPhp\Image\DataUriImageHandler;
use EndlessCreativity\ElephantPhp\Image\ImageHandler;
use EndlessCreativity\ElephantPhp\Reader\BodyReader;
use EndlessCreativity\ElephantPhp\Reader\CommentsReader;
use EndlessCreativity\ElephantPhp\Reader\ContentTypes;
use EndlessCreativity\ElephantPhp\Reader\ContentTypesReader;
use EndlessCreativity\ElephantPhp\Reader\DocumentXmlReader;
use EndlessCreativity\ElephantPhp\Reader\EmbeddedStyleMap;
use EndlessCreativity\ElephantPhp\Reader\NotesReader;
use EndlessCreativity\ElephantPhp\Reader\Numbering;
use EndlessCreativity\ElephantPhp\Reader\NumberingReader;
use EndlessCreativity\ElephantPhp\Reader\Relationships;
use EndlessCreativity\ElephantPhp\Reader\RelationshipsReader;
use EndlessCreativity\ElephantPhp\Reader\Styles;
use EndlessCreativity\ElephantPhp\Reader\StylesReader;
use EndlessCreativity\ElephantPhp\Reader\Xml\XmlReader;
use EndlessCreativity\ElephantPhp\Reader\ZipFile;
use EndlessCreativity\ElephantPhp\Style\StyleMap;
use EndlessCreativity\ElephantPhp\Style\StyleMapParser;
use InvalidArgumentException;

final class Converter
{
    /** @var array<string, string> */
    private const OFFICE_XML_NAMESPACE_MAP = [
        // Transitional format
        'http://schemas.openxmlformats.org/wordprocessingml/2006/main' => 'w',
        'http://schemas.openxmlformats.org/officeDocument/2006/relationships' => 'r',
        'http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing' => 'wp',
        'http://schemas.openxmlformats.org/drawingml/2006/main' => 'a',
        'http://schemas.openxmlformats.org/drawingml/2006/picture' => 'pic',

        // Strict format
        'http://purl.oclc.org/ooxml/wordprocessingml/main' => 'w',
        'http://purl.oclc.org/ooxml/officeDocument/relationships' => 'r',
        'http://purl.oclc.org/ooxml/drawingml/wordprocessingDrawing' => 'wp',
        'http://purl.oclc.org/ooxml/drawingml/main' => 'a',
        'http://purl.oclc.org/ooxml/drawingml/picture' => 'pic',

        // Common
        'http://schemas.openxmlformats.org/package/2006/content-types' => 'content-types',
        'http://schemas.openxmlformats.org/package/2006/relationships' => 'relationships',
        'http://schemas.openxmlformats.org/markup-compatibility/2006' => 'mc',
        'urn:schemas-microsoft-com:vml' => 'v',
        'urn:schemas-microsoft-com:office:word' => 'office-word',

        'http://schemas.microsoft.com/office/word/2010/wordml' => 'wordml',
    ];

    private readonly StyleMap $styleMap;

    /**
     * @param  list<string>|null  $styleMap  Optional list of mapping rules in
     *                                       mammoth's DSL (e.g. "p[style-name=
     *                                       'Aside'] => p.aside"). Rules are
     *                                       prepended to the default heading
     *                                       map and tried first.
     */
    public function __construct(
        ?array $styleMap = null,
        private readonly ImageHandler $imageHandler = new DataUriImageHandler(),
        // Prepended to every HTML `id` attribute we emit (and to the
        // matching `#fragment` hrefs). Useful when embedding the
        // converted document inside a larger page so its bookmark,
        // footnote and comment ids don't collide.
        private readonly string $idPrefix = '',
        // When true (the mammoth default), paragraphs that resolve to
        // no rendered children are dropped. Set to false to preserve
        // them as `<p></p>` so the document's intentional vertical
        // spacing survives the conversion.
        private readonly bool $ignoreEmptyParagraphs = true,
        // When true, the HTML writer emits indented multi-line output
        // for block elements. Affects only `convertToHtml`; ignored by
        // Markdown and raw-text conversion.
        private readonly bool $prettyPrint = false,
        // When true (the default), runs carrying the `w:vanish`
        // ("Hidden") font effect are dropped so the output matches what
        // Word shows on screen. This diverges from mammoth.js, which
        // keeps hidden text. Set to false to emit hidden runs as text.
        private readonly bool $ignoreHiddenText = true,
        // Optional callback that receives the parsed `Document` and
        // returns a (typically modified) `Document` to convert in its
        // place. Mirrors mammoth's `transformDocument` hook -- useful
        // for stripping comments, rewriting hyperlinks, normalising
        // images etc. without forking the converter. Document is
        // immutable, so the callback must produce a new instance.
        private readonly ?Closure $transformDocument = null,
    ) {
        $base = StyleMap::default();
        $this->styleMap = $styleMap === null
            ? $base
            : $base->prepend(StyleMapParser::parseAll($styleMap)->mappings);
    }

    /**
     * @return Result<string>
     */
    public function convertToHtml(string $path): Result
    {
        return $this->convert(
            $path,
            static fn (DocumentConverter $converter, $document): Result => $converter->convertToHtml($document),
        );
    }

    /**
     * @return Result<string>
     */
    public function convertToMarkdown(string $path): Result
    {
        return $this->convert(
            $path,
            static fn (DocumentConverter $converter, $document): Result => $converter->convertToMarkdown($document),
        );
    }

    /**
     * Reads the style map embedded in a docx as a mammoth-compatible part
     * (`mammoth/style-map`), returning the raw rules string or null when
     * the part is absent.
     */
    public static function readEmbeddedStyleMap(string $path): ?string
    {
        return EmbeddedStyleMap::read(ZipFile::openPath($path));
    }

    /**
     * Embeds the given style-map rules into a copy of the docx at $path,
     * registering the part with the relationships table and content types
     * exactly as mammoth does. Returns the modified docx as a byte string;
     * the source file is untouched.
     */
    public static function embedStyleMap(string $path, string $styleMap): string
    {
        return EmbeddedStyleMap::embed($path, $styleMap);
    }

    /**
     * Extracts plain text from the document body, mirroring mammoth's
     * extractRawText. Style maps and the image handler are not consulted;
     * paragraphs are separated by "\n\n", everything else just contributes
     * its descendant text.
     *
     * @return Result<string>
     */
    public function extractRawText(string $path): Result
    {
        return $this->convert(
            $path,
            static fn (DocumentConverter $_, $document): Result => Result::success(RawTextExtractor::extract($document)),
        );
    }

    /**
     * @param  callable(DocumentConverter, \EndlessCreativity\ElephantPhp\Document\Document): Result<string>  $write
     * @return Result<string>
     */
    private function convert(string $path, callable $write): Result
    {
        $zip = ZipFile::openPath($path);

        $styles = $zip->exists('word/styles.xml')
            ? StylesReader::readFromXml(XmlReader::readString(
                self::stripUtf8Bom($zip->read('word/styles.xml')),
                self::OFFICE_XML_NAMESPACE_MAP,
            ))
            : Styles::default();

        $relationships = $zip->exists('word/_rels/document.xml.rels')
            ? RelationshipsReader::readFromXml(XmlReader::readString(
                self::stripUtf8Bom($zip->read('word/_rels/document.xml.rels')),
                self::OFFICE_XML_NAMESPACE_MAP,
            ))
            : Relationships::default();

        $numbering = $zip->exists('word/numbering.xml')
            ? NumberingReader::readFromXml(
                XmlReader::readString(
                    self::stripUtf8Bom($zip->read('word/numbering.xml')),
                    self::OFFICE_XML_NAMESPACE_MAP,
                ),
                $styles,
            )
            : Numbering::default();

        $contentTypes = $zip->exists('[Content_Types].xml')
            ? ContentTypesReader::readFromXml(XmlReader::readString(
                self::stripUtf8Bom($zip->read('[Content_Types].xml')),
                self::OFFICE_XML_NAMESPACE_MAP,
            ))
            : ContentTypes::default();

        $bodyReader = new BodyReader(
            styles: $styles,
            relationships: $relationships,
            numbering: $numbering,
            contentTypes: $contentTypes,
            imageReader: static fn (string $path): string => $zip->read($path),
        );

        $notesReader = new NotesReader($bodyReader);
        $noteList = [];
        $noteMessages = [];
        $noteParts = [
            [NoteType::Footnote, 'word/footnotes.xml'],
            [NoteType::Endnote, 'word/endnotes.xml'],
        ];
        foreach ($noteParts as [$type, $entryName]) {
            if (! $zip->exists($entryName)) {
                continue;
            }
            $notesResult = $notesReader->readFromXml(
                XmlReader::readString(
                    self::stripUtf8Bom($zip->read($entryName)),
                    self::OFFICE_XML_NAMESPACE_MAP,
                ),
                $type,
            );
            foreach ($notesResult->value as $note) {
                $noteList[] = $note;
            }
            foreach ($notesResult->messages as $message) {
                $noteMessages[] = $message;
            }
        }
        $notes = new Notes($noteList);

        $commentMessages = [];
        if ($zip->exists('word/comments.xml')) {
            $commentsResult = (new CommentsReader($bodyReader))->readFromXml(
                XmlReader::readString(
                    self::stripUtf8Bom($zip->read('word/comments.xml')),
                    self::OFFICE_XML_NAMESPACE_MAP,
                ),
            );
            $comments = new Comments($commentsResult->value);
            foreach ($commentsResult->messages as $message) {
                $commentMessages[] = $message;
            }
        } else {
            $comments = Comments::default();
        }

        $documentXml = self::stripUtf8Bom($zip->read('word/document.xml'));
        $documentElement = XmlReader::readString($documentXml, self::OFFICE_XML_NAMESPACE_MAP);

        $documentResult = (new DocumentXmlReader($bodyReader, $notes, $comments))
            ->convertXmlToDocument($documentElement);

        $document = $documentResult->value;
        if ($this->transformDocument !== null) {
            $transformed = ($this->transformDocument)($document);
            if (! $transformed instanceof Document) {
                throw new InvalidArgumentException(
                    'transformDocument callback must return a Document instance, got '
                    .get_debug_type($transformed),
                );
            }
            $document = $transformed;
        }

        $converter = new DocumentConverter(
            styleMap: $this->styleMap,
            imageHandler: $this->imageHandler,
            idPrefix: $this->idPrefix,
            ignoreEmptyParagraphs: $this->ignoreEmptyParagraphs,
            prettyPrint: $this->prettyPrint,
            ignoreHiddenText: $this->ignoreHiddenText,
        );
        $htmlResult = $write($converter, $document);

        return new Result(
            value: $htmlResult->value,
            messages: array_merge($noteMessages, $commentMessages, $documentResult->messages, $htmlResult->messages),
        );
    }

    private static function stripUtf8Bom(string $value): string
    {
        return str_starts_with($value, "\u{FEFF}") ? mb_substr($value, 1) : $value;
    }
}
