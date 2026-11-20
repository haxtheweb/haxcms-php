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

it('drops comment references by default, leaving only the surrounding text', function (): void {
    $document = new Document(
        children: [new Paragraph(children: [
            new Run(children: [new Text(value: 'before ')]),
            new CommentReference(commentId: '1'),
            new Run(children: [new Text(value: 'after')]),
        ])],
        comments: new Comments([new Comment(commentId: '1', body: [])]),
    );

    expect((new DocumentConverter())->convertToHtml($document)->value)
        ->toBe('<p>before after</p>');
});
