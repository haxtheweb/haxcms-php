---
name: oerschema-integration-finder
description: >
  READ-ONLY diagnostic: scan HAX webcomponents, themes, CMS backends (PHP/NodeJS HAXcms), the
  VitePress plugin, and the Google Apps Script add-on for code surfaces that render or consume
  pedagogical content but do NOT emit or consume OER Schema metadata — e.g. quiz/assessment
  elements with no Assessment microdata, outline components not mapping to TableOfContents,
  objective/rubric/syllabus elements with no itemtype. Emit a per-file hit-list of the class +
  property each surface should expose. Use when the user says "which of my webcomponents should
  emit oer schema", "find assessment code without schema support", "where in my codebase should
  oerschema be wired in", "audit my components for oer schema integration opportunities", "does
  the quiz element emit microdata", or "what elements in HAX could expose oer schema but don't" —
  even if they don't say "finder" or "oerschema". Diagnoses only; hands off to hax-webcomponent-dev
  for implementation and to oerschema-audit when the gap is in authored content, not code.
version: 1.0.0
license: MIT
metadata:
  author: PRAW
  tags: [oer-schema, oerschema, schema, metadata, integration, finder, diagnostic, webcomponents, monorepo, microdata, hax]
  requirements: "A codebase path to scan (the webcomponents monorepo, a theme, a CMS backend, the VitePress plugin, or the Google Apps Script add-on). Emits a per-file hit-list; does NOT edit source files."
---

# OER Schema Integration Finder (Read-Only Diagnostic)

Scan HAX ecosystem *code* — webcomponents, themes, CMS backends, the VitePress plugin, and the
Google Apps Script add-on — for surfaces that render or consume pedagogical content but do not emit
or consume OER Schema metadata, and emit a per-file hit-list of integration opportunities. This
skill **diagnoses and recommends only** — it does not edit source. It mirrors the `oerschema-audit`
pattern but operates on *code*, not *authored content*: where `oerschema-audit` asks "does this page
have the right microdata?", this skill asks "does this element *emit* the right microdata?"

## The worldview in one paragraph

OER Schema only lands in authored content if the rendering elements emit it. A HAX quiz element
that renders questions but no `itemscope itemtype="http://oerschema.org/Quiz"` means every page
using that element is silently uncovered — a code gap, not a content gap. The HAX ecosystem has
many elements that render pedagogical structures (quizzes, outlines, objectives, rubrics, media
playlists, syllabi) and several surfaces that transform content (the VitePress plugin, the Apps
Script add-on, the CMS backends). This skill scans those code surfaces for missing schema emission
or consumption and produces a hit-list that `hax-webcomponent-dev` can act on. It is distinct from
`oerschema-audit` (authored content), from `hax-a11y-audit` (accessibility), and from
`hax-webcomponent-dev` (which *implements* the fixes this skill identifies). It does not invent
classes/properties — every recommendation cites a real OER Schema class from the v1.2.0 vocabulary.

## When to Use

**Trigger conditions:**
- "Which of my webcomponents should emit OER schema" / "find assessment code without schema support"
- "Where in my codebase should oerschema be wired in" / "does the quiz element emit microdata"
- "Audit my components for OER schema integration opportunities"
- "What elements in HAX could expose OER schema but don't"
- "Does the VitePress plugin cover all the OER classes" / "does the Google Docs add-on emit rubrics"
- even when the user does not say "finder" or "OER schema" — if they ask which code surfaces should
  carry educational schema metadata, this is the skill

**When NOT to use (with redirect):**
- A specific *page* is missing schema (the gap is in authored content, not the rendering element) →
  `oerschema-audit`
- Implementing the schema emission in a component (writing the LitElement template / haxProperties) →
  `hax-webcomponent-dev`
- The OER Schema vocabulary itself needs new classes/properties → `oerschema-schema-author`
- `schema.ts` internal consistency → `oerschema-validation`
- Accessibility of a component → `hax-a11y-audit` / `hax-webcomponent-dev`

## Scope: this skill is READ-ONLY

This skill **diagnoses and recommends only**. It produces a hit-list. To implement, hand off to
`hax-webcomponent-dev` (for webcomponents), `hax-site-building` (for themes), or the relevant
backend/plugin maintainer. Never edit source files from within this skill.

## Inputs

- A codebase path to scan. Typical targets:
  - the webcomponents monorepo (e.g. `~/Documents/git/haxtheweb/webcomponents`)
  - a single element directory (e.g. `elements/<tag-name>`)
  - a HAXcms theme directory
  - a CMS backend (PHP: `haxcms-php`; NodeJS: `haxcms-nodejs`)
  - the VitePress plugin (`oerschema/vitepress-plugin/`)
  - the Google Apps Script add-on (`oerschema/google-apps-script/`)
- optional scope flag: `--surface=webcomponents|theme|backend|plugin|appsscript` to limit the scan

## Methodology

1. **Identify the scan target and surface.** Determine whether the path is a webcomponent, a theme,
   a backend, the VitePress plugin, or the Apps Script add-on — each has a different schema-emission
   mechanism (see `references/component-patterns.md`).
2. **Load the vocabulary.** Consult `oerschema-audit`'s `references/vocabulary.md` (the v1.2.0 class
   + property tables) as the source of truth for what classes/properties exist. This skill does not
   duplicate that file; it references it.
3. **For webcomponents**, for each element:
   - Read the element's `src/<tag>.js` (LitElement class) and its `render()` / template. Look for
     `itemscope`, `itemtype`, `itemprop` in the template — the microdata emission surface.
   - Read its `haxProperties` / `haxProperties.json` / `HAXSchema` — the HAX editor integration
     surface. Does it expose a schema-relevant `gizmo` type or properties that map to OER classes?
   - Classify the element by what it renders (see `references/component-patterns.md` candidate
     list): assessment/quiz, outline/navigation, objective, rubric, syllabus, media, reading,
     activity. If it renders a pedagogical structure and emits no microdata, it is a hit.
   - If it emits microdata with a wrong/invalid `itemtype` or a property outside its domain, it is a
     mis-classification hit (use the vocabulary + inheritance rule to verify).
4. **For themes**, check whether the theme wraps page content in an OER class wrapper (e.g. a
   `Course`/`Unit` wrapper around the rendered page) or emits a site-level JSON-LD block. A theme
   that renders `pages/*.html` content with no surrounding OER class is a structural hit.
5. **For CMS backends (PHP/NodeJS HAXcms)**, check whether the backend exposes OER Schema in its
   data model / API responses (site.json, page JSON, outline endpoints). A backend that serves
   outline data with no OER class mapping is a hit. Per PRAW rules, only the `siteRoutes api/v1`
   system is in scope for NodeJS — do not flag the legacy `src/routes/*` (v0) endpoints.
6. **For the VitePress plugin**, compare the directives it implements against the OER class
   vocabulary. The plugin currently implements `learning-objective`, `assessment`, `practice`,
   `rubric`, `rubric-criterion`, `rubric-scale`, `rubric-level`, `learning-component`,
   `instructional-pattern`. Missing directives: no dedicated `course`, `unit`, `module`, `lesson`,
   `quiz`, `submission`, `task`, `activity`, `project`, `learning-objective` (present), `syllabus`,
   `topic`. Each missing directive for a class that authors would reasonably use is a plugin hit.
7. **For the Apps Script add-on**, compare the components it can insert (`LearningObjective`,
   `Assessment`, `Practice`) against the vocabulary. Missing: `Quiz`, `Rubric` family, `Lesson`
   wrapper, `Task`/`Activity`. Each missing component is an add-on hit.
8. **For each hit, record:** file path, the element/surface, what it renders, the recommended OER
   class + properties it should emit, and the implementation handoff (`hax-webcomponent-dev` for
   components, `hax-site-building` for themes, the relevant maintainer for backends/plugins).
9. **Apply the overlap / defer rules:**
   - If the gap is actually in *authored content* (the element emits microdata but the page didn't
     use it), defer to `oerschema-audit`.
   - If the element needs *new vocabulary* to emit the right class, defer to `oerschema-schema-author`.
   - If the gap is a component-internal accessibility issue, defer to `hax-a11y-audit` /
     `hax-webcomponent-dev`.
10. **Determine the overall rating:**
    - **Wired:** the scanned surface emits/consumes schema for all pedagogical structures it renders.
    - **Partial:** some pedagogical structures emit schema, others don't; or some emit
      mis-classified schema.
    - **Unwired:** the surface renders pedagogical content but emits zero OER Schema metadata.
11. **Emit the hit-list** (format below), then an "Implementation handoff" block.

## Expected Output Format

```
### 📊 OER Schema Integration Diagnostics
* **Integration Rating:** [Wired / Partial / Unwired]
* **Primary Gap:** [the worst offender — one sentence, e.g. "The quiz element renders assessments but emits no Assessment microdata, so every page using it is uncovered."]

### 🔍 Hit-list & integration recommendations

* **{file path} — {surface}**
  * **Renders:** [what pedagogical structure, e.g. "quiz questions with a points value"]
  * **Schema emitted:** [none / what it currently emits, e.g. "no itemscope/itemtype"]
  * **Recommended:** [exact class + properties to emit, e.g. "itemscope itemtype='http://oerschema.org/Quiz' on the wrapper; itemprop='gradingFormat' (range GradeFormat|Text) for points; itemprop='assessing' (range Activity) if linked to an activity."]
  * **Handoff:** [hax-webcomponent-dev / hax-site-building / plugin maintainer / add-on maintainer]

* **{file path} — {surface}**
  * ...

### 🛠 Implementation handoff
* [one-line action per hit → skill/maintainer, e.g. "Add Assessment microdata to the quiz element's render template (hax-webcomponent-dev)."]
```

## Worked example

**Input** — scan the webcomponents monorepo, focusing on a quiz element at
`elements/quiz-element/src/quiz-element.js` whose `render()` returns questions and a points display
but contains no `itemscope`/`itemtype`/`itemprop`.

**Output:**
```
### 📊 OER Schema Integration Diagnostics
* **Integration Rating:** Unwired
* **Primary Gap:** quiz-element renders assessments (questions + points) but emits no OER Schema
  microdata, so every HAX page using <quiz-element> is silently uncovered.

### 🔍 Hit-list & integration recommendations

* **elements/quiz-element/src/quiz-element.js — webcomponent**
  * **Renders:** quiz questions with a `points` property and an optional `assessing` link.
  * **Schema emitted:** none — render() has no itemscope/itemtype/itemprop.
  * **Recommended:** wrap the quiz in `<div itemscope itemtype="http://oerschema.org/Quiz">`;
    emit `<meta itemprop="gradingFormat" content="${this.points} points">` for points;
    emit `<link itemprop="assessing" href="${this.assessingRef}">` when an activity is linked.
    `Quiz` subClassOf `Assessment` (domain of `assessing`/`gradingFormat`), so both properties are
    valid on it. Expose the class choice in `haxProperties` so authors can pick Quiz vs Assessment.
  * **Handoff:** hax-webcomponent-dev

### 🛠 Implementation handoff
* Add Quiz microdata to quiz-element's render template and surface a schema toggle in its
  haxProperties (hax-webcomponent-dev).
* After implementation, re-audit pages using <quiz-element> via oerschema-audit to confirm coverage.
```

## Implementing the Recommendations

This finder is the diagnosis step. Implement with:

- **`hax-webcomponent-dev`** — add microdata emission to a component's `render()` template and
  surface schema-relevant options in its `haxProperties` / HAXSchema. This is the primary handoff.
- **`hax-site-building`** — for theme-level schema wrappers (Course/Unit wrappers, site JSON-LD).
- **Plugin maintainer** — for new VitePress directives (`::: quiz`, `::: lesson`, etc.) in
  `oerschema/vitepress-plugin/index.js`.
- **Add-on maintainer** — for new Google Apps Script components in
  `oerschema/google-apps-script/Code.js`.
- **`oerschema-schema-author`** — if a needed class/property doesn't exist yet in the vocabulary.
- **`oerschema-audit`** — after implementation, re-audit authored pages to confirm end-to-end
  coverage.

**CLI/rules reminders (from PRAW RULES.md):**
- Per PRAW rules, when issues are found in minified build data for HAXcms PHP/NodeJS, fix in the
  webcomponents monorepo first; the user runs the ubiquity build (the agent does not).
- Do not run the ubiquity script. Do not run a top-of-monorepo build. Use the local `hax` command,
  not `npx hax`.

## Acceptance criteria (for the hit-list itself)

- Every hit names a real OER Schema class/property (from the v1.2.0 vocabulary) and the exact
  emission surface (microdata in `render()`, `haxProperties` field, theme wrapper, plugin
  directive, add-on component, backend API field).
- Every recommended property is in the declared `domain` of the recommended class (accounting for
  `subClassOf` inheritance — see `oerschema-audit`'s `references/vocabulary.md`).
- The rating is one of: Wired / Partial / Unwired.
- Authored-content gaps are handed to `oerschema-audit`, not double-reported as code hits.
- Missing-vocabulary needs are handed to `oerschema-schema-author`, not invented inline.
- NodeJS backend hits target only `siteRoutes api/v1` (not the legacy v0 `src/routes/*`).

## Gotchas

- **Emission vs. editor integration are two surfaces.** A component can emit microdata in its
  `render()` (the runtime surface) and/or expose schema-relevant fields in its `haxProperties`
  (the authoring surface). A hit on one is independent of the other — report both if both are
  missing.
- **Don't confuse content gaps with code gaps.** If an element *emits* microdata but a page using
  it is still uncovered, that is an `oerschema-audit` finding (the author didn't supply the
  property values), not a code hit.
- **Verify domain with inheritance.** `Quiz` is a valid `assessing` domain because
  `Quiz`→`Assessment`. Do not flag a component emitting `assessing` on a `Quiz` wrapper as a
  domain violation.
- **The VitePress plugin maps `::: assessment` to `Assessment`, not `Quiz`.** A quiz in VitePress
  is `::: assessment type="Quiz"`. The lack of a dedicated `::: quiz` directive is a plugin hit,
  but the *content* can still be correctly classed via `type="Quiz"` → `additionalType`. Report
  the missing directive as an authoring-ergonomics hit, not a correctness hit.
- **NodeJS v0 routes are out of scope.** Per PRAW rules, only `siteRoutes api/v1` is in scope; do
  not flag `src/routes/*` (legacy v0) — those are left alone until v1 migration.
- **Don't edit here.** This skill emits a hit-list. Implementation belongs in `hax-webcomponent-dev`
  (components), `hax-site-building` (themes), or the relevant plugin/add-on/backend maintainer.

## Dependencies

- **Reads:** webcomponent source (`src/*.js`, `haxProperties.json`, `HAXSchema`), theme templates,
  CMS backend route/data code, `vitepress-plugin/index.js`, `google-apps-script/Code.js`
- **Consults:** `references/component-patterns.md` (candidate elements + emission patterns) and
  `oerschema-audit`'s `references/vocabulary.md` (class/property tables — single source of truth)
- **Hands off to:** `hax-webcomponent-dev` (components), `hax-site-building` (themes),
  `oerschema-schema-author` (missing vocabulary), `oerschema-audit` (post-implementation content
  re-audit)
- **Defers to:** `oerschema-audit` (authored-content gaps), `hax-a11y-audit` (component
  accessibility)

## References

- `references/component-patterns.md` — candidate HAX elements by pedagogical structure, the two
  schema surfaces (render microdata + haxProperties), and per-surface emission patterns
- `oerschema-audit` skill's `references/vocabulary.md` — OER Schema v1.2.0 class/property tables
  (consult, do not duplicate)
- OER Schema vocabulary source: `oerschema` repo `app/lib/schema.ts`
- PRAW RULES.md: `~/Documents/git/haxtheweb/praw/RULES.md`
