<p align="center">
  <img src="assets/elephant-php-logo-alpha.png" alt="elephant-php" width="480">
</p>

# elephant-php

[![CI](https://github.com/endless-creativity/elephant-php/actions/workflows/ci.yml/badge.svg)](https://github.com/endless-creativity/elephant-php/actions/workflows/ci.yml)
[![Latest Stable Version](https://img.shields.io/packagist/v/endless-creativity/elephant-php?label=packagist)](https://packagist.org/packages/endless-creativity/elephant-php)
[![License](https://img.shields.io/badge/license-BSD--2--Clause-blue.svg)](LICENSE)
[![PHP Version](https://img.shields.io/packagist/php-v/endless-creativity/elephant-php)](composer.json)

**Convert `.docx` files to Markdown or semantic HTML, in pure PHP.**
No external binaries (LibreOffice, Pandoc), no heavyweight library
(PHPWord), no shell-out — just the standard PHP extensions.
elephant-php reads the *structure* of a document — headings, lists,
tables, footnotes, images — instead of trying to reproduce Word's
visual formatting.

It is a PHP port of
[mammoth.js](https://github.com/mwilliamson/mammoth.js) and adopts its
core philosophy and style-mapping DSL.

## Features

- **Pure PHP**, no `LibreOffice` / `Pandoc` / shell-out — only the standard
  `dom`, `libxml`, `mbstring`, `xmlreader`, `zip` extensions.
- HTML and Markdown output from the same intermediate model.
- Headings (h1–h6), paragraphs, runs with bold / italic / strike /
  superscript / subscript, hyperlinks, nested lists (mixed `ul`/`ol`),
  tables (`colspan` + `rowspan` from `gridSpan` and `vMerge`), images
  (embedded as data URIs by default, customisable handler), footnotes
  and endnotes (with backlinks), comments, content controls (`w:sdt`),
  symbol fonts (Wingdings / Webdings / Symbol via dingbat-to-unicode),
  legacy hyperlink complex fields (`w:fldChar`), checkbox form fields
  (SDT `wordml:checkbox` and `FORMCHECKBOX`) rendered as
  `<input type="checkbox">` in HTML and `[x]` / `[ ]` in Markdown.
- Tables in Markdown as GFM pipe tables (first row as header, `colspan` /
  `rowspan` padded with empty cells, multi-block cells joined with
  `<br>`); mammoth.js flattens them into plain paragraphs.
- Mammoth-compatible style-mapping DSL:
  `p[style-name='Heading 1'] => h1:fresh`, `b => strong`, `r.Code => code`,
  `comment-reference => sup`, `highlight[color='yellow'] => mark`,
  `br[type='page'] => hr`, `table.Grid => table.fancy`, `=> !` for ignore.
  Supports `:fresh`, `:separator('text')` and backslash escape sequences
  (`\n`, `\r`, `\t`, `\\`, `\'`) inside string literals.
- `Converter` options: `idPrefix` (namespace HTML ids),
  `ignoreEmptyParagraphs`, `prettyPrint` (indented HTML),
  `ignoreHiddenText` (drop `w:vanish` "Hidden" runs, on by default),
  `transformDocument` (callback to rewrite the parsed tree before
  rendering), plus `Transforms` helpers
  (`paragraph`, `run`, `elementsOfType`, `elements`, `getDescendants`,
  `getDescendantsOfType`) for walking and rebuilding the document tree.
- `extractRawText` for plain-text indexing pipelines.
- `embedStyleMap` / `readEmbeddedStyleMap` for mammoth-compatible
  in-document style maps.
- CLI `bin/elephant-php` for `.docx → HTML/Markdown` from the terminal.
- **Safe by default** for untrusted input — see
  [Security defaults](#security-defaults).

See [`ROADMAP.md`](ROADMAP.md) for the residual gap with mammoth.js
(track-changes deletion concatenation, DSL list matchers, OMML
equations).

## Installation

```bash
composer require endless-creativity/elephant-php
```

Requires PHP 8.2+.

## Usage

### HTML

```php
use EndlessCreativity\ElephantPhp\Converter;

$result = (new Converter())->convertToHtml('/path/to/file.docx');

echo $result->value;             // semantic HTML
foreach ($result->messages as $message) {
    fwrite(STDERR, "[{$message->type->value}] {$message->message}\n");
}
```

### Markdown

```php
$result = (new Converter())->convertToMarkdown('/path/to/file.docx');

file_put_contents('article.md', $result->value);
```

### Plain text

```php
$result = (new Converter())->extractRawText('/path/to/file.docx');

// Paragraphs are separated by "\n\n", everything else just contributes
// its descendant text. Useful for indexing/search pipelines.
echo $result->value;
```

### Custom style map

Mammoth's DSL is supported as a list of rule strings. Rules are tried
in order; the first match wins. The default heading map (Heading 1..6
→ h1..h6) is appended after your rules.

```php
$converter = new Converter(styleMap: [
    "p[style-name='Aside'] => aside.callout",
    "p[style-name='Quote'] => blockquote > p:fresh",
    "r[style-name='Code'] => code",
    "comment-reference => sup",                    // opt in to comments
    "highlight[color='yellow'] => mark.yellow",
    "br[type='page'] => hr",
    "p[style-name='List Paragraph'] =>",           // silence common warning
]);

$html = $converter->convertToHtml('/path/to/file.docx')->value;
```

### Custom image handler

By default, images are embedded as `data:` URIs. Plug in your own
`ImageHandler` to write to disk / S3 / a CDN / whatever, returning the
final `<img>` attributes.

```php
use EndlessCreativity\ElephantPhp\Document\Image;
use EndlessCreativity\ElephantPhp\Image\ImageHandler;

$handler = new class implements ImageHandler {
    public function attributes(Image $image): array {
        $bytes = ($image->readBytes)();
        $hash = hash('sha256', $bytes);
        $ext = ['image/png' => 'png', 'image/jpeg' => 'jpg'][$image->contentType] ?? 'bin';
        $path = "uploads/{$hash}.{$ext}";
        file_put_contents(__DIR__ . "/public/{$path}", $bytes);
        return ['src' => "/{$path}"];
    }
};

$converter = new Converter(imageHandler: $handler);
```

### Transform the document before rendering

`transformDocument` receives the parsed `Document` and returns a
(possibly modified) `Document`. Combine with the `Transforms` helpers
to walk and rebuild the tree without writing recursion by hand:

```php
use EndlessCreativity\ElephantPhp\Document\Run;
use EndlessCreativity\ElephantPhp\Document\Transforms;

// Force every run to bold before rendering.
$converter = new Converter(
    transformDocument: Transforms::run(
        static fn (Run $r): Run => new Run(children: $r->children, isBold: true),
    ),
);
```

### Embedded style map (read / write)

Mammoth supports embedding the style map as a part of the docx itself
under `mammoth/style-map`. Read / write round-trips:

```php
// Write
$bytes = Converter::embedStyleMap('/in.docx', "p[style-name='Aside'] => p.aside");
file_put_contents('/out.docx', $bytes);

// Read
$rules = Converter::readEmbeddedStyleMap('/out.docx');
```

### CLI

```bash
vendor/bin/elephant-php /path/to/file.docx                  # → HTML to stdout
vendor/bin/elephant-php --markdown /path/to/file.docx       # → Markdown to stdout
vendor/bin/elephant-php /path/to/file.docx out.html         # → HTML to file
```

Conversion warnings are written to `stderr` regardless of the output
destination.

## Security defaults

The library is designed to be safe to point at untrusted `.docx`
input out of the box. Three behaviours diverge from mammoth.js for
this reason:

- **`r:link` images are not fetched.** Mammoth resolves
  `<a:blip r:link="...">` via `fs.readFile` on the relationship
  target, which is attacker-controlled in any user-uploaded
  scenario and exposes SSRF, local file inclusion, and `phar://`
  deserialisation. elephant-php refuses to load `r:link` bytes; the
  path is preserved on the `Image` node so a `transformDocument`
  hook can decide what to do.
- **Hyperlinks with `javascript:` / `vbscript:` / `data:` schemes
  lose their `<a>` wrapper.** The match is case-insensitive and
  tolerates leading whitespace and control characters (browsers do
  too). The link text still renders inline; only the executable
  wrapper is dropped.
- **XML with a `DOCTYPE` declaration is rejected**, and `loadXML`
  is called with `LIBXML_NONET`. Combined, this neutralises XXE
  and billion-laughs attacks: an entity that's never read can't be
  expanded. Real OOXML files never declare a DOCTYPE.

Always sanitise the produced HTML before injecting it into a page —
the library escapes attribute values and text content, but it does
not run a full HTML sanitiser, and downstream contexts may have
stricter requirements (e.g., CSP that forbids inline `<sup>`).

## Warnings on real-world documents

The following messages are **expected** and don't indicate
malfunctions:

- `Unrecognised paragraph style: 'List Paragraph' (Style ID: ListParagraph)`
  — Word applies this to list items; the default style map only covers
  headings. Add `p[style-name='List Paragraph'] =>` to silence.
- `Unrecognised run style: 'FootnoteReference'` — same mechanism inside
  `footnotes.xml`.
- `Image of type image/bmp is unlikely to display in web browsers` —
  informational; the `<img>` is still emitted.

## Development

```bash
composer install
composer test     # Pest
composer stan     # PHPStan level 8
composer format   # Laravel Pint
```

Project guidance and porting conventions live in
[`CLAUDE.md`](CLAUDE.md). Limitations and roadmap items vs mammoth in
[`ROADMAP.md`](ROADMAP.md). Contributions welcome — see
[`CONTRIBUTING.md`](CONTRIBUTING.md).

## Attribution

elephant-php is an independent PHP port inspired by mammoth.js by
Michael Williamson. It adopts mammoth's core philosophy (extract
semantics, not formatting) and style-mapping DSL. It is not affiliated
with or endorsed by the mammoth.js project.

## License

[BSD-2-Clause](LICENSE). Copyright © 2026 Endless Creativity (PHP port)
and © 2013 Michael Williamson (mammoth.js, from which this work derives
its algorithmic structure and test fixtures).
