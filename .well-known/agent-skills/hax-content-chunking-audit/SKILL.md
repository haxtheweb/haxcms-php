---
name: hax-content-chunking-audit
description: >
  Audit a HAX site page (JSON Outline Schema node, raw HTML in pages/, or markdown) against
  cognitive-load and content-chunking instructional-design standards, then return actionable HAX
  web-component remediation. Use when the user asks to review a course page, textbook module, or
  HAX site for readability, "wall of text", overly long sections, list fatigue, media saturation,
  microlearning chunking, or to "make this page less overwhelming" — even if they don't say "audit".
version: 1.0.0
license: MIT
metadata:
  author: PRAW
  tags: [instructional-design, hax, content-chunking, microlearning, cognitive-load, audit]
  requirements: "Access to HAX site schema JSON (site.json / JOS items), raw HTML pages in pages/, or markdown representations of course nodes."
---

# HAX Content Chunking Audit

Audit a page built in the HAX ecosystem against cognitive-load theory and content-chunking
standards, then pair every violation with a real, HAX-capable web component that fixes it.

## When to Use

**Trigger conditions:**
- Reviewing an online course page, textbook module, or HAX site node for readability and structure
- The page comes from a HAX project (`site.json`, `pages/*.html`, a JOS items export, or markdown)
- The user says a page is "too long", "wall of text", "overwhelming", "too many bullets", or asks
  for microlearning / chunking improvements

**When NOT to use:**
- Writing learning objectives or assessing cognitive-level balance — use the `grad-blooms` skill
- Creating a brand-new site or restructuring `site.json` — use the `hax-site-building` skill
- Auditing CSS for DDD design-system compliance — use the `hax-design-system` skill

## Scope: this skill is READ-ONLY

This skill **diagnoses and recommends only**. It does not edit pages, mutate `site.json`, or insert
components. It produces a report. To actually apply the recommendations, hand off to the related
skills and `hax` CLI commands in the "Implementing the Recommendations" section below.

## Core Analysis Thresholds

Use these instructional-design benchmarks to flag structural issues when parsing page data (JOS
schema, raw HTML, or markdown):

- **Wall of Text:** any continuous paragraph block exceeding **150–200 words** with no structural break.
- **Heading Gaps:** a text run between two headings (`h2`, `h3`, `h4`) exceeding **300–400 words**
  with no clarifying subheading.
- **List Fatigue:** any `<ul>` or `<ol>` with more than **7 items**.
- **Media Saturation:** any standalone video or audio asset longer than **10 minutes** with no
  interactive check-in or subdivision.

Treat thresholds as guidelines, not hard limits. A 205-word paragraph that is a single coherent
definition is fine; a 180-word paragraph burying three distinct concepts is not. Always state the
actual measurement so the human can judge.

## Evaluation Workflow

1. **Ingest structure.** Locate the page in the HAX project. Common sources:
   - `site.json` (JSON Outline Schema) for the node tree and metadata
   - `pages/<slug>.html` for the rendered page content (the canonical content source)
   - a JOS items export or markdown representation for offline review
2. **Scan nodes.** Parse text, list, heading, and media nodes. Compute word counts, run lengths,
   and element counts. Record the node index / heading anchor for each finding.
3. **Map HAX remediation.** Pair every violation with a real, HAX-capable component (see the
   remediation map below). Do not invent tag names — only recommend components that exist in the
   HAX registry and have `haxProperties` (i.e. they can be authored in the HAX editor).
4. **Generate report.** Emit the diagnostics + recommendations format below, then an
   "Implementation handoff" block pointing at the CLI/skill that applies the fix.

## HAX Component Remediation Map

Every recommendation must use a real HAX component. Default mappings:

| Issue | HAX component | Notes |
|---|---|---|
| Wall of text / needs active retrieval | `self-check`, `multiple-choice`, `fill-in-the-blanks` | Insert a check-in at the split point to promote retrieval |
| Reflection / "stop and think" break | `stop-note` | The HAX reflection/stop component |
| Heading gap / long section | new `h2`/`h3` + `stop-note` or `self-check` | Subdivide and add a check-in |
| List fatigue (>7 items) | `a11y-collapse` (grouped) or `a11y-tabs` (categorized) | Progressive disclosure; always set `heading-button` on `a11y-collapse` |
| Media saturation (video >10 min) | `video-player` (segmented) + `self-check` between segments | Break into chapters with check-ins |
| Media saturation (audio >10 min) | `media-playlist` + `audio-player` | Preferred HAX audio presentation |
| Flash-card / retrieval reinforcement | `flash-card` | For key-term review |

When styling any inserted component, use DDD design tokens for spacing, color, and icon sizing via
the `hax-design-system` skill. Apply OER Schema metadata to educational elements for semantic
interoperability.

## Expected Output Format

Format findings exactly like this structure:

```
### 📊 Content Chunking Diagnostics
* **Cognitive Load Rating:** [Pass / Needs Improvement / Critical Overload]
* **Primary Structural Bottleneck:** [Brief description of the worst offending element]

### 🔍 Identified Issues & HAX Component Recommendations

* **[Node Index / Section Title]**
  * **Issue:** [e.g., Wall of Text — 285-word paragraph]
  * **Instructional Rationale:** [Why this hurts learning — cognitive load / working memory]
  * **HAX Remediation:** [Real component + concrete placement, e.g. "Split at sentence 3; insert a `multiple-choice` check-in immediately after."]

* **[Node Index / Section Title]**
  * **Issue:** [e.g., Exhaustive List — 12 items]
  * **Instructional Rationale:** [Why this hurts learning]
  * **HAX Remediation:** [e.g. "Group into two themed sets inside `a11y-collapse` containers (set `heading-button`)."]

### 🛠 Implementation handoff
* [One-line action per fix, naming the skill/CLI to apply it — see "Implementing the Recommendations"]
```

### Worked example

**Input** — HAX page snippet:
```json
[
  { "tag": "p", "content": "Lorem ipsum dolor sit amet … [250 words uninterrupted] … ut enim ad minim veniam." },
  { "tag": "ul", "children": [
      { "tag": "li", "content": "Item 1" }, { "tag": "li", "content": "Item 2" },
      { "tag": "li", "content": "Item 3" }, { "tag": "li", "content": "Item 4" },
      { "tag": "li", "content": "Item 5" }, { "tag": "li", "content": "Item 6" },
      { "tag": "li", "content": "Item 7" }, { "tag": "li", "content": "Item 8" },
      { "tag": "li", "content": "Item 9" }
  ] }
]
```

**Output:**
```
### 📊 Content Chunking Diagnostics
* **Cognitive Load Rating:** Needs Improvement
* **Primary Structural Bottleneck:** Node 0 text density (250-word unbroken paragraph).

### 🔍 Identified Issues & HAX Component Recommendations

* **Node 0 (<p>)**
  * **Issue:** Wall of Text — 250-word paragraph.
  * **Instructional Rationale:** Unbroken text blocks exceed working-memory capacity, lowering comprehension and completion.
  * **HAX Remediation:** Split into two shorter paragraphs; place a `stop-note` reflection prompt between them, or a `multiple-choice` check-in to drive active retrieval.

* **Node 1 (<ul>)**
  * **Issue:** List Fatigue — 9 sequential bullets.
  * **Instructional Rationale:** Long flat lists are hard to scan and categorize; readers lose tracking past ~7 items.
  * **HAX Remediation:** Group the 9 items into two themed sets and place each set in an `a11y-collapse` container with `heading-button` set.

### 🛠 Implementation handoff
* Node 0: edit `pages/<slug>.html` to split the paragraph and insert a `stop-note` (or use `/hax add a check-for-understanding section to <page>`).
* Node 1: edit `pages/<slug>.html` to wrap the grouped lists in `a11y-collapse` (set `heading-button`).
* Apply DDD tokens via the `hax-design-system` skill; align the check-in's cognitive level via `grad-blooms`.
```

## Implementing the Recommendations

This audit is the diagnosis step. Apply the fixes with these related skills and the `hax` CLI:

- **`hax-claudehax`** — operate the site from Claude Code via the `/hax` slash command or the HAX
  CLI. Directly relevant for inserting components/sections into an existing page:
  - `/hax add an interactive check-for-understanding section to <page> using HAX web components, not plain HTML`
  - `/hax add a multiple choice quiz to this page based on the page content`
  - `/hax add 5 flash cards to <page> using the best HAX web component`
- **`hax-site-building`** — owns page structure. The CLI owns `site.json` / page structure; you own
  page **content**. To remediate an existing page, edit its HTML content file at `pages/<slug>.html`
  directly (split paragraphs, insert `stop-note` / `self-check` / `a11y-collapse`). To add a new
  chunked page:
  - Single page: `hax site node:add --title "<title>" --slug "<slug>" --content <path-to-html-file> --format html --y --no-i`
  - Bulk: `hax site site:items-import --items-import <items.json> --y --no-i`
  - Verify: `hax site site:items`
- **`hax-design-system`** — DDD tokens for spacing, color, icon sizing on any inserted component.
- **`grad-blooms`** — when a check-in is recommended, confirm its cognitive level matches the
  learning objective (don't insert a Remember-level quiz where an Analyze prompt is needed).

**CLI rules (from PRAW RULES.md):**
- Use the local/global `hax` command — **not** `npx hax` (resolves to a different package).
- When scripting, always pass automation flags to avoid prompts/new windows: `--y --no-i`
  (add `--auto` / `--quiet` / `--skip` as needed).
- Always create/structure pages through the `hax` CLI, never by hand-editing `site.json`.
- On `a11y-collapse`, always set the `heading-button` property so the whole heading is clickable.
- For audio assets, prefer `media-playlist` + `audio-player` over inline audio.

## Gotchas

- **Chunking is not always better.** Over-fragmenting a short, cohesive argument into tiny pieces
  can hurt coherence more than a single readable block helps. Flag real overload, not mild density.
- **Thresholds are heuristics.** A 205-word definition is often fine; a 160-word paragraph hiding
  three unrelated ideas is not. Report the measured number and let the human decide edge cases.
- **Only recommend real components.** Never suggest a tag that is not in the HAX registry. When
  unsure whether a component exists or how to slot it, check the registry or defer to
  `hax-claudehax` / `hax-webcomponent-dev` rather than guessing a tag name.
- **Don't edit structure here.** This skill emits a report. Page-structure edits belong to the
  `hax` CLI via `hax-site-building`; content edits belong in `pages/<slug>.html` or via `/hax`.

## References

- PRAW RULES.md: `~/Documents/git/haxtheweb/praw/RULES.md` (canonical ecosystem rules by Rule ID)
- Related skills: `hax-claudehax`, `hax-site-building`, `hax-design-system`, `grad-blooms`
- HAX web components registry: `~/Documents/git/haxtheweb/webcomponents/elements`
- HAX CLI: `hax site --help`, `hax --help`
