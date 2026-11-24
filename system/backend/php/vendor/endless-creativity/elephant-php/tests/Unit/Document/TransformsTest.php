<?php

declare(strict_types=1);

use EndlessCreativity\ElephantPhp\Document\Document;
use EndlessCreativity\ElephantPhp\Document\Hyperlink;
use EndlessCreativity\ElephantPhp\Document\Paragraph;
use EndlessCreativity\ElephantPhp\Document\Run;
use EndlessCreativity\ElephantPhp\Document\Text;
use EndlessCreativity\ElephantPhp\Document\Transforms;

function sampleDocument(): Document
{
    // Document {p1[run1[t1]], p2[hyperlink[run2[t2]]]}
    return new Document(children: [
        new Paragraph(children: [
            new Run(children: [new Text(value: 'one')]),
        ]),
        new Paragraph(children: [
            new Hyperlink(href: 'https://example.com', children: [
                new Run(children: [new Text(value: 'two')]),
            ]),
        ]),
    ]);
}

it('walks every Paragraph with Transforms::paragraph', function (): void {
    // Tag every paragraph with a styleId.
    $tagger = Transforms::paragraph(static function (Paragraph $p): Paragraph {
        return new Paragraph(
            children: $p->children,
            styleId: 'tagged',
            styleName: $p->styleName,
            numbering: $p->numbering,
        );
    });

    $document = $tagger(sampleDocument());

    $paragraphs = Transforms::getDescendantsOfType($document, Paragraph::class);
    expect($paragraphs)->toHaveCount(2);
    foreach ($paragraphs as $p) {
        expect($p->styleId)->toBe('tagged');
    }
});

it('replaces every Run with Transforms::run', function (): void {
    $boldifier = Transforms::run(static function (Run $r): Run {
        return new Run(children: $r->children, isBold: true, font: $r->font);
    });

    $document = $boldifier(sampleDocument());

    $runs = Transforms::getDescendantsOfType($document, Run::class);
    expect($runs)->toHaveCount(2);
    foreach ($runs as $r) {
        expect($r->isBold)->toBeTrue();
    }
});

it('does not modify nodes whose type does not match elementsOfType', function (): void {
    $hyperlinkSpy = 0;
    $callback = Transforms::elementsOfType(Hyperlink::class, function (Hyperlink $h) use (&$hyperlinkSpy) {
        $hyperlinkSpy++;

        return $h;
    });

    $callback(sampleDocument());

    expect($hyperlinkSpy)->toBe(1);
});

it('walks post-order: descendants before their parent in elements()', function (): void {
    $order = [];
    $callback = Transforms::elements(function ($node) use (&$order) {
        $order[] = $node::class;

        return $node;
    });

    $callback(sampleDocument());

    // First leaves (Text), then their wrappers (Run / Hyperlink), then
    // Paragraph, then Document.
    expect($order[0])->toBe(Text::class);
    expect(array_search(Document::class, $order, true))->toBe(count($order) - 1);
});

it('returns the rebuilt tree from elements() so users can chain transforms', function (): void {
    // Strip every Hyperlink wrapper, keeping its children inline.
    $unlink = Transforms::elements(function ($node) {
        if ($node instanceof Hyperlink) {
            // Replace the hyperlink with a plain Run wrapping the same text.
            $textValues = array_map(
                static fn (Run $r): string => $r->children[0] instanceof Text ? $r->children[0]->value : '',
                array_values(array_filter($node->children, static fn ($c) => $c instanceof Run)),
            );

            return new Run(children: [new Text(value: implode('', $textValues))]);
        }

        return $node;
    });

    $document = $unlink(sampleDocument());

    expect(Transforms::getDescendantsOfType($document, Hyperlink::class))->toHaveCount(0);
    $runs = Transforms::getDescendantsOfType($document, Run::class);
    expect($runs)->toHaveCount(2);
});

it('lists every descendant in post-order via getDescendants', function (): void {
    $simple = new Paragraph(children: [
        new Run(children: [new Text(value: 'hi')]),
    ]);

    $descendants = Transforms::getDescendants($simple);

    // Text first (deepest), then Run (its parent). Paragraph itself is the
    // root and is not in the list.
    expect($descendants)->toHaveCount(2);
    expect($descendants[0])->toBeInstanceOf(Text::class);
    expect($descendants[1])->toBeInstanceOf(Run::class);
});

it('returns an empty list when getDescendants is called on a leaf node', function (): void {
    expect(Transforms::getDescendants(new Text(value: 'x')))->toBe([]);
});

it('filters by class with getDescendantsOfType', function (): void {
    $document = sampleDocument();

    expect(Transforms::getDescendantsOfType($document, Text::class))->toHaveCount(2);
    expect(Transforms::getDescendantsOfType($document, Run::class))->toHaveCount(2);
    expect(Transforms::getDescendantsOfType($document, Paragraph::class))->toHaveCount(2);
    expect(Transforms::getDescendantsOfType($document, Hyperlink::class))->toHaveCount(1);
});
