<?php

declare(strict_types=1);

// Ported from mammoth.js: lib/writers/markdown-writer.js
//
// Faithful port: each tag in HANDLERS returns a {start, end, list?,
// anchorPosition?} descriptor exactly as mammoth does, so the nested-list
// "share the trailing \n with the inner <li>" trick (the hasClosed flag) is
// preserved. We walk the HTML AST directly rather than going through a
// separate writer protocol -- the equivalent is inlined in writeNode.

namespace EndlessCreativity\ElephantPhp\Html;

use Closure;

final class MarkdownWriter
{
    /**
     * Tags whose markdown delimiters require a non-whitespace character
     * adjacent to the opening / closing marker to actually format the
     * content (CommonMark § 6.2). For these tags we hoist any leading or
     * trailing whitespace text out of the wrapper before writing, so
     * `__   bold   __` (broken markdown) becomes `   __bold__   ` (working).
     *
     * @var array<string, true>
     */
    private const EMPHASIS_TAGS = [
        'strong' => true,
        'em' => true,
        's' => true,
        'sub' => true,
        'sup' => true,
    ];

    /**
     * @param  list<Node>  $nodes
     */
    public static function write(array $nodes): string
    {
        $writer = new self();
        $writer->writeNodes(self::hoistEmphasisWhitespace($nodes));

        return self::stripLineLeadingSpaces($writer->output);
    }

    /**
     * Strips runs of leading spaces at the start of every line. CommonMark
     * § 4.4 turns any line that begins with four or more spaces into an
     * indented code block, which destroys formatting on indented paragraphs
     * (and on emphasis wrappers whose leading whitespace we hoist out).
     * Markdown has no paragraph-indent primitive, so the spaces are dropped
     * entirely rather than preserved as `&nbsp;` -- the user asked for pure
     * text. Tabs at line start are left alone: we use them ourselves for
     * nested list items, where the parser recognises the list before the
     * indented-code rule kicks in.
     */
    private static function stripLineLeadingSpaces(string $markdown): string
    {
        // Strip runs of leading spaces *or* tabs at the start of every line.
        // A tab at line start is treated as four spaces by CommonMark's
        // indented-code rule, so a paragraph that started with <w:tab/> in
        // Word would otherwise become a code block.
        //
        // The negative lookahead `(?!- |\d+\. )` protects our own nested
        // list items: convertList emits "\t- foo" / "\t1. foo" for nested
        // entries, and those tabs must remain so the markdown parser keeps
        // recognising them as nested list items.
        //
        // <br>'s "  \n" marker is unaffected because <br> always appears
        // after non-whitespace content inside a paragraph in normal use,
        // so the two spaces never sit at line start.
        return preg_replace('/(^|\n)[ \t]+(?!- |\d+\. )/', '$1', $markdown) ?? $markdown;
    }

    /**
     * @param  list<Node>  $nodes
     * @return list<Node>
     */
    private static function hoistEmphasisWhitespace(array $nodes): array
    {
        $result = [];
        foreach ($nodes as $node) {
            foreach (self::hoistOne($node) as $hoisted) {
                $result[] = $hoisted;
            }
        }

        return $result;
    }

    /**
     * @return list<Node>
     */
    private static function hoistOne(Node $node): array
    {
        if (! $node instanceof Element) {
            return [$node];
        }

        // Recurse first so nested emphasis is normalised bottom-up.
        $children = self::hoistEmphasisWhitespace($node->children);

        if (! isset(self::EMPHASIS_TAGS[$node->tag->tagName])) {
            return [$node->withChildren($children)];
        }

        $leading = '';
        while ($children !== []) {
            $first = $children[0];
            // <br> is void and expands to "  \n" (pure whitespace) in the
            // markdown writer, so a <br> at the leading edge of an emphasis
            // wrapper would push the content away from the opening marker
            // and CommonMark would refuse to open emphasis. Hoist it as if
            // it were a Text("  \n") node.
            if ($first instanceof Element && $first->tag->tagName === 'br') {
                $leading .= "  \n";
                array_shift($children);

                continue;
            }
            if (! $first instanceof Text) {
                break;
            }
            $value = $first->value;
            if (preg_match('/^(\s+)(.*)$/s', $value, $m) !== 1) {
                break;
            }
            $leading .= $m[1];
            if ($m[2] === '') {
                array_shift($children);

                continue;
            }
            $children[0] = new Text(value: $m[2]);
            break;
        }

        $trailing = '';
        while ($children !== []) {
            $lastIndex = count($children) - 1;
            $last = $children[$lastIndex];
            if ($last instanceof Element && $last->tag->tagName === 'br') {
                $trailing = "  \n".$trailing;
                array_pop($children);

                continue;
            }
            if (! $last instanceof Text) {
                break;
            }
            $value = $last->value;
            if (preg_match('/^(.*?)(\s+)$/s', $value, $m) !== 1) {
                break;
            }
            $trailing = $m[2].$trailing;
            if ($m[1] === '') {
                array_pop($children);

                continue;
            }
            $children[$lastIndex] = new Text(value: $m[1]);
            break;
        }

        $result = [];
        if ($leading !== '') {
            $result[] = new Text(value: $leading);
        }
        if ($children !== []) {
            $result[] = $node->withChildren($children);
        }
        if ($trailing !== '') {
            $result[] = new Text(value: $trailing);
        }

        return $result;
    }

    private string $output = '';

    /**
     * Set on the writers that render a single table cell. A table nested
     * inside a cell cannot be expressed as a GFM table, so it falls back
     * to the generic walk and its content is flattened into the cell.
     */
    private bool $inTableCell = false;

    /** @var list<array{end: string|Closure(): ?string, list: ?array{isOrdered: bool, indent: int, count: int}}> */
    private array $stack = [];

    /** @var ?array{isOrdered: bool, indent: int, count: int} */
    private ?array $list = null;

    /** Shared across list items so the outer <li> doesn't re-emit the inner <li>'s trailing newline. */
    /** @var array{hasClosed: bool} */
    private array $listItem = ['hasClosed' => false];

    /**
     * @param  list<Node>  $nodes
     */
    private function writeNodes(array $nodes): void
    {
        foreach ($nodes as $node) {
            if ($node instanceof Text) {
                $this->output .= $this->smartEscape($node->value);

                continue;
            }
            if ($node instanceof Element) {
                if ($node->tag->tagName === 'table' && ! $this->inTableCell) {
                    $this->writeTable($node);

                    continue;
                }
                if ($node->isVoid()) {
                    $this->openElement($node);
                    $this->closeElement();
                } else {
                    $this->openElement($node);
                    $this->writeNodes($node->children);
                    $this->closeElement();
                }
            }
        }
    }

    private function openElement(Element $element): void
    {
        $descriptor = $this->buildDescriptor($element->tag->tagName, $element->tag->attributes);
        $this->stack[] = ['end' => $descriptor['end'], 'list' => $this->list];

        if (isset($descriptor['list'])) {
            $this->list = $descriptor['list'];
        }

        // Mammoth emits a literal `<a id="...">` here for any element
        // carrying an id (so bookmarks survive in markdown). We don't:
        // markdown should be markdown, not HTML inline. BookmarkStart
        // therefore renders to nothing in markdown output and the id is
        // only preserved by the HTML writer. The `anchorPosition` key on
        // a descriptor is now inert -- kept for parity with mammoth's
        // descriptor shape.
        $this->output .= $descriptor['start'] ?? '';
    }

    private function closeElement(): void
    {
        $frame = array_pop($this->stack);
        if ($frame === null) {
            return;
        }
        $this->list = $frame['list'];
        $end = $frame['end'];
        $rendered = $end instanceof Closure ? $end() : $end;
        $this->output .= $rendered ?? '';
    }

    /**
     * @param  array<string, string>  $attributes
     * @return array{start?: string, end: string|Closure(): ?string, list?: array{isOrdered: bool, indent: int, count: int}, anchorPosition?: string}
     */
    private function buildDescriptor(string $tagName, array $attributes): array
    {
        // Heading levels h1..h6 share one rule.
        if (preg_match('/^h([1-6])$/', $tagName, $matches) === 1) {
            return ['start' => str_repeat('#', (int) $matches[1]).' ', 'end' => "\n\n"];
        }

        return match ($tagName) {
            'p' => ['start' => '', 'end' => "\n\n"],
            'br' => ['start' => '', 'end' => "  \n"],
            'strong' => ['start' => '**', 'end' => '**'],
            'em' => ['start' => '*', 'end' => '*'],
            'a' => self::linkDescriptor($attributes),
            'img' => self::imageDescriptor($attributes),
            'input' => self::inputDescriptor($attributes),
            'ul' => $this->listDescriptor(isOrdered: false),
            'ol' => $this->listDescriptor(
                isOrdered: true,
                start: isset($attributes['start']) ? (int) $attributes['start'] : 1,
            ),
            'li' => $this->listItemDescriptor(),
            default => ['end' => ''],
        };
    }

    /**
     * @param  array<string, string>  $attributes
     * @return array{start?: string, end: string, anchorPosition?: string}
     */
    private static function linkDescriptor(array $attributes): array
    {
        $href = $attributes['href'] ?? '';
        if ($href === '') {
            return ['end' => ''];
        }

        return ['start' => '[', 'end' => ']('.$href.')'];
    }

    /**
     * @param  array<string, string>  $attributes
     * @return array{start?: string, end: string}
     */
    private static function imageDescriptor(array $attributes): array
    {
        $src = $attributes['src'] ?? '';
        $alt = $attributes['alt'] ?? '';
        if ($src === '' && $alt === '') {
            return ['end' => ''];
        }

        return ['start' => '!['.$alt.']('.$src.')', 'end' => ''];
    }

    /**
     * Renders `<input type="checkbox">` as the GFM task-list marker
     * (`[x]` or `[ ]`). Inside a `<li>` this composes naturally into
     * the standard `- [x] text` shape; elsewhere it stays as inline
     * literal text. Other `<input>` types (radio, text, ...) are not
     * generated by the docx pipeline, so we skip them silently.
     *
     * @param  array<string, string>  $attributes
     * @return array{start?: string, end: string}
     */
    private static function inputDescriptor(array $attributes): array
    {
        if (($attributes['type'] ?? '') !== 'checkbox') {
            return ['end' => ''];
        }
        $checked = isset($attributes['checked']);

        return ['start' => $checked ? '[x] ' : '[ ] ', 'end' => ''];
    }

    /**
     * @return array{start: string, end: string, list: array{isOrdered: bool, indent: int, count: int}}
     */
    private function listDescriptor(bool $isOrdered, int $start = 1): array
    {
        $nested = $this->list !== null;

        return [
            'start' => $nested ? "\n" : '',
            'end' => $nested ? '' : "\n",
            'list' => [
                'isOrdered' => $isOrdered,
                'indent' => $nested ? $this->list['indent'] + 1 : 0,
                // First listItemDescriptor() does count++, producing $start.
                'count' => $start - 1,
            ],
        ];
    }

    /**
     * @return array{start: string, end: Closure(): ?string}
     */
    private function listItemDescriptor(): array
    {
        $list = $this->list ?? ['indent' => 0, 'isOrdered' => false, 'count' => 0];
        $list['count']++;
        $this->list = $list;
        $this->listItem['hasClosed'] = false;

        $bullet = $list['isOrdered'] ? $list['count'].'.' : '-';
        $start = str_repeat("\t", $list['indent']).$bullet.' ';

        $listItem = &$this->listItem;

        return [
            'start' => $start,
            'end' => static function () use (&$listItem): ?string {
                if (! $listItem['hasClosed']) {
                    $listItem['hasClosed'] = true;

                    return "\n";
                }

                return null;
            },
        ];
    }

    /**
     * Renders a table as a GFM pipe table. Mammoth's markdown writer has no
     * table support and flattens every cell into its own paragraph, which
     * loses the row/column structure entirely.
     *
     * GFM requires exactly one header row, so the first row becomes the
     * header whether or not it came from <thead>. Rows are padded to the
     * widest row, colspan is expanded into empty cells after the spanning
     * one, and columns covered by a rowspan from a previous row get an
     * empty cell, so every value stays under its own column.
     */
    private function writeTable(Element $table): void
    {
        $grid = [];
        $pendingRowSpans = [];
        foreach (self::tableRows($table) as $row) {
            $cells = [];
            $column = 0;
            foreach ($row->children as $cell) {
                if (! $cell instanceof Element || ($cell->tag->tagName !== 'td' && $cell->tag->tagName !== 'th')) {
                    continue;
                }
                while (($pendingRowSpans[$column] ?? 0) > 0) {
                    $pendingRowSpans[$column]--;
                    $cells[$column++] = '';
                }
                $colSpan = max(1, (int) ($cell->tag->attributes['colspan'] ?? 1));
                $rowSpan = max(1, (int) ($cell->tag->attributes['rowspan'] ?? 1));
                for ($offset = 0; $offset < $colSpan; $offset++) {
                    $cells[$column] = $offset === 0 ? $this->renderTableCell($cell) : '';
                    if ($rowSpan > 1) {
                        $pendingRowSpans[$column] = $rowSpan - 1;
                    }
                    $column++;
                }
            }
            foreach ($pendingRowSpans as $spannedColumn => $remaining) {
                if ($spannedColumn >= $column && $remaining > 0) {
                    $pendingRowSpans[$spannedColumn]--;
                    $cells[$spannedColumn] = '';
                }
            }
            ksort($cells);
            $grid[] = $cells;
        }

        if ($grid === []) {
            return;
        }

        $width = max(1, ...array_map(
            static fn (array $cells): int => $cells === [] ? 0 : max(array_keys($cells)) + 1,
            $grid,
        ));

        if ($this->output !== '' && ! str_ends_with($this->output, "\n\n")) {
            $this->output .= str_ends_with($this->output, "\n") ? "\n" : "\n\n";
        }

        foreach ($grid as $index => $cells) {
            $line = '|';
            for ($column = 0; $column < $width; $column++) {
                $line .= ' '.($cells[$column] ?? '').' |';
            }
            $this->output .= $line."\n";
            if ($index === 0) {
                $this->output .= '|'.str_repeat(' --- |', $width)."\n";
            }
        }
        $this->output .= "\n";
    }

    /**
     * Rows of a table in document order, looking through <thead>/<tbody>.
     *
     * @return list<Element>
     */
    private static function tableRows(Element $table): array
    {
        $rows = [];
        foreach ($table->children as $child) {
            if (! $child instanceof Element) {
                continue;
            }
            if ($child->tag->tagName === 'tr') {
                $rows[] = $child;
            } elseif ($child->tag->tagName === 'thead' || $child->tag->tagName === 'tbody') {
                foreach (self::tableRows($child) as $row) {
                    $rows[] = $row;
                }
            }
        }

        return $rows;
    }

    /**
     * A GFM cell must fit on one line: the cell is rendered on its own,
     * then line breaks between blocks become <br> (the only way GFM keeps
     * a line break inside a cell) and pipes are escaped so they don't
     * split the cell.
     */
    private function renderTableCell(Element $cell): string
    {
        $writer = new self();
        $writer->inTableCell = true;
        $writer->writeNodes($cell->children);

        $lines = preg_split('/\n+/', self::stripLineLeadingSpaces($writer->output)) ?: [];
        $lines = array_values(array_filter(
            array_map(static fn (string $line): string => rtrim($line), $lines),
            static fn (string $line): bool => $line !== '',
        ));

        return str_replace('|', '\\|', implode('<br>', $lines));
    }

    /**
     * Context-aware Markdown escape. Mammoth's blanket escape (every
     * occurrence of `` ` * _ { } [ ] ( ) # + - . ! ``) creates a lot of
     * visual noise on docx-derived text -- e.g. fill-in fields like
     * `_______________` and inline punctuation like `c.c.` get escaped
     * even though CommonMark would parse them as literal text. Here we
     * escape only the characters that would actually be parsed as syntax
     * in their position:
     *
     * - `` \ ``, `` ` ``: always (rare in docx text, and an unescaped
     *   backtick can open a code span across chunks).
     * - `[`, `]`: only when forming the inline link pattern `[...](` --
     *   citation-style brackets `[1]`, `[Nota]`, `[sic]` are left as
     *   text because we never emit link reference definitions, so
     *   CommonMark renders them literally.
     * - `#`, `+`: only at line start (heading / list marker)
     * - `-`: only at line start when followed by space (list marker)
     * - `.`: only after a digit run that begins at line start (ordered
     *   list marker pattern `\d+. `)
     * - `!`: only when followed by `[` *and* that `[` is itself a link
     *   opener (image syntax `![alt](src)`).
     * - `*`, `_`, `(`, `)`, `{`, `}`, `<`, `>`: never. CommonMark's
     *   flanking-delimiter and intraword rules mean docx-derived runs
     *   like `_______` between space and punctuation don't open
     *   emphasis, and parens don't open links without a preceding `]`.
     *
     * Because the decision depends on `$this->output` (line position),
     * this is an instance method, not static.
     */
    private function smartEscape(string $text): string
    {
        if ($text === '') {
            return '';
        }

        $atLineStart = $this->output === '' || str_ends_with($this->output, "\n");
        // Tracks the chars emitted on the current line so far, scoped to
        // this chunk -- reset only on internal `\n`. Used solely for the
        // `\d+. ` ordered-list-marker detection.
        $lineSoFar = $atLineStart ? '' : null;

        // Pre-pass: positions of `[` and `]` that participate in an
        // inline-link pattern `[...](`. Outside of these positions,
        // brackets are left as literal text. The pattern disallows
        // nested brackets, matching the CommonMark inline-link rule.
        $linkBrackets = [];
        if (preg_match_all('/\[[^\[\]]*\]\(/', $text, $matches, PREG_OFFSET_CAPTURE) > 0) {
            foreach ($matches[0] as $match) {
                $openPos = $match[1];
                $closePos = $openPos + strlen($match[0]) - 2;
                $linkBrackets[$openPos] = true;
                $linkBrackets[$closePos] = true;
            }
        }

        $result = '';
        $len = strlen($text);
        for ($i = 0; $i < $len; $i++) {
            $char = $text[$i];
            $next = $i + 1 < $len ? $text[$i + 1] : '';

            $escape = match ($char) {
                '\\', '`' => true,
                '[', ']' => isset($linkBrackets[$i]),
                '#', '+' => $atLineStart,
                '-' => $atLineStart && $next === ' ',
                '.' => $lineSoFar !== null
                    && $lineSoFar !== ''
                    && preg_match('/^\d+$/', $lineSoFar) === 1
                    && $next === ' ',
                '!' => $next === '[' && isset($linkBrackets[$i + 1]),
                default => false,
            };

            $emitted = $escape ? '\\'.$char : $char;
            $result .= $emitted;

            if ($char === "\n") {
                $atLineStart = true;
                $lineSoFar = '';
            } else {
                $atLineStart = false;
                if ($lineSoFar !== null) {
                    $lineSoFar .= $emitted;
                }
            }
        }

        return $result;
    }
}
