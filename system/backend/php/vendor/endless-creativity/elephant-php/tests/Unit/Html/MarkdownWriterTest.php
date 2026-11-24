<?php

declare(strict_types=1);

use EndlessCreativity\ElephantPhp\Html\Element;
use EndlessCreativity\ElephantPhp\Html\MarkdownWriter;
use EndlessCreativity\ElephantPhp\Html\Tag;
use EndlessCreativity\ElephantPhp\Html\Text;

/**
 * @param  array<string, string>  $attributes
 * @param  list<\EndlessCreativity\ElephantPhp\Html\Node>  $children
 */
function el(string $tag, array $attributes = [], array $children = []): Element
{
    return new Element(tag: new Tag(tagName: $tag, attributes: $attributes), children: $children);
}

function txt(string $value): Text
{
    return new Text(value: $value);
}

it('renders a paragraph as text followed by two newlines, with no escape on a trailing period', function (): void {
    // The trailing period is *not* escaped: it would only matter at line
    // start after a digit run (`1. `), which is not the case here. Mammoth
    // would have escaped it; we don't.
    expect(MarkdownWriter::write([el('p', children: [txt('Hello.')])]))
        ->toBe("Hello.\n\n");
});

it('hoists leading whitespace out of <strong> and drops it at line start', function (): void {
    // The hoist moves the spaces outside the wrapper so the markdown is
    // valid; the line-start strip then keeps the output as pure text without
    // triggering CommonMark's indented-code-block rule (4+ leading spaces).
    $node = el('p', children: [
        el('strong', children: [txt('    Bau')]),
    ]);

    expect(MarkdownWriter::write([$node]))->toBe("**Bau**\n\n");
});

it('hoists trailing whitespace out of <strong> and keeps it after the wrapper', function (): void {
    // Trailing whitespace lands mid-line, where it does not trigger any
    // CommonMark rule, so it is preserved as-is.
    $node = el('p', children: [
        el('strong', children: [txt('Bau    ')]),
    ]);

    expect(MarkdownWriter::write([$node]))->toBe("**Bau**    \n\n");
});

it('strips line-leading whitespace from an <em> wrapper while keeping trailing', function (): void {
    $node = el('p', children: [
        el('em', children: [txt('  italic  ')]),
    ]);

    expect(MarkdownWriter::write([$node]))->toBe("*italic*  \n\n");
});

it('drops a wrapper that contained only whitespace, leaving an empty paragraph', function (): void {
    $node = el('p', children: [
        el('strong', children: [txt('   ')]),
    ]);

    expect(MarkdownWriter::write([$node]))->toBe("\n\n");
});

it('hoists a leading <br> out of <strong> so the ** opener is adjacent to text', function (): void {
    // Word inserts <w:br/> at the start of a bold run when there is a soft
    // line break before the bold text. The void <br> expands to "  \n"
    // (pure whitespace) and would sit between ** and the first letter,
    // which CommonMark treats as a non-opener -- the bold never closes.
    $node = el('p', children: [
        el('strong', children: [el('br'), txt('Maria Elena Canzoni ')]),
        txt('trascrizione avviata'),
    ]);

    // The hoisted "  \n" leaves leading spaces at line start, which the
    // line-leading-whitespace strip then removes, leaving a blank line
    // before the bold paragraph -- harmless paragraph separation.
    expect(MarkdownWriter::write([$node]))
        ->toBe("\n**Maria Elena Canzoni** trascrizione avviata\n\n");
});

it('hoists a trailing <br> out of <strong> so the ** closer is adjacent to text', function (): void {
    $node = el('p', children: [
        el('strong', children: [txt('Maria Elena Canzoni'), el('br')]),
        txt('trascrizione avviata'),
    ]);

    expect(MarkdownWriter::write([$node]))
        ->toBe("**Maria Elena Canzoni**  \ntrascrizione avviata\n\n");
});

it('hoists whitespace through nested emphasis recursively then strips at line start', function (): void {
    // <strong>  <em>  hi  </em>  </strong>
    $node = el('p', children: [
        el('strong', children: [
            txt('  '),
            el('em', children: [txt('  hi  ')]),
            txt('  '),
        ]),
    ]);

    expect(MarkdownWriter::write([$node]))->toBe("***hi***    \n\n");
});

it('strips a run of leading spaces inside a paragraph, not just at doc start', function (): void {
    $first = el('p', children: [txt('one')]);
    $indented = el('p', children: [txt('    two')]);

    expect(MarkdownWriter::write([$first, $indented]))->toBe("one\n\ntwo\n\n");
});

it('strips a leading tab so paragraphs indented with <w:tab> are not code blocks', function (): void {
    // mammoth/our reader emits <w:tab/> as "\t". Twelve tabs at line start
    // would become an indented code block under CommonMark; strip them.
    $node = el('p', children: [txt("\t\t\t\t\t\t\t\t\t\t\t\tcontro")]);

    expect(MarkdownWriter::write([$node]))->toBe("contro\n\n");
});

it('strips mixed leading tabs and spaces from a paragraph', function (): void {
    $node = el('p', children: [txt("\t \t  contro")]);

    expect(MarkdownWriter::write([$node]))->toBe("contro\n\n");
});

it('does not emit an HTML anchor for an element carrying an id', function (): void {
    // Word fills documents with bookmarks (`_Toc...`, `_Hlk...`,
    // `_Ref...`) which the converter renders as <a id> in HTML. Markdown
    // output must stay HTML-free, so the id is silently dropped here.
    $node = el('h1', children: [
        el('a', attributes: ['id' => '_Toc0']),
        txt('Heading'),
    ]);

    expect(MarkdownWriter::write([$node]))->toBe("# Heading\n\n");
});

it('still renders an <a href> as a normal markdown link even when it carries an id', function (): void {
    // A real link with both id and href: drop the id, keep the link.
    $node = el('p', children: [
        el('a', attributes: ['id' => 'comment-ref-1', 'href' => '#comment-1'], children: [
            txt('see'),
        ]),
    ]);

    expect(MarkdownWriter::write([$node]))->toBe("[see](#comment-1)\n\n");
});

it('keeps the leading tab on a nested list item so the parser sees the nesting', function (): void {
    $list = el('ul', children: [
        el('li', children: [
            txt('A'),
            el('ul', children: [el('li', children: [txt('a1')])]),
        ]),
        el('li', children: [txt('B')]),
    ]);

    // Same expectation as the existing nested-list test: the "\t- a1" line
    // must keep its leading tab so it's recognised as a nested list item.
    expect(MarkdownWriter::write([$list]))->toBe("- A\n\t- a1\n- B\n\n");
});

it('renders <strong> as ** wrappers (asterisks, to avoid clash with literal underscores)', function (): void {
    expect(MarkdownWriter::write([el('p', children: [
        el('strong', children: [txt('bold')]),
    ])]))->toBe("**bold**\n\n");
});

it('renders <em> as * wrappers', function (): void {
    expect(MarkdownWriter::write([el('p', children: [
        el('em', children: [txt('italic')]),
    ])]))->toBe("*italic*\n\n");
});

it('renders heading levels with the right number of #', function (): void {
    expect(MarkdownWriter::write([el('h1', children: [txt('one')])]))->toBe("# one\n\n");
    expect(MarkdownWriter::write([el('h3', children: [txt('three')])]))->toBe("### three\n\n");
    expect(MarkdownWriter::write([el('h6', children: [txt('six')])]))->toBe("###### six\n\n");
});

it('renders <a> with href as a markdown link', function (): void {
    expect(MarkdownWriter::write([
        el('a', attributes: ['href' => 'https://example.com/'], children: [txt('click')]),
    ]))->toBe('[click](https://example.com/)');
});

it('renders <a> without href as plain children', function (): void {
    expect(MarkdownWriter::write([
        el('a', children: [txt('plain')]),
    ]))->toBe('plain');
});

it('renders <img> with src and alt as ![alt](src)', function (): void {
    expect(MarkdownWriter::write([
        el('img', attributes: ['alt' => 'logo', 'src' => 'data:img']),
    ]))->toBe('![logo](data:img)');
});

it('renders <img> with no src and no alt as the empty string', function (): void {
    expect(MarkdownWriter::write([el('img')]))->toBe('');
});

it('renders <br> as a two-space hard line break inside a paragraph', function (): void {
    // <br> always appears inside flow content; the two-space marker only
    // matters when preceded by text, so we test the realistic shape.
    $node = el('p', children: [txt('a'), el('br'), txt('b')]);

    expect(MarkdownWriter::write([$node]))->toBe("a  \nb\n\n");
});

it('escapes only chars that would actually be parsed as markdown in this position', function (): void {
    // Mid-text: `[d](e)` is the inline-link pattern, so its brackets are
    // escaped; everything else is left literal because CommonMark would
    // not parse it as syntax here (intraword underscore rule, asterisks
    // not flanking, parens only matter after `]`, `#` only at line start).
    expect(MarkdownWriter::write([txt('a*b_c[d](e)#')]))
        ->toBe('a*b_c\\[d\\](e)#');
});

it('leaves citation-style brackets as literal text', function (): void {
    // `[1]`, `[Nota]`, `[sic]` would only become links if a matching
    // `[ref]: url` definition existed somewhere -- and we never emit
    // those. CommonMark renders them as literal text; no escape needed.
    $node = el('p', children: [txt('vedi [1] e [Nota] e anche [sic]')]);

    expect(MarkdownWriter::write([$node]))
        ->toBe("vedi [1] e [Nota] e anche [sic]\n\n");
});

it('escapes brackets only when they form the inline-link pattern', function (): void {
    // Mixed: `[1]` is a citation (literal), `[click](url)` is a real
    // link pattern that must be escaped to render as text.
    $node = el('p', children: [txt('see [1] and [click](url)')]);

    expect(MarkdownWriter::write([$node]))
        ->toBe("see [1] and \\[click\\](url)\n\n");
});

it('leaves long underscore runs alone (the typical Word fill-in field)', function (): void {
    // PHPWord+CommonMark output style: the underscore run is between
    // whitespace and punctuation, so CommonMark never opens emphasis on
    // it. No escape needed -- the user's main motivation for switching.
    $node = el('p', children: [txt('La società ___________________, con sede in __________')]);

    expect(MarkdownWriter::write([$node]))
        ->toBe("La società ___________________, con sede in __________\n\n");
});

it('does not escape periods, parens or dashes mid-text', function (): void {
    $node = el('p', children: [txt('see (articoli 2222 c.c. e seguenti) - art. 1')]);

    expect(MarkdownWriter::write([$node]))
        ->toBe("see (articoli 2222 c.c. e seguenti) - art. 1\n\n");
});

it('escapes # only at line start (heading marker)', function (): void {
    // Mid-text `#` is literal; at line start it would start a heading
    // and must be escaped to render as `#` text.
    $start = el('p', children: [txt('# not a heading')]);
    $mid = el('p', children: [txt('issue # 42')]);

    expect(MarkdownWriter::write([$start]))->toBe("\\# not a heading\n\n");
    expect(MarkdownWriter::write([$mid]))->toBe("issue # 42\n\n");
});

it('escapes - at line start when it would start a list item', function (): void {
    $list = el('p', children: [txt('- not a list')]);
    $mid = el('p', children: [txt('range 1-10')]);

    expect(MarkdownWriter::write([$list]))->toBe("\\- not a list\n\n");
    expect(MarkdownWriter::write([$mid]))->toBe("range 1-10\n\n");
});

it('escapes . after a digit run at line start (ordered-list marker)', function (): void {
    $list = el('p', children: [txt('1. not a list')]);
    $mid = el('p', children: [txt('Art. 2 - Natura')]);

    expect(MarkdownWriter::write([$list]))->toBe("1\\. not a list\n\n");
    expect(MarkdownWriter::write([$mid]))->toBe("Art. 2 - Natura\n\n");
});

it('escapes ! only when followed by [ (image syntax)', function (): void {
    $img = el('p', children: [txt('not an ![image](src) here')]);
    $excl = el('p', children: [txt('hello!')]);

    // Both `!` (only before `[`) and `[` `]` (always) are escaped here.
    expect(MarkdownWriter::write([$img]))->toBe("not an \\!\\[image\\](src) here\n\n");
    expect(MarkdownWriter::write([$excl]))->toBe("hello!\n\n");
});

it('escapes a literal backslash by doubling it', function (): void {
    expect(MarkdownWriter::write([txt('a\\b')]))->toBe('a\\\\b');
});

it('renders an unordered list with one bullet per item', function (): void {
    $list = el('ul', children: [
        el('li', children: [txt('Apple')]),
        el('li', children: [txt('Banana')]),
    ]);

    expect(MarkdownWriter::write([$list]))->toBe("- Apple\n- Banana\n\n");
});

it('renders an ordered list with incrementing numbers', function (): void {
    $list = el('ol', children: [
        el('li', children: [txt('one')]),
        el('li', children: [txt('two')]),
    ]);

    expect(MarkdownWriter::write([$list]))->toBe("1. one\n2. two\n\n");
});

it('honours <ol start="N"> when rendering the first item number', function (): void {
    // Word's exporter sometimes splits a continuous "1., 2., 3." into
    // separate single-item ordered lists each with `<w:start>`. The
    // converter forwards that on the <ol> as `start`, and we render
    // the first marker as that number rather than always 1.
    $list = el('ol', attributes: ['start' => '2'], children: [
        el('li', children: [txt('Il compenso')]),
    ]);

    expect(MarkdownWriter::write([$list]))->toBe("2. Il compenso\n\n");
});

it('continues numbering from the start attribute across items', function (): void {
    $list = el('ol', attributes: ['start' => '5'], children: [
        el('li', children: [txt('e')]),
        el('li', children: [txt('f')]),
    ]);

    expect(MarkdownWriter::write([$list]))->toBe("5. e\n6. f\n\n");
});

it('indents nested lists with tabs and reuses the parent newline', function (): void {
    $list = el('ul', children: [
        el('li', children: [
            txt('A'),
            el('ul', children: [el('li', children: [txt('a1')])]),
        ]),
        el('li', children: [txt('B')]),
    ]);

    expect(MarkdownWriter::write([$list]))->toBe("- A\n\t- a1\n- B\n\n");
});

it('emits no markup for unknown tags but still walks children', function (): void {
    expect(MarkdownWriter::write([
        el('section', children: [el('p', children: [txt('hi')])]),
    ]))->toBe("hi\n\n");
});

/**
 * @param  list<list<string>>  $rows
 */
function mdTable(array $rows, string $cellTag = 'td'): Element
{
    return el('table', children: array_map(
        static fn (array $cells): Element => el('tr', children: array_map(
            static fn (string $cell): Element => el($cellTag, children: $cell === '' ? [] : [el('p', children: [txt($cell)])]),
            $cells,
        )),
        $rows,
    ));
}

it('renders a table as a GFM pipe table using the first row as header', function (): void {
    expect(MarkdownWriter::write([mdTable([['A', 'B'], ['C', 'D']])]))->toBe(
        "| A | B |\n"
        ."| --- | --- |\n"
        ."| C | D |\n\n",
    );
});

it('keeps empty cells so columns stay aligned', function (): void {
    expect(MarkdownWriter::write([mdTable([['', 'Col 1', 'Col 2'], ['A', 'Yes', 'No']])]))->toBe(
        "|  | Col 1 | Col 2 |\n"
        ."| --- | --- | --- |\n"
        ."| A | Yes | No |\n\n",
    );
});

it('uses <thead> rows of <th> cells as the table header', function (): void {
    $table = el('table', children: [
        el('thead', children: [el('tr', children: [
            el('th', children: [el('p', children: [txt('h1')])]),
            el('th', children: [el('p', children: [txt('h2')])]),
        ])]),
        el('tbody', children: [el('tr', children: [
            el('td', children: [el('p', children: [txt('a')])]),
            el('td', children: [el('p', children: [txt('b')])]),
        ])]),
    ]);

    expect(MarkdownWriter::write([$table]))->toBe(
        "| h1 | h2 |\n"
        ."| --- | --- |\n"
        ."| a | b |\n\n",
    );
});

it('pads short rows to the widest row', function (): void {
    expect(MarkdownWriter::write([mdTable([['A'], ['B', 'C', 'D']])]))->toBe(
        "| A |  |  |\n"
        ."| --- | --- | --- |\n"
        ."| B | C | D |\n\n",
    );
});

it('expands colspan into trailing empty cells', function (): void {
    $table = el('table', children: [
        el('tr', children: [
            el('td', ['colspan' => '2'], [el('p', children: [txt('wide')])]),
            el('td', children: [el('p', children: [txt('x')])]),
        ]),
        el('tr', children: [
            el('td', children: [el('p', children: [txt('a')])]),
            el('td', children: [el('p', children: [txt('b')])]),
            el('td', children: [el('p', children: [txt('c')])]),
        ]),
    ]);

    expect(MarkdownWriter::write([$table]))->toBe(
        "| wide |  | x |\n"
        ."| --- | --- | --- |\n"
        ."| a | b | c |\n\n",
    );
});

it('leaves an empty cell where a rowspan covers the column', function (): void {
    $table = el('table', children: [
        el('tr', children: [
            el('td', children: [el('p', children: [txt('h1')])]),
            el('td', children: [el('p', children: [txt('h2')])]),
        ]),
        el('tr', children: [
            el('td', ['rowspan' => '2'], [el('p', children: [txt('tall')])]),
            el('td', children: [el('p', children: [txt('a')])]),
        ]),
        el('tr', children: [
            el('td', children: [el('p', children: [txt('b')])]),
        ]),
    ]);

    expect(MarkdownWriter::write([$table]))->toBe(
        "| h1 | h2 |\n"
        ."| --- | --- |\n"
        ."| tall | a |\n"
        ."|  | b |\n\n",
    );
});

it('keeps cell content on one line, joining blocks with <br>', function (): void {
    $table = el('table', children: [el('tr', children: [
        el('td', children: [
            el('p', children: [txt('first')]),
            el('p', children: [el('strong', children: [txt('second')])]),
        ]),
    ])]);

    expect(MarkdownWriter::write([$table]))->toBe(
        "| first<br>**second** |\n"
        ."| --- |\n\n",
    );
});

it('escapes pipes inside cells', function (): void {
    expect(MarkdownWriter::write([mdTable([['a | b']])]))->toBe(
        "| a \\| b |\n"
        ."| --- |\n\n",
    );
});

it('separates a table from surrounding paragraphs with blank lines', function (): void {
    expect(MarkdownWriter::write([
        el('p', children: [txt('Before')]),
        mdTable([['A']]),
        el('p', children: [txt('After')]),
    ]))->toBe("Before\n\n| A |\n| --- |\n\nAfter\n\n");
});

it('renders nothing for a table without rows', function (): void {
    expect(MarkdownWriter::write([el('table')]))->toBe('');
});
