<?php

declare(strict_types=1);

use EndlessCreativity\ElephantPhp\Document\BookmarkStart;
use EndlessCreativity\ElephantPhp\Document\Comment;
use EndlessCreativity\ElephantPhp\Document\CommentReference;
use EndlessCreativity\ElephantPhp\Document\Comments;
use EndlessCreativity\ElephantPhp\Document\Document;
use EndlessCreativity\ElephantPhp\Document\DocumentConverter;
use EndlessCreativity\ElephantPhp\Document\Note;
use EndlessCreativity\ElephantPhp\Document\NoteReference;
use EndlessCreativity\ElephantPhp\Document\Notes;
use EndlessCreativity\ElephantPhp\Document\NoteType;
use EndlessCreativity\ElephantPhp\Document\Paragraph;
use EndlessCreativity\ElephantPhp\Document\Run;
use EndlessCreativity\ElephantPhp\Document\Text;
use EndlessCreativity\ElephantPhp\Style\StyleMap;
use EndlessCreativity\ElephantPhp\Style\StyleMapParser;

function withPrefix(string $prefix): DocumentConverter
{
    return new DocumentConverter(idPrefix: $prefix);
}

it('prepends idPrefix to the bookmark anchor id', function (): void {
    $document = new Document(children: [
        new Paragraph(children: [new BookmarkStart(name: '_Toc1')]),
    ]);

    $html = withPrefix('doc-42-')->convertToHtml($document)->value;

    expect($html)->toContain('<a id="doc-42-_Toc1">');
});

it('prepends idPrefix to footnote target and back-reference', function (): void {
    $document = new Document(
        children: [new Paragraph(children: [
            new Run(children: [new Text(value: 'See')]),
            new NoteReference(noteType: NoteType::Footnote, noteId: '1'),
        ])],
        notes: new Notes([new Note(
            noteType: NoteType::Footnote,
            noteId: '1',
            body: [new Paragraph(children: [new Run(children: [new Text(value: 'body')])])],
        )]),
    );

    $html = withPrefix('doc-42-')->convertToHtml($document)->value;

    expect($html)
        ->toContain('href="#doc-42-footnote-1"')
        ->toContain('id="doc-42-footnote-ref-1"')
        ->toContain('<li id="doc-42-footnote-1">')
        ->toContain('href="#doc-42-footnote-ref-1"');
});

it('prepends idPrefix to comment reference and target', function (): void {
    // Style-map needs an explicit `comment-reference => sup` mapping for
    // comments to be rendered (opt-in, like in the production tests).
    $styleMap = StyleMap::default()->prepend(
        StyleMapParser::parseAll(['comment-reference => sup'])->mappings,
    );

    $document = new Document(
        children: [new Paragraph(children: [
            new Run(children: [new Text(value: 'Look')]),
            new CommentReference(commentId: '7'),
        ])],
        comments: new Comments([new Comment(
            commentId: '7',
            body: [new Paragraph(children: [new Run(children: [new Text(value: 'note')])])],
            authorName: null,
            authorInitials: null,
        )]),
    );

    $html = (new DocumentConverter(styleMap: $styleMap, idPrefix: 'p-'))
        ->convertToHtml($document)
        ->value;

    expect($html)
        ->toContain('href="#p-comment-7"')
        ->toContain('id="p-comment-ref-7"')
        ->toContain('id="p-comment-7"')
        ->toContain('href="#p-comment-ref-7"');
});

it('emits raw ids when no idPrefix is configured (default)', function (): void {
    $document = new Document(children: [
        new Paragraph(children: [new BookmarkStart(name: '_Toc1')]),
    ]);

    $html = (new DocumentConverter())->convertToHtml($document)->value;

    expect($html)->toContain('<a id="_Toc1">');
});
