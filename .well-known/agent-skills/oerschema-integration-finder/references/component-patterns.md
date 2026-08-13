# HAX component patterns for OER Schema integration

This reference lists the candidate HAX elements/surfaces that render pedagogical content and the
two schema-emission surfaces each has. Use it to scope a code scan: these are the places OER Schema
microdata or consumption *should* be wired. The class/property names come from the v1.2.0 vocabulary
(see `oerschema-audit`'s `references/vocabulary.md`).

## The two schema surfaces for a webcomponent

1. **Runtime microdata (the `render()` template).** A LitElement component emits OER Schema by
   including `itemscope`/`itemtype`/`itemprop` in its rendered template. This is what makes the
   rendered DOM machine-readable. Example:
   ```js
   render() {
     return html`<div itemscope itemtype="http://oerschema.org/Quiz">
       <meta itemprop="name" content="${this.title}" />
       <meta itemprop="gradingFormat" content="${this.points} points" />
       ${this.questions.map(q => html`<div itemprop="mainContent">${q}</div>`)}
     </div>`;
   }
   ```
2. **Authoring integration (`haxProperties` / `HAXSchema`).** The component's HAX editor integration
   can expose schema-relevant fields (the class choice, `forCourse`, `hasLearningObjective`,
   `assessing`, `gradingFormat`, `typeOfAction`, `aiUsageConstraint`) so authors set them in the
   editor. A component whose `gizmo` maps to a pedagogical structure but whose `settings` expose no
   schema fields is an authoring-surface hit.

A component can be a hit on one surface and not the other. Report both independently.

## Candidate elements by pedagogical structure

Scan the webcomponents monorepo (`elements/`) for elements in these categories. The exact tag names
evolve; discover them by searching for the rendered behavior, not by hard-coding names. When you
find a candidate, check both surfaces above.

| Pedagogical structure | OER class | What to look for in the element | Likely tag-name patterns (verify) |
|---|---|---|---|
| Quiz / test / check | `Quiz` (subClassOf `Assessment`) | renders questions, a score/points value, maybe an `assessing` link | `*quiz*`, `*assessment*`, `*test*`, `*question*` |
| Generic assessment / exam | `Assessment` | renders an evaluation with a grade | `*assessment*`, `*exam*`, `*grade*` |
| Submission / written work | `Submission` | renders a student submission prompt | `*submission*`, `*assignment*` |
| Activity / lab / exercise | `Activity` (subClassOf `Task`) | renders a hands-on activity, may have `assessedBy`/`rubric` | `*activity*`, `*lab*`, `*exercise*` |
| Project | `Project` (subClassOf `Activity`) | renders a multi-part project | `*project*` |
| Practice / drill | `Practice` (subClassOf `Task`) | renders practice items with `typeOfAction` | `*practice*`, `*drill*` |
| Learning objective | `LearningObjective` | renders an objective statement with `skill` | `*objective*`, `*outcome*`, `*goal*` |
| Rubric | `Rubric` (+ `RubricCriterion`/`RubricScale`/`RubricLevel`) | renders a rubric grid | `*rubric*` |
| Lesson / unit / module wrapper | `Lesson` / `Unit` / `Module` | renders a page-level pedagogical wrapper | `*lesson*`, `*unit*`, `*module*` |
| Course / syllabus | `Course` / `CourseSyllabus` | renders course metadata or a syllabus | `*course*`, `*syllabus*` |
| Outline / table of contents / nav | `TableOfContents` → `TableOfContentsEntry` | renders the site outline/navigation | `*outline*`, `*toc*`, `*nav*`, `*menu*`, `*sitemap*` |
| Media (video/audio/image) | `MediaObject`/`ImageObject` (via `associatedMedia`) | renders media referenced by content | `*video*`, `*audio*`, `*media*`, `*image*` |
| Reading / text content | `LearningComponent` (generic) | renders authored reading content | `*content*`, `*reading*`, `*article*` |

For each candidate found: read `src/<tag>.js` and any `haxProperties.json` / `HAXSchema` method.
Classify what it renders, check the two surfaces, and record a hit if a pedagogical structure is
rendered with no schema emission.

## Theme surfaces

A HAXcms theme (extending `HAXCMSLitElement` or the DDD-based theme) renders `pages/*.html` content.
Schema integration at the theme level means:
- wrapping the rendered page in an OER class wrapper (`Course` for the site, `Unit`/`Lesson` for the
  current page based on outline depth), and/or
- emitting a site-level `<script type="application/ld+json">` `Course` graph in the `<head>`.

A theme that renders page content with no OER wrapper and no JSON-LD is a structural hit. Per PRAW
rules, run `yarn run build` (not manual `custom-elements.json` edits) after any theme class change
that inherits from `HAXCMSLitElement` — note this in the handoff, do not run it yourself.

## CMS backend surfaces (PHP / NodeJS HAXcms)

Backends serve outline and page data. Schema integration means the API/data model exposes OER class
mapping:
- `site.json` / outline endpoints could serialize a `Course` graph (`hasComponent` references).
- Page JSON could carry an `oerClass` / `oerProperties` field the theme renders.

NodeJS scope: per PRAW rules, only the `siteRoutes api/v1` system is in scope — do **not** flag the
legacy `src/routes/*` (v0) endpoints. PHP: `haxcms-php`. Per PRAW rules, fix issues in the
webcomponents monorepo first for minified build data; the user runs ubiquity (the agent does not).

## VitePress plugin surface (`oerschema/vitepress-plugin/index.js`)

The plugin implements markdown-it container directives. Implemented directives (v0.2.0):
`learning-objective`, `assessment`, `practice`, `rubric`, `rubric-criterion`, `rubric-scale`,
`rubric-level`, `learning-component`, `instructional-pattern`. Each maps to a class (see
`oerschema-audit`'s `references/markup-surfaces.md`).

Missing directives worth flagging (each is a plugin hit if authors would reasonably use it):
- `course`, `unit`, `module`, `lesson` — no dedicated structural directive (`instructional-pattern
  type="Lesson"` is the workaround).
- `quiz`, `submission` — no dedicated assessment subtypes (`assessment type="Quiz"` is the
  workaround).
- `task`, `activity`, `project` — no dedicated task directives.
- `syllabus`, `topic` — no directives.
- `learning-objective` is present; `course-prerequisites` has no directive.

Report missing directives as authoring-ergonomics hits (the content can often be classed via
`type=`/`additionalType`, but a dedicated directive is more ergonomic and more discoverable).

## Google Apps Script add-on surface (`oerschema/google-apps-script/Code.js`)

The add-on inserts components into Google Docs and exports to HTML with microdata. Implemented
components: `LearningObjective`, `Assessment`, `Practice`. Missing components worth flagging:
- `Quiz` (a quiz insert flow).
- `Rubric` family (rubric insert + export).
- `Lesson`/`Unit` wrappers (wrapping a doc section as a lesson).
- `Task`/`Activity` (activity insert with `typeOfAction`).

Each missing component is an add-on hit; hand to the add-on maintainer.

## How to verify a hit is real

Before reporting a hit:
1. Confirm the element actually *renders* the pedagogical structure (read `render()`), not just that
   its name suggests it.
2. Confirm neither surface emits schema (grep the template for `itemscope`/`itemtype`/`itemprop`
   and check `haxProperties`/`HAXSchema` for schema-relevant fields).
3. Confirm the recommended class is in the v1.2.0 vocabulary and the recommended properties are in
   its declared domain (with inheritance).
4. If the element already emits schema but with a wrong class/property, report it as a
   mis-classification hit, not a missing-coverage hit.

## What not to flag

- Elements that render purely presentational content (a card, a button, a grid) with no
  pedagogical semantics — no OER class applies.
- The legacy NodeJS `src/routes/*` (v0) endpoints — out of scope per PRAW rules.
- Authored-content gaps (the element emits schema but the author didn't fill it in) — those are
  `oerschema-audit` findings.
- Vocabulary gaps (a needed class doesn't exist) — those are `oerschema-schema-author` findings.
