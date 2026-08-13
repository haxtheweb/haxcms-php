---
name: hax-ubd-unit-audit
description: >
  READ-ONLY diagnostic: review an existing HAX site/unit against Understanding by
  Design standards and emit a report with actionable HAX remediation. Use when the
  user says "review this unit for understanding", "is my course backward-designed",
  "audit my HAX site against UbD", "are my assessments aligned", or "does this unit
  have big ideas" — even if they don't say "UbD". Diagnoses only; hands off to the
  stage skills and hax-claudehax for remediation.
version: 1.0.0
license: MIT
metadata:
  author: PRAW
  tags: [instructional-design, understanding-by-design, ubd, audit, alignment, hax, diagnostic]
  requirements: "A HAX site path (site.json + pages/*.html), optionally a files/ubd/*.manifest.json. Emits a report; does NOT edit pages or site.json."
---

# HAX UbD Unit Audit (Read-Only Diagnostic)

Review an existing HAX site/unit against the UbD **design standards** and emit a
diagnostic + remediation report. This skill **diagnoses and recommends only** —
it does not edit pages, mutate `site.json`, or insert components. It mirrors the
`hax-content-chunking-audit` pattern (page-scope load) but operates at
**unit scope** for backward-design alignment.

## When to Use

**Trigger conditions:**
- "Review this unit for understanding" / "is my course backward-designed"
- "Audit my HAX site against UbD" / "are my assessments aligned"
- "Does this unit have big ideas" / "why does this unit feel like coverage"
- even when the user does not say "UbD" — if they question a unit's intellectual coherence or assessment alignment, this is the skill

**When NOT to use (with redirect):**
- Page readability / "wall of text" / chunking → `hax-content-chunking-audit` (page scope)
- DDD/CSS compliance → `hax-design-system`
- Component accessibility audit → `hax-webcomponent-dev`
- Designing/redesigning a unit (not just auditing) → `hax-ubd-backward-design`

## Scope: this skill is READ-ONLY

This skill **diagnoses and recommends only**. It produces a report. To actually
apply remediation, hand off to the UbD stage skills and the `hax` CLI / `hax-claudehax`
(see "Implementing the Recommendations" below). Never edit `pages/*.html` or
`site.json` from within this skill.

## Inputs

- a HAX site path: `site.json` (JOS tree) + `pages/*.html` + optional `files/ubd/*.manifest.json`
- optional `unitSlug` to scope the audit to one unit node (else audit the top-level course structure)

## Methodology

1. **Ingest structure.** Locate the unit in the HAX project:
   - `site.json` (JSON Outline Schema) for the node tree + metadata
   - `pages/<slug>.html` for rendered page content (the canonical content source)
   - `files/ubd/<unit-slug>.manifest.json` if the unit was designed with the UbD skills (else reconstruct a *partial* manifest from the live site for alignment checking)
2. **Run the 12 UbD design-standards checks** (`../hax-ubd-backward-design/references/design-standards.md`) across the unit:
   - Big-idea focus present, visible, transferable?
   - Enduring understandings proposition-shaped (not topics/facts)?
   - Overarching + topical essential questions present and genuinely open?
   - Predictable misunderstandings anticipated AND addressed (pre-assess + confront)?
   - Assessment aligned to goals (Stage 1 ↔ Stage 2)?
   - ≥1 GRASPS performance task? Problem-not-exercise?
   - Six-facet evidence breadth? Rubric criteria facet-mapped?
   - Validity self-test pass (run it on each assessment — `../hax-ubd-backward-design/references/validity-self-test.md`)?
   - Reliability: multiple measures over time?
   - WHERETO sequence present? Exhibit references a real Stage 2 task?
   - Uncoverage vs coverage?
   - Cross-stage triangulation (Stage 1 ↔ 2 ↔ 3 coherent)?
3. **Map each finding to a real HAX remediation component.** Use the consolidated map (`../hax-ubd-backward-design/references/ubd-element-map.md`). Never invent tag names.
4. **Determine the overall rating:** Aligned | Partial | Activity-oriented | Coverage-bound (the twin sins are named diagnoses, not vague grades).
5. **Emit the report** (format below), then an "Implementation handoff" block pointing at the skill/CLI that applies each fix.

## Expected Output Format

Format findings exactly like this structure (mirrors `hax-content-chunking-audit`):

```
### 📊 UbD Design Diagnostics
* **Backward-Design Rating:** [Aligned / Partial / Activity-oriented / Coverage-bound]
* **Primary Defect:** [the worst offender — one sentence]

### 🔍 Findings & HAX Remediation

* **{node / page / unit-level}**
  * **UbD Standard Violated:** [standard # + name]
  * **Issue:** [e.g., Goals present but no enduring understandings — only topic nouns]
  * **UbD Rationale:** [why this defeats understanding — cite the twin sin or alignment failure]
  * **HAX Remediation:** [real component + concrete placement, e.g. "Add a `stop-note` at the top of pages/<slug>.html carrying the essential question; add `oer-schema` learningOutcome propositions."]

* **{node / page / unit-level}**
  * ...

### 🛠 Implementation handoff
* [one-line action per fix → skill/CLI, e.g. "Rebuild Stage 2 from Stage 1 via `hax-ubd-stage2`."]
```

## Worked example

**Input** — a HAX unit node "Westward Expansion" with three child pages: a long
text page, a vocabulary word-search page, and a 10-item `multiple-choice` quiz.
No manifest present.

**Output:**
```
### 📊 UbD Design Diagnostics
* **Backward-Design Rating:** Activity-oriented
* **Primary Defect:** No enduring understandings or essential questions; the only
  evidence is a selected-response quiz that cannot reveal understanding.

### 🔍 Findings & HAX Remediation

* **Unit-level (site.json node "westward-expansion")**
  * **UbD Standard Violated:** #1 Big-idea focus; #2 Enduring understandings; #3 Essential questions
  * **Issue:** No big ideas, understandings, or EQs anywhere in the unit. Pages are
    activity + content only.
  * **UbD Rationale:** Activity-oriented design (twin sin #1) — "hands-on without
    being minds-on." Without explicit priorities, students cannot answer "what's
    the point?" and learning is by osmosis.
  * **HAX Remediation:** Run `hax-ubd-stage1` to derive big ideas + understandings
    + EQs from the topic. Then add a `stop-note` carrying the topical EQ to the top
    of each child page, and `oer-schema` `learningOutcome` propositions to page
    metadata.

* **Page "westward-quiz" (multiple-choice only)**
  * **UbD Standard Violated:** #6 GRASPS task; #7 facet breadth; #8 validity
  * **Issue:** The only evidence is a `multiple-choice` quiz. No performance task.
  * **UbD Rationale:** Selected response is "insufficient and sometimes misleading"
    for understanding. It can show recall, not transfer. Fails validity self-test
    Q2 (parroting) and Q1 (guessing).
  * **HAX Remediation:** Add ≥1 GRASPS performance task page via `hax-ubd-stage2`
    (authentic context via `video-player`/`image-compare-slider`; product via
    `simple-fields`; rubric via `editable-table`). Keep the quiz as OE for K/S only.

* **Page "westward-vocab" (word search)**
  * **UbD Standard Violated:** #12 triangulation
  * **Issue:** Word search is an orphan activity — equips for no Stage 2 performance.
  * **UbD Rationale:** Activity not derived from goals/evidence — the activity-oriented sin.
  * **HAX Remediation:** Drop or repurpose as an Explore/Equip (`flash-card` for
    vocab retrieval) that explicitly equips for the GRASPS task.

### 🛠 Implementation handoff
* Unit-level: run `hax-ubd-stage1` then `hax-ubd-stage2` to rebuild goals + evidence.
* Quiz page: add a GRASPS task page via `hax-ubd-stage2`; keep quiz as OE only.
* Vocab page: replace with `flash-card` Explore/Equip, or drop.
* Apply DDD tokens via `hax-design-system`; insert via `hax-claudehax` / `hax-site-building`.
```

## Implementing the Recommendations

This audit is the diagnosis step. Apply fixes with the related skills and the `hax` CLI:

- **`hax-ubd-backward-design`** — full redesign when the rating is Activity-oriented or Coverage-bound.
- **`hax-ubd-stage1` / `hax-ubd-stage2` / `hax-ubd-stage3`** — targeted rebuild of the failing stage(s) when the rating is Partial.
- **`hax-claudehax`** — insert components/sections into existing pages (e.g. `/hax add a stop-note carrying the essential question to <page>`).
- **`hax-site-building`** — owns page structure; edit page *content* at `pages/<slug>.html`; add pages via `hax site node:add` / `site:items-import` (never hand-edit `site.json`).
- **`hax-design-system`** — DDD tokens for spacing, color, icon sizing on any inserted component.
- **`grad-blooms`** — when a check-in/quiz is retained, confirm its cognitive level matches the learning objective.

**CLI rules (from PRAW RULES.md):**
- Use the local/global `hax` command — **not** `npx hax`.
- When scripting, pass automation flags: `--y --no-i --auto` (add `--quiet` / `--skip` as needed).
- Never hand-edit `site.json` for structure — use the CLI.
- `a11y-collapse` MUST set `heading-button`.
- Audio via `media-playlist` + `audio-player`; inputs via `simple-fields`; tables via `editable-table`; educational elements get `oer-schema` metadata.

## Acceptance criteria (for the audit report itself)

- Every finding cites the UbD standard # it violates and a real HAX component that fixes it.
- The rating is one of: Aligned / Partial / Activity-oriented / Coverage-bound.
- If a manifest is present, `validation.*` fields are updated from this audit run.
- No recommended component is outside the verified registry.

## Gotchas

- **A site can have many `multiple-choice` and still be 0% evidence of *understanding*.** Selected response alone is insufficient — do not rate a quiz-heavy unit as Aligned.
- **Alignment is the failure mode.** A fun, rich, well-chunked unit can still be Activity-oriented if Stage 2 doesn't align to Stage 1. Don't be fooled by production quality.
- **"Coverage" is negative; "survey"/"overview" is legitimate.** Do not flag a purposeful, transparent overview as coverage-bound. Coverage is content march with *no overarching intellectual purpose*.
- **Don't edit here.** This skill emits a report. Structural edits belong to the `hax` CLI via `hax-site-building`; content edits belong in `pages/<slug>.html` or via `hax-claudehax`; redesign belongs to the UbD stage skills.
- **A missing manifest is a finding, not a blocker.** Reconstruct a partial manifest from the live site to check alignment, and note "no manifest — designed without UbD skills" as context.

## Dependencies

- **Reads:** `site.json` / `pages/*.html` / `files/ubd/*.manifest.json`
- **Hands off to:** `hax-ubd-backward-design` (redesign) or `hax-ubd-stage1/2/3` (targeted fixes) + `hax-claudehax` / `hax-site-building` (insertion)
- **Consults:** `grad-blooms`, `hax-content-chunking-audit` (page scope), `hax-design-system`

## References

- `../hax-ubd-backward-design/references/design-standards.md` — the 12 standards + rating → diagnosis mapping (the core checklist)
- `../hax-ubd-backward-design/references/ubd-element-map.md` — remediation component map (real tags only)
- `../hax-ubd-backward-design/references/validity-self-test.md` — run on each assessment
- `../hax-ubd-backward-design/references/manifest-schema.md` — for reconstructing/validating a partial manifest
- PRAW RULES.md: `~/Documents/git/haxtheweb/praw/RULES.md`
- Source: Wiggins & McTighe (2005), *UbD* — design standards (Appendix) + Fig 8.5.
