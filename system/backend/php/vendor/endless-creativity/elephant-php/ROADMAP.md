# Roadmap & Known Gaps

For real-world documents (paragraphs, headings, inline runs,
hyperlinks, nested lists, tables with colspan/rowspan, embedded images,
foot/endnotes, comments, special symbols, complex-field hyperlinks, SDT
and FORMCHECKBOX checkboxes) the library is **functionally equivalent to
mammoth.js**. What follows is the residual delta, grouped by area.

## Reader: features in mammoth that we don't yet implement

| Missing | Notes |
|---|---|
| **Track changes** (`w:ins`, `w:moveTo`, `w:moveFrom`, `w:cellIns/Del/Merge`) | mammoth has partial handling: deleted-paragraph contents are concatenated into the following paragraph. We currently drop track-change markup silently. |

## DSL style-map: forms not yet supported

| Form | Status |
|---|---|
| `p:unordered-list(N)` / `p:ordered-list(N)` | Not implemented. List rendering is imperative in `DocumentConverter::convertParagraph`, not driven by the style map. The tokeniser already understands `(`, `)` and integers, but wiring the matchers requires replacing the imperative path -- non-trivial refactor with low ROI given the current output is correct. |
| `ul\|ol` (multi-tag in element position) | Not implemented at the DSL level. `Tag::matchAlternativeTagNames` already exists and is used internally by the imperative list converter. |

## Public API not yet exposed

- `underline` module — custom mappers for the underline run property.
- CLI `--style-map FILE` — load DSL rules from an external text file.

## Deliberate divergences from mammoth (security)

These are **not bugs to fix** — they are intentional choices because
elephant-php is a server-side library and the docx is typically
attacker-controlled.

- **`r:link` images are not fetched.** mammoth calls `fs.readFile` on
  the relationship target, exposing SSRF / LFI / phar deserialisation
  via attacker-chosen paths. We refuse to load and leave the path on
  the `Image` node so a `transformDocument` hook can decide what to do.
- **Hyperlinks with `javascript:` / `vbscript:` / `data:` schemes lose
  their `<a>` wrapper.** mammoth forwards them verbatim; we treat them
  as XSS payloads.
- **XML with a DOCTYPE declaration is rejected**, combined with
  `LIBXML_NONET` on `loadXML`. Closes XXE and billion-laughs. Real
  OOXML never declares a DOCTYPE.

## Other deliberate divergences

- **DSL parser failures throw `InvalidArgumentException`.** mammoth
  returns `Result(null, [warning(...)])` and keeps going. We chose
  rigid over tolerant: a malformed style-map is almost always a bug
  the developer wants to see immediately.
- **List rendering is imperative**, not DSL-driven. Decision made to
  ship lists before the DSL parser; replicating mammoth's `unordered-list(N)`
  approach would require a converter refactor with no user-visible
  benefit -- output is identical.
- **No `.docx` writer.** One-way conversion, same as mammoth. For
  generating .docx from PHP, see PhpWord and similar.

## Test coverage gaps still open

Most edge cases listed in earlier snapshots are now covered (see commits
ec4adfb and 42bb200). The remaining wishlist is **integration fixtures**:
each docx in `mammoth/test/test-data/` that isn't yet round-tripped through
`Converter` (`comments.docx`, `endnotes.docx`, `external-picture.docx`,
`text-box.docx`, `embedded-style-map.docx`, `strict-format.docx`, ...).
Adds end-to-end confidence on the full pipeline rather than just the
unit-tested seams.
