<?php

declare(strict_types=1);

use EndlessCreativity\ElephantPhp\Document\Comment;
use EndlessCreativity\ElephantPhp\Document\CommentReference;
use EndlessCreativity\ElephantPhp\Document\Comments;
use EndlessCreativity\ElephantPhp\Document\Document;
use EndlessCreativity\ElephantPhp\Document\DocumentConverter;
use EndlessCreativity\ElephantPhp\Document\Paragraph;
use EndlessCreativity\ElephantPhp\Document\Run;
use EndlessCreativity\ElephantPhp\Document\Text;
use EndlessCreativity\ElephantPhp\Style\MatcherKind;
use EndlessCreativity\ElephantPhp\Style\StyleMap;
use EndlessCreativity\ElephantPhp\Style\StyleMapParser;

it('parses comment-reference as its own matcher kind', function (): void {
    $mapping = StyleMapParser::parse('comment-reference => sup');

    expect($mapping->from->kind)->toBe(MatcherKind::CommentReference);
    expect($mapping->to->elements[0]->tagName)->toBe('sup');
});

function commentDocument(string $body, ?string $authorInitials = null): Document
{
    return new Document(
        children: [new Paragraph(children: [
            new Run(children: [new Text(value: 'see ')]),
            new CommentReference(commentId: '1'),
        ])],
        comments: new Comments([new Comment(
            commentId: '1',
            body: [new Paragraph(children: [new Run(children: [new Text(value: $body)])])],
            authorInitials: $authorInitials,
        )]),
    );
}

it('renders inline comment reference + <dl> section when comment-reference is mapped', function (): void {
    $styleMap = StyleMap::default()->prepend([StyleMapParser::parse('comment-reference => sup')]);
    $document = commentDocument(body: 'A tachyon walks into a bar.', authorInitials: 'MW');

    $html = (new DocumentConverter(styleMap: $styleMap))->convertToHtml($document)->value;

    expect($html)->toContain('<sup><a href="#comment-1" id="comment-ref-1">[MW1]</a></sup>');
    expect($html)->toContain('<dl>');
    expect($html)->toContain('<dt id="comment-1">Comment [MW1]</dt>');
    expect($html)->toContain('A tachyon walks into a bar.');
    expect($html)->toContain('<a href="#comment-ref-1">↑</a>');
});

it('omits author initials when none are set, leaving the count alone', function (): void {
    $styleMap = StyleMap::default()->prepend([StyleMapParser::parse('comment-reference => sup')]);
    $document = commentDocument(body: 'no author');

    $html = (new DocumentConverter(styleMap: $styleMap))->convertToHtml($document)->value;

    expect($html)->toContain('<sup><a href="#comment-1" id="comment-ref-1">[1]</a></sup>');
});

it('still drops comment references when no comment-reference mapping is provided', function (): void {
    $document = commentDocument(body: 'unused');

    $html = (new DocumentConverter())->convertToHtml($document)->value;

    expect($html)->toBe('<p>see </p>');
});
