---
name: hax-udl-audit
description: >
  READ-ONLY diagnostic: review a HAX site/page against Universal Design for Learning (UDL) 3.0 —
  multiple means of Engagement, Representation, and Action & Expression — and emit a report with
  actionable HAX web-component remediation. Use when the user says "is this accessible to all
  learners", "audit for UDL", "does this page reach everyone", "add multiple means of expression",
  "my course is all reading and video with no interaction", or "universal design" — even if they
  don't say "UDL". Diagnoses only; hands off to hax-claudehax / hax-site-building for remediation.
version: 1.0.0
license: MIT
metadata:
  author: PRAW
  tags: [instructional-design, udl, universal-design-for-learning, accessibility, audit, hax, diagnostic, learner-agency]
  requirements: "A HAX site path (site.json + pages/*.html) or a single page (JOS node / raw HTML / markdown). Emits a report; does NOT edit pages or site.json."
---

# HAX UDL Audit (Read-Only Diagnostic)

Review an existing HAX site/page against the **CAST UDL Guidelines 3.0** and emit a diagnostic +
remediation report. This skill **diagnoses and recommends only** — it does not edit pages, mutate
`site.json`, or insert components. It mirrors the `hax-ubd-unit-audit` and `hax-content-chunking-audit`
pattern but operates through the UDL lens: does the page reach all learners through multiple means
of engagement, representation, and action & expression?

## The worldview in one paragraph

There is no "average" brain. Learner variability is predictable, so design for it up front rather
than retrofitting accommodations. UDL 3.0 organizes that design around three principles —
**Engagement** (the WHY of learning), **Representation** (the WHAT), and **Action & Expression**
(the HOW) — each with three guidelines (G1–G9). The goal is learner agency: purposeful &
reflective, resourceful & authentic, strategic & action-oriented. UDL is a set of design prompts,
not a checklist — so this audit reports what is present, what is absent, and recommends only real
HAX components for the gaps. The empirically-confirmed gap across the HAX training corpus (36
courses, 4,870 pages) is **Action & Expression**: courses are rich in media (Representation) and
moderate in reflection/objectives (Engagement) but nearly devoid of interactive assessment and
varied response methods. Bias the audit toward that gap.

## When to Use

**Trigger conditions:**
- "Is this accessible to all learners" / "does this page reach everyone" / "audit for UDL"
- "My course is all reading and video with no interaction" / "add multiple means of expression"
- "Universal design" / "learner agency" / "multiple means of representation"
- even when the user does not say "UDL" — if they question whether a page reaches diverse learners or relies on a single medium/response type, this is the skill

**When NOT to use (with redirect):**
- Page readability / "wall of text" / chunking → `hax-content-chunking-audit` (page-scope cognitive load)
- Unit alignment / "are assessments aligned" / backward design → `hax-ubd-unit-audit` (UbD owns alignment)
- DDD/CSS compliance → `hax-design-system`
- Component accessibility audit (ARIA, keyboard) → `hax-webcomponent-dev`
- Cognitive level of an objective/assessment → `grad-blooms`

## Scope: this skill is READ-ONLY

This skill **diagnoses and recommends only**. It produces a report. To apply remediation, hand off
to the related skills and the `hax` CLI / `hax-claudehax` (see "Implementing the Recommendations"
below). Never edit `pages/*.html` or `site.json` from within this skill.

## Inputs

- a HAX site path: `site.json` (JOS tree) + `pages/*.html`, **or** a single page (JOS node / raw HTML / markdown)
- optional `pageSlug` to scope the audit to one page (else audit the page and report site-level patterns if a site path is given)
- optional `unitSlug` — if the unit has already been through `hax-ubd-unit-audit`, note it and apply the overlap rule (see below)

## Methodology

1. **Ingest structure.** Locate the page(s) in the HAX project:
   - `site.json` (JSON Outline Schema) for the node tree + metadata
   - `pages/<slug>.html` for rendered page content (the canonical content source)
   - a JOS items export or markdown representation for offline review
2. **Inventory blocks present.** Parse every custom element on the page(s) and bucket each under
   the UDL guideline it supports, using `references/udl-element-map.md` as the single source of
   truth. Record counts per guideline.
3. **Find the gaps.** For each of the nine guidelines, mark **Present** / **Thin** / **Absent**:
   - Action & Expression (G4–G6): flag as Absent/Thin unless there is at least one interactive
     response block (`multiple-choice`, `self-check`, `fill-in-the-blanks`, `short-answer-question`,
     etc.) — this is the empirically-confirmed gap, so hold it to a higher bar.
   - Representation (G1–G3): usually Present in HAX courses (media-rich); flag only when a page is
     text-only with no alternate format, or when math/notation lacks `lrn-math`.
   - Engagement (G7–G9): flag when there is no visible goal/objective (`oer-schema`/`stop-note`),
     no reflection prompt (`stop-note`/`self-check`), or no relevance signal (`simple-cta`).
4. **Map each gap to a real HAX remediation.** Use `references/udl-element-map.md`. Never invent
   tag names and never recommend a legacy/third-party tag (`instruction-card`, `lrndesign-sidenote`,
   `link-preview`, `model-viewer` are excluded — use the documented gap handling).
5. **Apply the UbD overlap rule.** If the unit was already audited/designed with the UbD family, do
   NOT re-report Stage 2 alignment as a UDL finding — defer alignment to `hax-ubd-unit-audit` and
   limit the UDL report to the *variety of means* lens (multiple response methods? graduated
   practice? progress monitoring?).
6. **Determine the overall rating:** Accessible (all three principles represented) | Partial (one
   principle thin/absent) | Single-Path (Action & Expression absent — the common HAX-course
   failure mode).
7. **Emit the report** (format below), then an "Implementation handoff" block.

## Expected Output Format

Format findings exactly like this structure (mirrors `hax-ubd-unit-audit`):

```
### 📊 UDL 3.0 Diagnostics
* **UDL Rating:** [Accessible / Partial / Single-Path]
* **Primary Gap:** [the worst offender — one sentence, e.g. "Action & Expression absent: no interactive response blocks on the page."]

### 🔍 Findings & HAX Remediation

* **{node / page / site-level} — {Principle: Guideline}**
  * **Status:** [Present / Thin / Absent]
  * **Issue:** [what is missing or thin, with the measured signal, e.g. "0 interactive response blocks; page is video + text only."]
  * **UDL Rationale:** [why this limits learner agency — cite the guideline]
  * **HAX Remediation:** [real component(s) + concrete placement, e.g. "Add a `multiple-choice` or `short-answer-question` check-in after the video; add `self-check` for progress monitoring (G6.4)."]

* **{node / page / site-level} — {Principle: Guideline}**
  * ...

### 🛠 Implementation handoff
* [one-line action per fix → skill/CLI, e.g. "Add a `short-answer-question` check-in to <page> via `/hax add a check-for-understanding section to <page>`."]
```

## Worked example

**Input** — a HAX page that is a 14-minute `video-player` followed by 400 words of prose and a
`stop-note` reflection prompt. No interactive blocks.

**Output:**
```
### 📊 UDL 3.0 Diagnostics
* **UDL Rating:** Single-Path
* **Primary Gap:** Action & Expression absent — the only learner action is reading/watching; there
  is no way to respond, practice, or monitor progress.

### 🔍 Findings & HAX Remediation

* **Page "efficient-deep-dive" — Action & Expression: G4 Interaction**
  * **Status:** Absent
  * **Issue:** 0 interactive response blocks. Page is `video-player` (14:30) + prose + one
    `stop-note`. No `multiple-choice`, `self-check`, `short-answer-question`, or `fill-in-the-blanks`.
  * **UDL Rationale:** G4.1 asks to vary and honor the methods for response. A single medium
    (watch + read) excludes learners who demonstrate understanding through response, and offers no
    retrieval practice.
  * **HAX Remediation:** Insert a `multiple-choice` or `short-answer-question` check-in after the
    video. Add a `self-check` for ungraded self-monitoring (G6.4).

* **Page "efficient-deep-dive" — Action & Expression: G5 Expression & Communication**
  * **Status:** Absent
  * **Issue:** No constructed-response or composition tool. The `stop-note` invites reflection but
    captures no learner artifact.
  * **UDL Rationale:** G5.2 asks for multiple tools for construction/composition. Reflection without
    a way to produce/record it limits expression to learners who process internally.
  * **HAX Remediation:** Pair the `stop-note` with a `simple-fields` constructed-response prompt so
    learners can record their reflection.

* **Page "efficient-deep-dive" — Representation: G1 Perception**
  * **Status:** Present (video + text). No concern — multiple media present.
  * **Issue:** None.
  * **UDL Rationale:** G1.2 satisfied via `video-player` + prose.
  * **HAX Remediation:** None.

* **Page "efficient-deep-dive" — Engagement: G9 Emotional Capacity**
  * **Status:** Present (thin). One `stop-note` reflection prompt exists.
  * **Issue:** Reflection is present but singular; no metacognitive self-assessment.
  * **UDL Rationale:** G9.3 asks for individual reflection; `self-check` would strengthen it.
  * **HAX Remediation:** Convert or add a `self-check` alongside the `stop-note`.

### 🛠 Implementation handoff
* G4: add a `short-answer-question` check-in after the video — `/hax add a check-for-understanding section to efficient-deep-dive using HAX web components, not plain HTML`.
* G5: pair the `stop-note` with a `simple-fields` reflection capture.
* G9: add a `self-check` for metacognitive self-assessment.
* Confirm the check-in's cognitive level via `grad-blooms`; apply DDD tokens via `hax-design-system`.
```

## Implementing the Recommendations

This audit is the diagnosis step. Apply fixes with the related skills and the `hax` CLI:

- **`hax-claudehax`** — insert components/sections into existing pages:
  - `/hax add an interactive check-for-understanding section to <page> using HAX web components, not plain HTML`
  - `/hax add a multiple choice quiz to this page based on the page content`
  - `/hax add 5 flash cards to <page> using the best HAX web component`
- **`hax-site-building`** — owns page structure; edit page *content* at `pages/<slug>.html`; add pages via `hax site node:add --title "<t>" --slug "<s>" --content <path> --format html --y --no-i` (single) or `hax site site:items-import --items-import <items.json> --y --no-i` (bulk); verify with `hax site site:items`. Never hand-edit `site.json`.
- **`hax-design-system`** — DDD tokens for spacing, color, icon sizing on any inserted component.
- **`grad-blooms`** — when a check-in/quiz is recommended, confirm its cognitive level matches the learning objective (don't insert a Remember-level quiz where an Apply prompt is needed).
- **`hax-ubd-unit-audit` / `hax-ubd-backward-design`** — when the gap is really alignment (assessments don't match goals), defer to the UbD family rather than treating it as a UDL variety issue.

**CLI rules (from PRAW RULES.md):**
- Use the local/global `hax` command — **not** `npx hax` (resolves to a different package).
- When scripting, pass automation flags to avoid prompts/new windows: `--y --no-i` (add `--auto` / `--quiet` / `--skip` as needed).
- Never hand-edit `site.json` for structure — use the CLI.
- `a11y-collapse` MUST set `heading-button`.
- Audio via `media-playlist` + `audio-player`; inputs via `simple-fields`; tables via `editable-table` / `editable-table-display`; educational elements get `oer-schema` metadata.

## Acceptance criteria (for the audit report itself)

- Every finding names the UDL principle + guideline (e.g. "Action & Expression: G4 Interaction") and a real HAX component that fixes it.
- No recommended component is outside the verified registry in `references/udl-element-map.md`.
- The rating is one of: Accessible / Partial / Single-Path.
- Action & Expression (G4–G6) is never marked "Present" when the page has zero interactive response blocks — this is the common false-negative and the empirically-confirmed gap.
- The UbD overlap rule is applied when the unit has a UbD manifest or was already UbD-audited.

## Gotchas

- **A page can be media-rich and still be Single-Path.** Lots of `video-player` + `media-image` satisfies Representation but says nothing about Action & Expression. Do not rate a media-heavy page as Accessible just because it looks good — check whether the learner can *respond*.
- **`stop-note` is reflection, not response.** A `stop-note` supports Engagement (G9.3) but does not by itself satisfy Action & Expression (G4/G5) because it captures no learner artifact. Pair it with `self-check` or `simple-fields`.
- **UDL is not a checklist; do not over-flag.** A page does not need every guideline to be Accessible. Flag genuine gaps that limit reach, not the absence of every possible block. A focused page with one strong response path can be Accessible.
- **Do not double-report UbD findings.** Alignment (Stage 1↔2↔3) is the UbD skill's job; UDL owns variety of means. If a gap is really "the quiz doesn't assess the understanding," send it to `hax-ubd-unit-audit`, don't file it as UDL.
- **Never recommend legacy/third-party tags.** `instruction-card`, `lrndesign-sidenote`, `link-preview`, and `model-viewer` appear in older courses but are not in the current monorepo — use the gap-handling alternatives in the element map.
- **Don't edit here.** This skill emits a report. Structural edits belong to the `hax` CLI via `hax-site-building`; content edits belong in `pages/<slug>.html` or via `hax-claudehax`.

## Dependencies

- **Reads:** `site.json` / `pages/*.html` / a single JOS node / raw HTML / markdown
- **Consults:** `references/udl-element-map.md` (the verified component map — single source of truth)
- **Hands off to:** `hax-claudehax` / `hax-site-building` (insertion), `hax-design-system` (DDD tokens), `grad-blooms` (cognitive level)
- **Defers to:** `hax-ubd-unit-audit` / `hax-ubd-backward-design` (alignment), `hax-content-chunking-audit` (page-scope load), `hax-webcomponent-dev` (component accessibility)

## References

- `references/udl-element-map.md` — UDL → HAX component map (verified tags only; the iron rule)
- `../hax-ubd-backward-design/references/ubd-element-map.md` — companion UbD map (Stage 2 evidence overlap)
- PRAW RULES.md: `~/Documents/git/haxtheweb/praw/RULES.md`
- CAST (2024). *Universal Design for Learning Guidelines version 3.0*. https://udlguidelines.cast.org/
