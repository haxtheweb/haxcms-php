<?php

declare(strict_types=1);

use EndlessCreativity\ElephantPhp\Document\Document;
use EndlessCreativity\ElephantPhp\Document\DocumentConverter;
use EndlessCreativity\ElephantPhp\Document\Note;
use EndlessCreativity\ElephantPhp\Document\NoteReference;
use EndlessCreativity\ElephantPhp\Document\Notes;
use EndlessCreativity\ElephantPhp\Document\NoteType;
use EndlessCreativity\ElephantPhp\Document\Paragraph;
use EndlessCreativity\ElephantPhp\Document\Run;
use EndlessCreativity\ElephantPhp\Document\Text;

function noteParagraph(string $body): Paragraph
{
    return new Paragraph(children: [new Run(children: [new Text(value: $body)])]);
}

it('renders a note reference as <sup><a> and appends the note section', function (): void {
    $document = new Document(
        children: [new Paragraph(children: [
            new Run(children: [new Text(value: 'See')]),
            new NoteReference(noteType: NoteType::Footnote, noteId: '1'),
        ])],
        notes: new Notes([new Note(
            noteType: NoteType::Footnote,
            noteId: '1',
            body: [noteParagraph('the footnote text')],
        )]),
    );

    $html = (new DocumentConverter())->convertToHtml($document)->value;

    expect($html)->toContain('<sup><a href="#footnote-1" id="footnote-ref-1">[1]</a></sup>');
    expect($html)->toContain('<li id="footnote-1">');
    expect($html)->toContain('the footnote text');
    expect($html)->toContain('<a href="#footnote-ref-1">↑</a>');
});

it('numbers note references sequentially even when noteIds are non-numeric', function (): void {
    $document = new Document(
        children: [new Paragraph(children: [
            new NoteReference(noteType: NoteType::Footnote, noteId: 'a'),
            new NoteReference(noteType: NoteType::Endnote, noteId: 'b'),
        ])],
        notes: new Notes([
            new Note(noteType: NoteType::Footnote, noteId: 'a', body: [noteParagraph('first')]),
            new Note(noteType: NoteType::Endnote, noteId: 'b', body: [noteParagraph('second')]),
        ]),
    );

    $html = (new DocumentConverter())->convertToHtml($document)->value;

    expect($html)->toContain('[1]');
    expect($html)->toContain('[2]');
    expect($html)->toContain('<li id="footnote-a">');
    expect($html)->toContain('<li id="endnote-b">');
});

it('does not append a notes section when no NoteReference appears', function (): void {
    $document = new Document(children: [
        new Paragraph(children: [new Run(children: [new Text(value: 'plain')])]),
    ]);

    $html = (new DocumentConverter())->convertToHtml($document)->value;

    expect($html)->toBe('<p>plain</p>');
});

it('skips note references that cannot be resolved against the notes map', function (): void {
    $document = new Document(
        children: [new Paragraph(children: [
            new NoteReference(noteType: NoteType::Footnote, noteId: 'missing'),
        ])],
        notes: new Notes([]),
    );

    $html = (new DocumentConverter())->convertToHtml($document)->value;

    // The reference still renders inline, but no <ol> follows because no
    // resolvable note exists.
    expect($html)->toContain('[1]');
    expect($html)->not->toContain('<ol>');
});
