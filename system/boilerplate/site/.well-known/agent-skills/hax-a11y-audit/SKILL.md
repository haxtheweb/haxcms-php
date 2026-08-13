---
name: hax-a11y-audit
description: >
  READ-ONLY diagnostic: review authored HAX page/site content against WCAG 2.0 AA
  (image alt text, heading hierarchy, landmark structure, link text, form labels, color
  contrast, media caption/transcript/audio-description presence, keyboard/focus, ARIA
  correctness, data-table markup, list semantics) and emit a report with actionable HAX
  web-component + plain-HTML remediation. Use when the user says "is this accessible",
  "audit for WCAG", "check my page for accessibility", "does this meet WCAG 2.0 AA",
  "screen reader test", "are my images described", "is my color contrast OK",
  "do my videos have captions", or "accessibility audit" — even if they don't say
  "WCAG" or "a11y". Diagnoses only; hands off to hax-claudehax / hax-site-building for
  remediation and to hax-media-a11y for media-depth work.
version: 1.0.0
license: MIT
metadata:
  author: PRAW
  tags: [accessibility, wcag, wcag-2-0-aa, a11y, audit, hax, diagnostic, compliance, contrast, captions, screen-reader]
  requirements: "A HAX site path (site.json + pages/*.html) or a single page (JOS node / raw HTML / markdown). Emits a report; does NOT edit pages or site.json."
---

# HAX Accessibility Audit (WCAG 2.0 AA — Read-Only Diagnostic)

Review authored content on an existing HAX site/page against **WCAG 2.0 AA** and emit a
diagnostic + remediation report. This skill **diagnoses and recommends only** — it does not
edit pages, mutate `site.json`, or insert components. It mirrors the `hax-udl-audit` and
`hax-ubd-unit-audit` pattern but operates through the WCAG lens: does the authored content
meet the technical access standard for people with disabilities?

## The worldview in one paragraph

WCAG 2.0 AA is an explicit HAX ecosystem pillar — the ecosystem commits to it in its community
pillars and component audits — yet no existing skill audits *authored content* for it. This
skill fills that gap. WCAG is about **technical access for people with disabilities**: can a
screen-reader user perceive the content, can a keyboard-only user operate it, is contrast
sufficient for low-vision users, are media alternatives present. This is distinct from **UDL**
(pedagogical inclusivity — multiple means of reaching diverse learners), from
**content chunking** (cognitive load — is the page a wall of text), and from
**component-internal accessibility** (a custom element's own shadow-DOM ARIA/keyboard, which
`hax-webcomponent-dev` owns). Much of WCAG remediation on a HAX site is plain HTML: alt text,
semantic headings, landmark roles, link text, `scope`/`headers`/`caption` on tables. The rest
maps to real HAX components that bake accessibility in. This audit reports what fails, cites
the WCAG criterion, and recommends only real remediation — never an invented tag.

## When to Use

**Trigger conditions:**
- "Is this accessible" / "audit for WCAG" / "check my page for accessibility"
- "Does this meet WCAG 2.0 AA" / "screen reader test" / "accessibility audit"
- "Are my images described" / "is my color contrast OK" / "do my videos have captions"
- "Can a keyboard user get through this page" / "is my heading order right"
- even when the user does not say "WCAG" or "a11y" — if they question whether authored content
  is perceivable, operable, understandable, or robust for users with disabilities, this is the
  skill

**When NOT to use (with redirect):**
- Component-INTERNAL accessibility (a custom element's own shadow DOM ARIA/keyboard/tabindex) → `hax-webcomponent-dev`
- Pedagogical inclusivity / multiple means / learner agency → `hax-udl-audit`
- Page-scope cognitive load / "wall of text" / chunking → `hax-content-chunking-audit`
- DDD/CSS token-usage compliance (is the DDD token used at all) → `hax-design-system`
- MEDIA DEPTH (caption quality/timing/accuracy, transcript fidelity, audio-description
  authoring/coverage, asset production) → `hax-media-a11y` (this skill flags media
  caption/transcript/audio-description *presence* as a WCAG 1.2 finding and hands off to
  `hax-media-a11y` for depth + asset production — see the overlap rule below)
- Unit alignment / backward design → `hax-ubd-unit-audit`
- Cognitive level of an objective/assessment → `grad-blooms`

## Scope: this skill is READ-ONLY

This skill **diagnoses and recommends only**. It produces a report. To apply remediation, hand
off to the related skills and the `hax` CLI / `hax-claudehax` (see "Implementing the
Recommendations" below). Never edit `pages/*.html` or `site.json` from within this skill.

## Inputs

- a HAX site path: `site.json` (JOS tree) + `pages/*.html`, **or** a single page (JOS node / raw HTML / markdown)
- optional `pageSlug` to scope the audit to one page (else audit every page and report site-level patterns)
- optional reference to the DDD token values for contrast computation (the skill can read `d-d-d` tokens from the sibling repo if needed)

## Methodology

1. **Ingest structure.** Locate the page(s) in the HAX project:
   - `site.json` (JSON Outline Schema) for the node tree + metadata
   - `pages/<slug>.html` for rendered page content (the canonical content source)
   - a JOS items export or markdown representation for offline review
2. **Run the WCAG 2.0 AA checks across authored content.** For each page, evaluate:
   - **1.1 Text Alternatives (1.1.1):** every `<img>`, `media-image`, `a11y-figure`, or
     background-image carrying information has meaningful `alt` text. Decorative images get
     `alt=""` (or `aria-hidden="true"` on `simple-icon-lite`) — not missing alt, not
     `alt="image"`. Flag both missing-alt and junk-alt (`alt="placeholder"`, `alt="arrow"`).
   - **1.3 Adaptable / heading hierarchy (1.3.1):** exactly one `h1` per page; no skipped
     levels (no `h2` → `h4`); headings used for structure, not styling. Flag the skip location.
   - **1.3 Adaptable / landmarks (1.3.1):** page content uses semantic landmarks (`header`,
     `nav`, `main`, `article`, `aside`, `footer`) or equivalent ARIA roles. Flag a page with
     no `main` landmark or div-soup structure.
   - **1.4 Distinguishable / contrast (1.4.3 text, 1.4.11 UI):** authored text and UI
     components meet 4.5:1 (normal text < 18pt / 14pt bold) or 3:1 (large text ≥ 18pt /
     14pt bold, and UI component boundaries). Compute ratios against the DDD token values the
     page uses. Report the actual ratio and the token pair. (Token *usage* compliance — did
     the author use a DDD token at all — is `hax-design-system`'s job; *contrast checking*
     against token values is this skill's job.)
   - **2.4 Navigable / link text (2.4.4, 2.4.9):** no "click here", "read more", "learn more",
     or bare URLs as link text. Every link's purpose is clear from the link text alone (or
     link text + context). Flag each ambiguous link with its text and href.
   - **2.4 Navigable / focus order (2.4.3):** authored interactive markup (custom buttons,
     tabbed interfaces, collapsible sections) is keyboard-reachable in a logical order. Flag
     `tabindex` values that are positive (anti-pattern) or authored elements that lack
     keyboard access.
   - **3.3 Input Assistance / form labels (3.3.2):** every authored form field has a
     programmatic label (`<label for=...>`, `aria-label`, or `aria-labelledby`). Flag
     unlabelled `<input>`, `<select>`, `<textarea>` in authored content.
   - **4.1 Compatible / ARIA correctness (4.1.2):** authored ARIA is valid — `role` matches
     the element's function, `aria-label`/`aria-labelledby` on interactive elements,
     `aria-hidden="true"` on presentational icons, no redundant roles on native semantics
     (e.g. `<button role="button">` is redundant). Flag each violation with the exact markup.
   - **1.3 Data tables (1.3.1):** data tables use `<caption>`, `scope` on `<th>` (or
     `headers`/`id` for complex tables), and are not used for layout. Flag tables missing
     these.
   - **1.3 List semantics (1.3.1):** lists use `<ul>`/`<ol>`/`<dl>`; do not flag styled lists
     that are genuinely presentational.
   - **1.2 Media alternatives (1.2.1, 1.2.2, 1.2.3, 1.2.5):** every `video-player` /
     `audio-player` / `a11y-media-player` with audio has a `track` (captions) present; video
     has either captions or an audio-description track (`audio-description-source`); audio has
     a transcript link or slotted transcript. Flag **presence only** — caption
     quality/timing/accuracy, transcript fidelity, and audio-description authoring/coverage
     are `hax-media-a11y`'s depth scope (see overlap rule).
3. **Map each finding to a real HAX remediation.** Use `references/a11y-element-map.md`.
   Every recommendation is either a **component swap** (replace the failing markup with a real
   HAX component that bakes in the accessibility) or a **plain-HTML fix** (edit the markup in
   `pages/<slug>.html` directly — alt text, headings, landmarks, link text, table markup).
   Never invent a tag name and never recommend a legacy tag (`instruction-card`,
   `lrndesign-sidenote`, `link-preview`, `model-viewer` are excluded).
4. **Apply the overlap / defer rules:**
   - **Media depth (SYMMETRIC with `hax-media-a11y`):** when a media finding is about caption
     *quality*, transcript *fidelity*, or audio-description *authoring/coverage*, defer to
     `hax-media-a11y`. This skill reports media *presence* (is there a `track` at all?) as a
     WCAG 1.2 finding and hands the depth work off. State this split explicitly in the finding
     so the orchestrator knows where to send the follow-up. This rule is symmetric: if
     `hax-media-a11y` discovers a *presence* gap (no track file exists), it should hand back
     to this skill to file the WCAG 1.2 finding.
   - **Component-internal a11y:** if the failure is inside a custom element's shadow DOM
     (the element's own tabindex/ARIA/keyboard), defer to `hax-webcomponent-dev`.
   - **Pure token usage:** if the only issue is "the author hardcoded a hex color instead of
     a DDD token," that is `hax-design-system`'s scope. This skill only checks contrast
     *ratios* against the tokens the page actually uses.
   - **Cognitive load / chunking:** if the page is a wall of text, that is
     `hax-content-chunking-audit`'s scope — do not double-report it as a WCAG finding.
5. **Determine the overall rating:**
   - **Compliant:** all checked criteria pass; no Level A or AA failures. Minor advisories
     (e.g. "consider a longer description for this complex diagram") may be noted but do not
     affect the rating.
   - **Partial:** one or more Level A/AA failures are present but the page is fundamentally
     perceivable/operable (e.g. a few missing alt texts, one heading skip, one ambiguous
     link). Remediation is surgical.
   - **Non-compliant:** a structural failure that blocks access — e.g. no `main` landmark, a
     full-screen keyboard trap, contrast below 3:1 on body text, or zero media alternatives
     on a media-heavy page.
6. **Emit the report** (format below), then an "Implementation handoff" block.

## Expected Output Format

Format findings exactly like this structure (mirrors `hax-udl-audit` / `hax-ubd-unit-audit`):

```
### 📊 WCAG 2.0 AA Diagnostics
* **WCAG Rating:** [Compliant / Partial / Non-compliant]
* **Primary Barrier:** [the worst offender — one sentence, e.g. "3 images missing alt text and one h2→h4 heading skip on the landing page."]

### 🔍 Findings & HAX Remediation

* **{page slug / page-level / site-level} — {WCAG criterion}**
  * **Status:** [Fail / Partial / Pass-with-advisory]
  * **Issue:** [the exact failing markup, with the measured signal, e.g. "media-image at line 42 has alt='' but the image conveys the chart data — missing text alternative."]
  * **WCAG Rationale:** [cite the criterion and why it blocks access, e.g. "1.1.1 Non-text Content: informative images must have a text alternative so screen-reader users perceive the same information."]
  * **HAX Remediation:** [real component swap OR plain-HTML fix, e.g. "Add descriptive alt text to the media-image (alt='Bar chart: enrollment doubled from 2019 to 2024'). For complex diagrams, add a long description in a stop-note below."] OR "Hand off to hax-media-a11y for caption depth."]

* **{page slug / page-level / site-level} — {WCAG criterion}**
  * ...

### 🛠 Implementation handoff
* [one-line action per fix → skill/CLI, e.g. "Fix alt text on 3 media-image elements in pages/landing.html via /hax or direct edit."]
```

## Worked example

**Input** — a HAX page (`pages/intro.html`) containing:
```html
<h2>Introduction</h2>
<p>Welcome to the course. <a href="/syllabus">Click here</a> for the syllabus.</p>
<media-image
  source="files/enrollment-chart.png"
  alt="">
</media-image>
<h4>Course goals</h4>
<p>By the end you will...</p>
<label>Search</label>
<input type="text" name="q">
```

**Output:**
```
### 📊 WCAG 2.0 AA Diagnostics
* **WCAG Rating:** Partial
* **Primary Barrier:** Missing image alt text, an ambiguous "Click here" link, an h2→h4
  heading skip, and an unlabelled input — four Level A failures on one page.

### 🔍 Findings & HAX Remediation

* **Page "intro" — 1.1.1 Non-text Content**
  * **Status:** Fail
  * **Issue:** `media-image` (source="files/enrollment-chart.png") has `alt=""`, marking it
    decorative, but the image conveys enrollment data that is not present in the surrounding
    text.
  * **WCAG Rationale:** 1.1.1 requires that informative non-text content has a text
    alternative. An empty alt hides the chart from screen-reader users entirely.
  * **HAX Remediation:** Add descriptive alt text (`alt="Bar chart: enrollment doubled from
    2019 to 2024"`). For the chart, also add a `stop-note` below with a fuller data summary,
    or swap to `a11y-figure` with a `caption` slot for the long description.

* **Page "intro" — 2.4.4 Link Purpose (In Context)**
  * **Status:** Fail
  * **Issue:** Link text is "Click here" with href="/syllabus". The destination is not
    apparent from the link text.
  * **WCAG Rationale:** 2.4.4 requires that each link's purpose be clear from the link text
    alone (or link text + context). "Click here" tells a screen-reader user nothing when
    navigating by link list.
  * **HAX Remediation:** Plain-HTML fix — change the link text to "Read the syllabus" in
    `pages/intro.html`. No component swap needed.

* **Page "intro" — 1.3.1 Info and Relationships (headings)**
  * **Status:** Fail
  * **Issue:** Heading order skips from `h2` ("Introduction") to `h4` ("Course goals") with
    no `h3`. Also, there is no `h1` on the page — the first heading is an `h2`.
  * **WCAG Rationale:** 1.3.1 requires that heading relationships are programmatically
    correct. Skipped levels and a missing `h1` break screen-reader navigation by headings.
  * **HAX Remediation:** Plain-HTML fix — add an `h1` page title at the top of
    `pages/intro.html`, and change the `h4` to `h3` so the order is h1 → h2 → h3.

* **Page "intro" — 3.3.2 Labels or Instructions**
  * **Status:** Fail
  * **Issue:** The `<input type="text" name="q">` has a `<label>Search</label>` but the label
    is not programmatically associated — there is no `for` attribute linking the label to the
    input.
  * **WCAG Rationale:** 3.3.2 requires that form fields have programmatic labels. An
    unassociated `<label>` does not expose the field name to assistive technology.
  * **HAX Remediation:** Plain-HTML fix — add `for="q"` to the label and `id="q"` to the
    input. Or swap to `simple-fields` which bakes in the label/field association
    automatically and is the HAX-preferred input ecosystem.

### 🛠 Implementation handoff
* 1.1.1: add descriptive alt to the media-image in pages/intro.html (direct edit or /hax).
* 2.4.4: change "Click here" → "Read the syllabus" in pages/intro.html (direct edit).
* 1.3.1: add h1 page title, fix h4→h3 in pages/intro.html (direct edit).
* 3.3.2: associate the label (for/id) or swap to simple-fields via /hax.
* Confirm DDD token contrast on any restyled elements via hax-design-system.
```

## Implementing the Recommendations

This audit is the diagnosis step. Apply fixes with the related skills and the `hax` CLI:

- **`hax-claudehax`** — insert/replace components in existing pages:
  - `/hax replace the unlabelled input in <page> with a simple-fields search input`
  - `/hax add an accessible figure with caption to <page> using a11y-figure`
- **`hax-site-building`** — owns page structure; edit page *content* at `pages/<slug>.html`
  directly for plain-HTML fixes (alt text, headings, landmarks, link text, table markup, label
  association); add pages via `hax site node:add --title "<t>" --slug "<s>" --content <path> --format html --y --no-i` (single) or `hax site site:items-import --items-import <items.json> --y --no-i` (bulk); verify with `hax site site:items`. Never hand-edit `site.json`.
- **`hax-design-system`** — DDD tokens for spacing, color, icon sizing on any inserted
  component; and pure token-usage compliance (did the author use a DDD token at all?).
- **`hax-media-a11y`** — media DEPTH: caption quality/timing/accuracy, transcript fidelity,
  audio-description authoring/coverage, asset production. This skill flags media
  caption/transcript/audio-description *presence* (WCAG 1.2) and hands off to
  `hax-media-a11y` for depth.
- **`hax-webcomponent-dev`** — component-INTERNAL accessibility (a custom element's own shadow
  DOM ARIA/keyboard/tabindex).
- **`hax-udl-audit`** — if the user's real concern is pedagogical inclusivity (multiple means
  of reaching diverse learners), not technical WCAG compliance, defer to UDL.
- **`hax-content-chunking-audit`** — if the page is a wall of text (cognitive load), defer to
  chunking.

**CLI rules (from PRAW RULES.md):**
- Use the local/global `hax` command — **not** `npx hax` (resolves to a different package).
- When scripting, pass automation flags to avoid prompts/new windows: `--y --no-i` (add `--auto` / `--quiet` / `--skip` as needed).
- Never hand-edit `site.json` for structure — use the CLI.
- `a11y-collapse` MUST set `heading-button`.
- Audio via `media-playlist` + `audio-player`; inputs via `simple-fields`; tables via `editable-table` / `editable-table-display`; educational elements get `oer-schema` metadata.

## Acceptance criteria (for the audit report itself)

- Every finding cites the WCAG 2.0 AA criterion (e.g. "1.1.1 Non-text Content") and a real
  HAX component swap or plain-HTML fix.
- No recommended component is outside the verified registry in `references/a11y-element-map.md`.
- The rating is one of: Compliant / Partial / Non-compliant.
- Contrast findings report the actual computed ratio and the token pair (not a vague "looks
  low").
- Media findings distinguish **presence** (this skill's scope — WCAG 1.2) from **depth**
  (`hax-media-a11y`'s scope) and hand off depth explicitly.
- A page with zero heading failures, zero missing alt texts, and zero ambiguous links is not
  rated Non-compliant unless a structural barrier (no `main` landmark, keyboard trap,
  sub-3:1 body contrast, zero media alternatives) is present.

## Gotchas

- **Decorative images get `alt=""`, not missing alt.** A missing `alt` attribute is a failure
  (some screen readers read the filename). An empty `alt=""` is correct for decorative images.
  Do not flag `alt=""` on a genuinely decorative `simple-icon-lite`; do flag it on an
  informative `media-image`.
- **Junk alt is as bad as missing alt.** `alt="image"`, `alt="placeholder"`, `alt="arrow"`
  convey no information. Flag them the same as missing alt.
- **Contrast is about the token pair, not the tag.** This skill computes the ratio between the
  text-color token and background-color token the page actually uses. If the author hardcoded
  a hex color instead of a token, note it but defer the token-usage compliance to
  `hax-design-system` — do not double-report.
- **Media presence vs. media depth.** This skill checks whether a `track` exists on a
  `video-player`. Whether the captions are accurate, well-timed, or complete is
  `hax-media-a11y`'s job. Do not attempt to evaluate caption quality here — flag the presence
  gap and hand off.
- **Heading order, not heading count.** A page can have many headings and be Compliant; the
  issue is skipped levels and a missing `h1`. Do not flag a well-structured page with many
  `h3`s.
- **Native semantics usually do not need ARIA.** `<button>` already has the button role;
  adding `role="button"` is redundant (flag it). `<nav>` already implies a navigation
  landmark. Only add ARIA when the native semantics are insufficient.
- **Never recommend legacy/third-party tags.** `instruction-card`, `lrndesign-sidenote`,
  `link-preview`, and `model-viewer` appear in older courses but are not in the current
  monorepo — use the alternatives in the element map.
- **Don't edit here.** This skill emits a report. Plain-HTML edits belong in
  `pages/<slug>.html` (via `hax-site-building` or direct edit); component swaps belong via
  `hax-claudehax`; structural edits belong to the `hax` CLI.

## Dependencies

- **Reads:** `site.json` / `pages/*.html` / a single JOS node / raw HTML / markdown
- **Consults:** `references/a11y-element-map.md` (the verified component map — single source of truth)
- **Hands off to:** `hax-claudehax` / `hax-site-building` (insertion + plain-HTML edits), `hax-design-system` (DDD tokens + token-usage compliance), `hax-media-a11y` (media depth — symmetric overlap partner), `hax-webcomponent-dev` (component-internal a11y)
- **Defers to:** `hax-udl-audit` (pedagogical inclusivity), `hax-content-chunking-audit` (cognitive load), `hax-ubd-unit-audit` (unit alignment), `grad-blooms` (cognitive level)

## References

- `references/a11y-element-map.md` — WCAG failure → HAX component swap / plain-HTML fix map (verified tags only; the iron rule)
- PRAW RULES.md: `~/Documents/git/haxtheweb/praw/RULES.md`
- W3C (2008). *Web Content Accessibility Guidelines (WCAG) 2.0*. https://www.w3.org/TR/WCAG20/
- W3C (2023). *WCAG 2.0 Quick Reference*. https://www.w3.org/WAI/WCAG20/quickref/
