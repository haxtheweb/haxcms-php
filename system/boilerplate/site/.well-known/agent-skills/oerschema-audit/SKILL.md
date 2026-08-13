---
name: oerschema-audit
description: >
  READ-ONLY diagnostic: review authored HAX page HTML, site.json outline nodes, VitePress
  markdown directives, JSON-LD blocks, and Google Docs export HTML against the OER Schema
  vocabulary (Course/Unit/Module/Lesson, LearningObjective, Assessment/Quiz/Activity/Project,
  Task/Practice, Rubric family, SupportingMaterial, TableOfContents, ActionType verbs) and emit a
  report with the recommended class, properties, and markup surface for every finding. Use when the
  user says "audit this page for OER schema", "what schema should this lesson have", "is this quiz
  marked up as the right oer class", "does my site.json map to oerschema", "check my VitePress
  directives against the vocabulary", "scan my site for content missing oerschema", or
  "which of these pages need oer schema" — even if they don't say "audit" or "oerschema". Diagnoses
  only; hands off to hax-claudehax / hax-site-building to apply remediation and to
  oerschema-integration-finder when the gap is in a component (code), not the content.
version: 1.0.0
license: MIT
metadata:
  author: PRAW
  tags: [oer-schema, oerschema, schema, metadata, audit, diagnostic, pedagogy, instructional-design, microdata, json-ld, vitepress, hax]
  requirements: "A HAX site path (site.json + pages/*.html), a single page (JOS node / raw HTML / markdown), a VitePress markdown file, a JSON-LD block, or Docs export HTML. Emits a report; does NOT edit pages, site.json, or source files."
---

# OER Schema Audit (Read-Only Diagnostic)

Review authored content on an existing HAX site/page (or VitePress / JSON-LD / Docs-export content)
against the **OER Schema vocabulary** and emit a diagnostic + remediation report. This skill
**diagnoses and recommends only** — it does not edit pages, mutate `site.json`, or insert markup.
It mirrors the `hax-a11y-audit` / `hax-udl-audit` pattern but operates through the OER Schema lens:
does the authored content carry the correct *semantic pedagogical structure* that makes it
discoverable and interoperable as an open educational resource?

## The worldview in one paragraph

OER Schema is a vocabulary that extends schema.org with education-specific terms (Course,
LearningObjective, Assessment, Rubric, etc.) so educational content is machine-readable and
interoperable. The HAX ecosystem commits to applying OER Schema metadata to educational elements,
and the UbD skill family already produces OER-schema-tagged unit skeletons — but no existing skill
audits *authored content* to see whether the schema is present and correct. This skill fills that
gap. It is distinct from **a11y** (technical access for people with disabilities), from **UDL**
(pedagogical inclusivity), from **content chunking** (cognitive load), and from **UbD unit audit**
(instructional-design alignment). It is also distinct from **component-internal schema support**
(an element's own code not emitting microdata), which `oerschema-integration-finder` owns. This
audit reports what is missing or mis-classified, cites the class/property, and recommends only real
remediation in the right markup surface — never an invented tag or property.

## When to Use

**Trigger conditions:**
- "Audit this page for OER schema" / "what schema should this lesson have"
- "Is this quiz marked up as the right OER class" / "does my site.json map to oerschema"
- "Check my VitePress directives against the vocabulary" / "is this JSON-LD valid OER schema"
- "Scan my site for content missing oerschema" / "which of these pages need OER schema"
- "What OER class should I use for a group project" (when the user is reviewing authored content)
- even when the user does not say "OER schema" or "audit" — if they question whether authored
  educational content carries the right semantic metadata, this is the skill

**When NOT to use (with redirect):**
- A web component's own code doesn't emit schema (the gap is in the element, not the page) →
  `oerschema-integration-finder`
- Technical WCAG access of the content → `hax-a11y-audit`
- Pedagogical inclusivity / multiple means → `hax-udl-audit`
- Page-scope cognitive load / chunking → `hax-content-chunking-audit`
- Unit alignment / backward design / are assessments aligned → `hax-ubd-unit-audit`
- Cognitive level of an objective/assessment (Bloom's) → `grad-blooms`
- Editing the OER Schema vocabulary itself (`schema.ts`) → `oerschema-schema-author` (repo-local)
- Validating `schema.ts` internal consistency → `oerschema-validation` (repo-local)

## Scope: this skill is READ-ONLY

This skill **diagnoses and recommends only**. It produces a report. To apply remediation, hand off
to the related skills and the `hax` CLI / `hax-claudehax` (see "Implementing the Recommendations"
below). Never edit `pages/*.html`, `site.json`, VitePress markdown, or source files from within
this skill.

## Inputs

- a HAX site path: `site.json` (JOS tree) + `pages/*.html`, **or** a single page (JOS node / raw HTML / markdown)
- **or** a VitePress markdown file using `::: learning-objective` / `::: assessment` / `::: rubric` / `::: practice` / `::: instructional-pattern` directives
- **or** a JSON-LD block (a `<script type="application/ld+json">` or a standalone `.jsonld`)
- **or** Google Docs export HTML (the `itemscope itemtype="http://oerschema.org/..."` microdata the Apps Script add-on emits)
- optional `pageSlug` to scope the audit to one page (else audit every page and report site-level patterns)

## Methodology

1. **Ingest structure.** Locate the content and identify which markup surface(s) are present:
   - HAX: `site.json` (JOS tree + metadata) + `pages/<slug>.html` (rendered content)
   - VitePress: `:::` container directives (see `references/markup-surfaces.md`)
   - JSON-LD: `<script type="application/ld+json">` blocks
   - Docs export: `itemscope`/`itemtype`/`itemprop` microdata
2. **Load the vocabulary.** Consult `references/vocabulary.md` for the class hierarchy and the
   property domain/range tables (derived from `schema.ts` v1.2.0). This is the single source of
   truth for what classes and properties exist and where each property is valid.
3. **Run the OER Schema checks across authored content.** For each page/block, evaluate:
   - **Presence:** pedagogical structures are present in the content but carry no OER Schema
     markup at all. A lesson page with heading "Lesson 3", a quiz section, learning objectives
     stated in prose, and a rubric table — but no `itemscope`/`itemtype`/`itemprop` or directives —
     is a presence gap. Identify the *most specific* class for each structure (see mapping refs).
   - **Classification:** schema is present but the class is wrong or too generic. A quiz tagged
     `itemtype="http://oerschema.org/Task"` should be `Assessment` (or `Quiz`, which subclasses
     `Assessment`). A unit page tagged `LearningComponent` could be the more specific
     `InstructionalPattern` → `Unit`. Report the current type and the recommended type.
   - **Property/domain validity:** a property is used on a class outside its `domain`. `skill` is
     domain `LearningObjective` — using `itemprop="skill"` on an `Assessment` is invalid.
     `hasLearningObjective` is domain `InstructionalPattern` — using it on a bare `Task` is invalid
     (a `Task` is an `InstructionalPattern` subclass, so it *is* valid via inheritance — use
     `references/vocabulary.md` and the inheritance rule before flagging). Report the property,
     the class it was used on, and the declared domain.
   - **Range validity:** a property's value type doesn't match its `range`. `gradingFormat` range
     is `GradeFormat | Text` — a raw number on `gradingFormat` should be a `PointGradeFormat` or
     text. `assessing` range is `Activity` — pointing `assessing` at a `Quiz` is invalid (`Quiz`
     is an `Assessment`, not an `Activity`). Report the property, the value, and the declared range.
   - **subClassOf chain:** a declared `itemtype` doesn't resolve. `itemtype="http://oerschema.org/Foo"`
     where `Foo` is not a class in the vocabulary. Report the unresolved type.
   - **Inverse consistency:** an inverse pair is used one-directionally without its partner, or a
     relationship that should be reciprocal is dangling (e.g. `forComponent` without a matching
     `hasComponent` on the parent). Note: `forComponent`/`hasComponent` declare themselves inverses
     in their comments but do not set the `inverseOf` field in `schema.ts` — flag the *content*
     gap (missing reciprocal link), not the vocabulary gap (which is `oerschema-validation`'s job).
   - **Rubric family integrity:** a `Rubric` should have `hasCriterion` → `RubricCriterion` and
     `rubricScale` → `RubricScale` → `hasLevel` → `RubricLevel`. A rubric directive with levels but
     no scale, or criteria with no `criterionWeight`, is a structural gap.
   - **ActionType usage:** a `Task`/`Practice`/`Activity` with a `typeOfAction` value that is not
     one of the `ActionType` subclasses (Reading, Writing, Making, Researching, Listening, Watching,
     Reflecting, Discussing, Observing, Presenting, Assess). Report the invalid action value.
4. **Map each finding to a concrete remediation in the right surface.** Use
   `references/markup-surfaces.md` to pick the surface the content is already in (microdata for
   HAX pages / Docs export, `:::` directives for VitePress, JSON-LD for structured-data blocks).
   Use `references/hax-outline-mapping.md` for site.json → class mapping and
   `references/ubd-mapping.md` when the content originated from a UbD unit. Every recommendation
   names the exact class, the exact properties, and the exact surface — so the post-audit
   conversation can apply it directly without re-deriving.
5. **Apply the overlap / defer rules:**
   - **Component-internal schema gap:** if the reason content lacks schema is that the rendering
     element doesn't emit microdata (e.g. a `quiz-element` with no `itemtype`), defer to
     `oerschema-integration-finder` (code fix), not this skill (content fix).
   - **Vocabulary bug:** if the schema is wrong because `schema.ts` itself has a broken
     `subClassOf` or missing `inverseOf`, defer to `oerschema-validation` / `oerschema-schema-author`.
   - **Pedagogical alignment:** if the issue is that the objectives/assessments don't align with
     desired understandings (not a schema problem), defer to `hax-ubd-unit-audit`.
   - **Cognitive level:** if the issue is the Bloom's level of an objective, defer to `grad-blooms`.
6. **Determine the overall rating:**
   - **Covered:** all pedagogical structures carry correct, domain/range-valid schema. Minor
     advisories (e.g. "consider the more specific `Quiz` over generic `Assessment`") may be noted
     but do not affect the rating.
   - **Partial:** some structures lack schema, or some properties are mis-classified, but the page
     has a recognizable semantic skeleton. Remediation is surgical.
   - **Uncovered:** a pedagogically dense page (lessons, objectives, assessments, rubrics) with
     zero OER Schema markup, or a structural break (every `itemtype` unresolved, or a rubric with
     no criteria/scale).
7. **Emit the report** (format below), then an "Implementation handoff" block.

## Expected Output Format

Format findings exactly like this structure (mirrors `hax-a11y-audit` / `hax-udl-audit`):

```
### 📊 OER Schema Diagnostics
* **Coverage Rating:** [Covered / Partial / Uncovered]
* **Primary Gap:** [the worst offender — one sentence, e.g. "Lesson 3 page has learning objectives, a quiz, and a rubric in prose but zero OER Schema markup."]

### 🔍 Findings & Remediation

* **{page slug / block / site-level} — {check name}**
  * **Status:** [Fail / Partial / Pass-with-advisory]
  * **Issue:** [the exact failing markup, e.g. "Quiz section uses itemtype='http://oerschema.org/Task' but a quiz is an Assessment (specifically a Quiz subclass)."]
  * **OER Schema Rationale:** [cite the class/property, e.g. "Quiz subClassOf Assessment subClassOf InstructionalPattern; Task is a sibling of Assessment under InstructionalPattern, not a parent of Quiz."]
  * **Recommended markup:** [exact class + properties + surface, e.g. "Use itemtype='http://oerschema.org/Quiz' with itemprop='gradingFormat' (range GradeFormat|Text) and itemprop='assessing' (range Activity) pointing at the activity being assessed. Surface: microdata in pages/lesson-3.html."]

* **{page slug / block / site-level} — {check name}**
  * ...

### 🛠 Implementation handoff
* [one-line action per fix → skill/CLI + surface, e.g. "Add itemscope itemtype=Quiz to the quiz section in pages/lesson-3.html via direct edit or /hax."]
```

## Worked example

**Input** — a HAX page (`pages/lesson-3.html`) containing:
```html
<h2>Lesson 3: Photosynthesis</h2>
<p>Students will be able to explain the process of photosynthesis.</p>
<div class="quiz">
  <h3>Quick Check</h3>
  <ol><li>What are the reactants of photosynthesis?</li></ol>
</div>
<table class="rubric">
  <tr><th>Criterion</th><th>Level 4</th><th>Level 1</th></tr>
  <tr><td>Explanation accuracy</td><td>Complete</td><td>Fragmentary</td></tr>
</table>
```

**Output:**
```
### 📊 OER Schema Diagnostics
* **Coverage Rating:** Uncovered
* **Primary Gap:** Lesson 3 page has a learning objective, a quiz, and a rubric in prose/HTML but
  zero OER Schema markup — none of the pedagogical structures are machine-readable.

### 🔍 Findings & Remediation

* **Page "lesson-3" — Presence (lesson + objective)**
  * **Status:** Fail
  * **Issue:** The page is a Lesson with a stated learning objective, but neither is tagged.
    `h2 "Lesson 3: Photosynthesis"` implies `Lesson`; the objective paragraph implies
    `LearningObjective`.
  * **OER Schema Rationale:** `Lesson` subClassOf `InstructionalPattern` subClassOf
    `LearningComponent`; `hasLearningObjective` (domain `InstructionalPattern`, range
    `LearningObjective`) relates them. Without markup the lesson is not discoverable as an OER.
  * **Recommended markup:** Wrap the lesson in
    `<div itemscope itemtype="http://oerschema.org/Lesson">`; wrap the objective in
    `<div itemprop="hasLearningObjective" itemscope itemtype="http://oerschema.org/LearningObjective">`
    with `<meta itemprop="skill" content="explain the process of photosynthesis">` and
    `<meta itemprop="description" content="...">`. Surface: microdata in pages/lesson-3.html.

* **Page "lesson-3" — Presence (quiz)**
  * **Status:** Fail
  * **Issue:** The `.quiz` div is an untyped quiz. It should be a `Quiz` (subClassOf `Assessment`).
  * **OER Schema Rationale:** `Quiz` subClassOf `Assessment` subClassOf `InstructionalPattern`.
    `Assessment` carries `assessing` (range `Activity`) and `gradingFormat` (range
    `GradeFormat | Text`).
  * **Recommended markup:** `<div itemscope itemtype="http://oerschema.org/Quiz">` on the quiz div;
    add `<meta itemprop="gradingFormat" content="...">` if points are specified. Surface: microdata
    in pages/lesson-3.html. (If this quiz is rendered by a HAX quiz element that emits no
    microdata, also hand to oerschema-integration-finder for the component-level fix.)

* **Page "lesson-3" — Rubric family integrity**
  * **Status:** Fail
  * **Issue:** The `.rubric` table is a rubric with one criterion and two levels, but no
    `Rubric`/`RubricCriterion`/`RubricScale`/`RubricLevel` markup.
  * **OER Schema Rationale:** `Rubric` has `hasCriterion` → `RubricCriterion` and `rubricScale` →
    `RubricScale` → `hasLevel` → `RubricLevel`. The two-level table maps to a scale with two levels.
  * **Recommended markup:** Wrap in `<div itemscope itemtype="http://oerschema.org/Rubric">` with
    `<meta itemprop="rubricType" content="analytic">`; nest
    `<div itemprop="hasCriterion" itemscope itemtype="http://oerschema.org/RubricCriterion">`; nest
    a `<div itemprop="rubricScale" itemscope itemtype="http://oerschema.org/RubricScale">` with two
    `<div itemprop="hasLevel" itemscope itemtype="http://oerschema.org/RubricLevel">` carrying
    `levelOrdinal` and `levelPoints`. Surface: microdata in pages/lesson-3.html, or convert the
    page to VitePress and use the `::: rubric` / `::: rubric-criterion` / `::: rubric-scale` /
    `::: rubric-level` directives (see references/markup-surfaces.md).

### 🛠 Implementation handoff
* Lesson + objective: add itemscope markup in pages/lesson-3.html (direct edit or /hax).
* Quiz: add itemtype=Quiz to the quiz div (direct edit); if a HAX quiz element renders it, also
  file oerschema-integration-finder for the component to emit Assessment microdata.
* Rubric: add the Rubric family microdata, or migrate to VitePress rubric directives.
```

## Implementing the Recommendations

This audit is the diagnosis step. Apply fixes with the related skills and the `hax` CLI:

- **`hax-claudehax`** — insert/replace schema-tagged components in existing pages:
  - `/hax wrap the quiz in pages/lesson-3 with itemscope itemtype=http://oerschema.org/Quiz`
- **`hax-site-building`** — owns page structure; edit page *content* at `pages/<slug>.html` for
  microdata; add pages via `hax site node:add ... --y --no-i`; never hand-edit `site.json`.
- **`oerschema-integration-finder`** — when the gap is a rendering element not emitting microdata
  (a code gap, not a content gap).
- **`hax-ubd-unit-audit`** — if the real concern is objective/assessment alignment, not schema.
- **`grad-blooms`** — cognitive level of an objective/assessment.

**CLI rules (from PRAW RULES.md):**
- Use the local/global `hax` command — **not** `npx hax` (resolves to a different package).
- When scripting, pass automation flags to avoid prompts/new windows: `--y --no-i`.
- Never hand-edit `site.json` for structure — use the CLI.

## Acceptance criteria (for the audit report itself)

- Every finding names the exact OER Schema class and/or property (from `references/vocabulary.md`)
  and the markup surface to use.
- Every recommended class is a real class in the v1.2.0 vocabulary; every recommended property is
  in the declared `domain` of that class (accounting for `subClassOf` inheritance).
- The rating is one of: Covered / Partial / Uncovered.
- A page whose pedagogical structures all carry correct, domain/range-valid schema is not rated
  Uncovered even if advisories (use a more specific subclass) are present.
- Component-internal schema gaps are handed to `oerschema-integration-finder`, not double-reported
  as content findings.
- Vocabulary-internal bugs (broken `subClassOf`, missing `inverseOf` in `schema.ts`) are handed to
  `oerschema-validation` / `oerschema-schema-author`, not reported as content findings.

## Gotchas

- **Inheritance makes many uses valid that look invalid.** `hasLearningObjective` is domain
  `InstructionalPattern`, but `Lesson`, `Unit`, `Module`, `Assessment`, `Task`, `Activity`,
  `Project`, `Practice` are all `InstructionalPattern` subclasses — so using it on any of them is
  valid. Always walk the `subClassOf` chain in `references/vocabulary.md` before flagging a
  domain violation.
- **Quiz vs. Task vs. Assessment.** `Quiz` and `Task` are siblings (both subclass
  `InstructionalPattern` via different paths: `Quiz`→`Assessment`, `Task` directly). A quiz is NOT
  a Task. `Submission` is the other `Assessment` sibling. Get this right — it is the most common
  mis-classification.
- **`forComponent` / `hasComponent` are inverses by comment, not by the `inverseOf` field.** Do
  not flag this as a *content* error; it is a vocabulary-internal gap for `oerschema-validation`.
  In content, flag a missing *reciprocal link* (a `forComponent` with no matching `hasComponent`
  on the parent) as a structural advisory, not a hard fail.
- **`mainEntityOfPage.inverseOf = "mainEntity"` references an external schema.org property** that
  is not defined in this vocabulary. This is intentional (it aligns to schema.org), not a bug — do
  not flag it.
- **Pick the most specific class.** `itemtype=".../InstructionalPattern"` on a lesson is *valid*
  but `Lesson` is more specific and more discoverable. Report as an advisory, not a fail.
- **Surface matters.** Don't recommend microdata inside a VitePress markdown file — recommend the
  matching `:::` directive. Don't recommend a `:::` directive inside a HAX `pages/*.html` —
  recommend microdata. See `references/markup-surfaces.md`.
- **ActionType values are classes, not strings.** `typeOfAction` range is `ActionType`; the valid
  values are the `ActionType` subclasses (Reading, Writing, Making, etc.), referenced as
  `http://oerschema.org/Reading` etc. A `typeOfAction="reading"` lowercase string is invalid.
- **Don't edit here.** This skill emits a report. Edits belong in `pages/<slug>.html` (via
  `hax-site-building` or direct edit / `hax-claudehax`); structural edits belong to the `hax` CLI.

## Dependencies

- **Reads:** `site.json` / `pages/*.html` / a JOS node / raw HTML / markdown / VitePress `.md` / JSON-LD / Docs export HTML
- **Consults:** `references/vocabulary.md` (class + property tables — single source of truth),
  `references/markup-surfaces.md`, `references/hax-outline-mapping.md`, `references/ubd-mapping.md`
- **Hands off to:** `hax-claudehax` / `hax-site-building` (apply remediation),
  `oerschema-integration-finder` (component-internal schema gaps)
- **Defers to:** `hax-ubd-unit-audit` (alignment), `grad-blooms` (cognitive level),
  `oerschema-validation` / `oerschema-schema-author` (vocabulary-internal bugs)

## References

- `references/vocabulary.md` — OER Schema v1.2.0 class hierarchy + property domain/range tables (the iron rule; verify every recommendation against this)
- `references/markup-surfaces.md` — microdata vs JSON-LD vs VitePress directives vs Docs export, with exact syntax for each class
- `references/hax-outline-mapping.md` — site.json JOS node → OER class + relationship properties
- `references/ubd-mapping.md` — UbD artifacts (EU, GRASPS, WHERETO) → OER classes/properties
- OER Schema vocabulary source: `oerschema` repo `app/lib/schema.ts`
- PRAW RULES.md: `~/Documents/git/haxtheweb/praw/RULES.md`
