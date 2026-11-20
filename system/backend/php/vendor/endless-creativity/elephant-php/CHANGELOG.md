# Changelog

All notable changes to this project will be documented in this file. The
format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html)
once it reaches 1.0.

## [Unreleased]

Tracked in `ROADMAP.md`. Highlights still pending: DSL list
matchers (`p:unordered-list(N)`); custom `underline` mappers; CLI
`--style-map FILE`; track-changes deletion concatenation.

## [0.5.0] — 2026-10-15

### Added

- **Markdown tables**: `convertToMarkdown` renders tables as GFM pipe
  tables instead of flattening every cell into its own paragraph (the
  mammoth.js behaviour). The first row is the header, as GFM requires
  exactly one. Rows are padded to the widest row; `colspan` and
  `rowspan` become empty cells so every value stays under its column.
  A cell with several blocks is kept on one line by joining them with
  `<br>`, and `|` inside cells is escaped. A table nested inside a cell
  is flattened into that cell. This changes Markdown output for every
  document containing tables.

### Fixed

- **Empty table cells are no longer dropped**: an empty cell (or row, or
  table) was removed during HTML simplification, shifting every
  following cell of the row into the wrong column. Tables, rows and
  cells now carry mammoth's `forceWrite` marker, so an empty cell is
  emitted as `<td></td>` exactly as mammoth.js does.

## [0.4.1] — 2026-06-28

### Fixed

- **PHPStan 2.2 compatibility in `StylesReader`**: under PHPStan 2.2's
  stricter empty-array-shape inference, the style-deduplication
  `isset()` checks were flagged as `isset.offset` ("offset on
  `array{}`") and `booleanAnd.alwaysFalse`. The paragraph, character
  and table style maps are now explicitly typed as
  `array<string, Style>` (and the numbering pointer map as
  `array<string, string>`) so the lookups are recognised. No
  behavioural change.

## [0.4.0] — 2026-06-28

### Added

- **Hidden-text handling**: runs carrying the `w:vanish` ("Hidden")
  font effect are now parsed into a new `Run::$isHidden` flag, and a
  `Converter` / `DocumentConverter` option `ignoreHiddenText` controls
  whether they are rendered. The `w:vanish` element follows the usual
  boolean toggle rules (`w:val="false"` / `"0"` disables the effect).

### Changed

- **Hidden text is dropped by default**: with `ignoreHiddenText` on
  (the default), runs marked `w:vanish` are omitted from the HTML and
  Markdown output, so it matches what Word shows on screen and in
  print. The parsed `Document` still keeps the runs (flagged
  `isHidden`) so `transformDocument` callbacks can inspect them. This
  diverges from mammoth.js, which keeps hidden text. Pass
  `ignoreHiddenText: false` to restore the previous behaviour and emit
  hidden runs as ordinary text.

## [0.3.1] — 2026-04-28

### Fixed

- **Markdown emphasis broken by leading/trailing `<br>`**: a soft
  line break at the very start or end of a `<strong>`/`<em>` (and
  other emphasis) wrapper was emitted as `"  \n"` *inside* the
  delimiters, e.g. `**  \nName**`, which CommonMark refuses to
  parse as emphasis (the opener requires non-whitespace adjacent
  to the marker). The whitespace-hoisting pass now also pulls
  leading and trailing `<br>` elements out of emphasis wrappers,
  so the markers stay flush against text and the line break is
  preserved as a hard break outside the wrapper.

## [0.3.0] — 2026-04-28

Public API expansion to bring elephant-php to feature parity with
mammoth's documented options, plus a security review that closes
three vulnerabilities in the externally fed code paths.

### Added

- **`Converter` options:** `idPrefix`, `ignoreEmptyParagraphs`,
  `prettyPrint`, `transformDocument` — all mirroring mammoth's
  same-named options. Defaults preserve existing behaviour.
- **`Transforms` helpers** (`paragraph`, `run`, `elementsOfType`,
  `elements`, `getDescendants`, `getDescendantsOfType`) for
  walking and rebuilding the document tree, paired with
  `transformDocument`. Backed by a new `HasChildren` interface
  implemented by Document, Paragraph, Run, Hyperlink, Table,
  TableRow, TableCell so each container exposes
  `getChildren()` / `withChildren()`.
- **Checkbox form fields:** `<w:sdt><wordml:checkbox>` content
  controls and `FORMCHECKBOX` complex fields now produce
  `Checkbox` document nodes that render as
  `<input type="checkbox">` in HTML and `[x]` / `[ ]` in
  Markdown (composes naturally to GFM task lists inside `<li>`).
- **`numStyleLink` chasing in numbering:** an `<w:abstractNum>`
  pointing at a numbering-type `<w:style>` resolves transparently
  to that style's `<w:numId>`. Requires `Styles` to be passed to
  `NumberingReader`, which `Converter` now does automatically.
- **`findLevelByParagraphStyleId` fallback** in body reader: when
  a paragraph has only a styleId and no `<w:numPr>`, but the
  numbering definition tied a level to that style via
  `<w:lvl><w:pStyle>`, the paragraph picks up the level.
- **DSL style-map:** backslash escape sequences (`\n`, `\r`,
  `\t`, `\\`, `\'`) inside `'...'` strings; `:separator('text')`
  modifier on path elements.

### Fixed (security)

- **r:link images are no longer fetched from disk.** Mammoth
  resolves `<a:blip r:link="...">` via `fs.readFile` on the
  relationship target; the path is attacker-controlled in any
  user-uploaded scenario, exposing SSRF (`http://internal/...`),
  LFI (`file:///etc/passwd`), `phar://` deserialisation and
  arbitrary file reads via traversal. The reader now refuses to
  load r:link bytes outright; the path is preserved on the Image
  node so a `transformDocument` hook can make a different choice
  after seeing the document.
- **`javascript:` / `vbscript:` / `data:` hyperlinks are stripped.**
  Match is case-insensitive and tolerates leading whitespace and
  control characters (browsers do too). The `<a>` wrapper is
  dropped; children render inline. `mailto:`, `tel:`, `https:`,
  fragments, etc. flow through unchanged.
- **XML parsing rejects DOCTYPE and disables network entity
  loads.** Combined with the explicit `LIBXML_NONET` flag now
  passed to `loadXML`, this neutralises XXE and billion-laughs:
  an entity that's never read can't be expanded. Real OOXML
  never declares a DOCTYPE.

## [0.2.0] — 2026-04-27

Cleaner Markdown output and faithful Word numbering. The public API is
unchanged; output format has shifted in ways that may affect downstream
consumers diffing rendered Markdown.

### Changed

- **Bold marker switched from `__` to `**`.** The double-underscore
  wrapper clashed with literal underscore runs (Word fill-in fields
  like `_______`), breaking surrounding emphasis. Asterisks have no
  intraword conflict in docx-derived content.
- **Replaced mammoth's blanket text escape with a context-aware one.**
  `*`, `_`, `(`, `)`, `{`, `}` are never escaped; `#`, `+`, `-`, `.`
  only when at line start in a position that would parse as syntax;
  `[` and `]` only when forming the inline-link pattern `[text](url)`
  (citation-style brackets like `[1]`, `[Nota]`, `[sic]` stay
  literal); `!` only before such a link pattern. `\` and backtick
  remain always escaped.

### Added

- **Honour `<w:start>` from numbering definitions.** Word's exporter
  often splits a visually continuous "1., 2., 3." sequence into
  separate single-item ordered lists, each with its own `<w:start>`
  in the abstract numbering. Previously every chunk restarted at 1;
  the converter now forwards `start` on `<ol>` and the Markdown
  writer renders the first item from that number. `NumberingLevel`
  gained an optional `start: ?int` field.

### Fixed

- **Drop HTML `<a id="...">` anchors from Markdown output.** Bookmarks
  (Word's `_Toc...`, `_Hlk...`, `_Ref...`) used to leak into the
  Markdown as raw HTML; they are now silently elided. Real links
  (`<a href>`) are unaffected. HTML output keeps the anchors as
  before.

## [0.1.0] — initial release

First public release. Functionally equivalent to mammoth.js for typical
real-world documents (paragraphs, headings, runs, hyperlinks, lists,
tables, images, footnotes, comments, symbols, complex-field hyperlinks).

### Added

- Public API: `Converter::convertToHtml`, `convertToMarkdown`,
  `extractRawText`, `embedStyleMap`, `readEmbeddedStyleMap`.
- HTML and Markdown output from a shared intermediate document model.
- Style-mapping DSL (mammoth-compatible subset): paragraph, run, run
  property (`b/i/u/strike/all-caps/small-caps`), `comment-reference`,
  `highlight[color='X']`, `br[type='X']`, `table` matchers; element
  paths with `.class` and `[attr='val']` modifiers and the `:fresh`
  flag; `!` for ignore.
- Default style map that mirrors mammoth's heading rules
  (`Heading 1..6` → `h1..h6`).
- Reader features: paragraphs, runs (bold, italic, underline,
  strikethrough, all-caps, small-caps, sub/sup, highlight, font,
  fontSize), hyperlinks (regular and complex-field `HYPERLINK`), nested
  lists (mixed ordered/unordered), tables (`gridSpan` → colspan,
  `vMerge` → rowspan, `tblHeader` → `<thead>`), images (embedded via
  `r:embed` and linked via `r:link`, optional wrap in `<a>` from
  `wp:docPr/a:hlinkClick`), footnotes and endnotes with backlinks,
  comments (opt-in render via `comment-reference =>` DSL), content
  controls (`w:sdt`), `w:tab`, line/page/column breaks, bookmarks
  (`w:bookmarkStart`, except the synthetic `_GoBack`), no-break and
  soft hyphens, `w:sym` symbol fonts (Wingdings / Webdings / Symbol
  via the dingbat-to-unicode table), `mc:AlternateContent` collapsing.
- Native Markdown writer (port of mammoth's
  `lib/writers/markdown-writer.js`); CommonMark-aware whitespace
  cleanup so `__   bold   __` and tab-indented paragraphs render
  correctly instead of as code blocks.
- CLI `bin/elephant-php` (HTML / Markdown to stdout or file).
- CI on PHP 8.2 / 8.3 / 8.4 / 8.5 (Pint, PHPStan level 8, Pest).

### Documented limitations

- DSL list matchers (`p:unordered-list(N)`): list rendering is
  imperative, not DSL-driven. Functionally equivalent.
- `w:basedOn` style chains: not resolved (mammoth doesn't either).
- OMML equations, SmartArt, track changes, checkbox form fields:
  dropped or warned, matching mammoth's behaviour.

[Unreleased]: https://github.com/endless-creativity/elephant-php/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/endless-creativity/elephant-php/releases/tag/v0.1.0
