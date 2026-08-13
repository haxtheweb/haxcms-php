# OER Schema Markup Surfaces

OER Schema can be expressed in four surfaces. Pick the surface the content is *already* in — do
not mix (e.g. no microdata inside a VitePress markdown file, no `:::` directive inside a HAX
`pages/*.html`). The class URIs are always `http://oerschema.org/{ClassName}`.

## 1. Microdata (HAX `pages/*.html` and Google Docs export)

This is the surface HAX pages and the Google Apps Script add-on use. `itemscope` + `itemtype` on a
wrapper element; `itemprop` on children; `<meta>`/`<link>` for non-rendered values.

```html
<!-- A lesson with a learning objective and a quiz -->
<div itemscope itemtype="http://oerschema.org/Lesson">
  <meta itemprop="name" content="Lesson 3: Photosynthesis" />

  <div itemprop="hasLearningObjective" itemscope itemtype="http://oerschema.org/LearningObjective">
    <meta itemprop="skill" content="explain the process of photosynthesis" />
    <meta itemprop="description" content="Students will be able to explain photosynthesis." />
  </div>

  <div itemprop="doTask" itemscope itemtype="http://oerschema.org/Quiz">
    <meta itemprop="gradingFormat" content="10 points" />
    <meta itemprop="name" content="Quick Check" />
    <!-- quiz items here -->
  </div>
</div>
```

Rules:
- `itemprop` on a nested `itemscope` element creates a relationship (the child is a node, not a
  string). Use `<meta itemprop="x" content="...">` for string values, `<link itemprop="x" href="...">`
  for URI values.
- Relationship properties (`hasLearningObjective`, `doTask`, `assessing`, `rubric`,
  `hasCriterion`, `rubricScale`, `hasLevel`, `hasComponent`, `forCourse`, `parentOf`) use nested
  `itemscope` elements.
- `typeOfAction` values are `ActionType` subclass URIs: `<link itemprop="typeOfAction"
  href="http://oerschema.org/Reading" />`.
- `aiUsageConstraint` uses `<link>` for a URL value or `<meta>` for a text/code value.

## 2. JSON-LD (structured-data blocks)

Used when content is primarily structured data (a course catalog feed, an outline export) rather
than rendered prose. Emit a `<script type="application/ld+json">` block. Use `@type` and `@id` for
node identity; reference other nodes by `@id` so relationships are reciprocal.

```json
{
  "@context": "http://oerschema.org/",
  "@type": "Lesson",
  "@id": "#lesson-3",
  "name": "Lesson 3: Photosynthesis",
  "hasLearningObjective": {
    "@type": "LearningObjective",
    "@id": "#obj-1",
    "skill": "explain the process of photosynthesis",
    "description": "Students will be able to explain photosynthesis."
  },
  "doTask": {
    "@type": "Quiz",
    "@id": "#quiz-1",
    "name": "Quick Check",
    "gradingFormat": "10 points"
  }
}
```

Rules:
- Range-typed relationships are nested objects or `{"@id": "..."}` references.
- For inverse pairs, express at least one direction; prefer `@id` references so both directions
  resolve.
- `typeOfAction` value is an `ActionType` subclass URI string
  (`"http://oerschema.org/Reading"`).

## 3. VitePress directives (VitePress markdown)

Provided by the `vitepress-plugin-oer-schema` package. Container directives render to microdata at
build time. This is the surface for VitePress-authored docs.

```markdown
::: learning-objective skill="explain photosynthesis" course="BIOL-101"
Students will be able to explain the process of photosynthesis...
:::

::: assessment type="Quiz" points="25" assessing="photosynthesis-lab"
**Quick Check: Photosynthesis**
1. What are the main reactants?
:::

::: practice action="Observing,Making" material="microscope-slides"
**Lab Exercise: Observing Chloroplasts**
Instructions here...
:::

::: rubric type="analytic" scale="default-scale"
Describe rubric usage here...
  ::: rubric-criterion weight="1"
  Criterion description here...
  :::
  ::: rubric-scale pointsRequired="true"
    ::: rubric-level ordinal="1" points="1"
    Descriptor for level 1
    :::
    ::: rubric-level ordinal="4" points="4"
    Descriptor for level 4
    :::
  :::
:::

::: learning-component action="Reflecting" objective="connect-concepts"
**Reflection: Connecting Photosynthesis to Daily Life**
Reflection prompts here...
:::

::: instructional-pattern type="Lesson" title="Introduction to Photosynthesis"
This lesson combines multiple learning components...
:::
```

Directive → class mapping (from the plugin source):
- `::: learning-objective` → `LearningObjective` (attrs: `skill`, `course`)
- `::: assessment` → `Assessment` (attrs: `type` → `additionalType`, `points` → `gradingFormat`,
  `assessing`, `aiUsageConstraint`)
- `::: practice` → `Practice` (attrs: `action` → `typeOfAction` list, `material`,
  `aiUsageConstraint`)
- `::: rubric` → `Rubric` (attrs: `type` → `rubricType`, `scale` → `rubricScale` ref)
- `::: rubric-criterion` → `RubricCriterion` (attrs: `weight` → `criterionWeight`)
- `::: rubric-scale` → `RubricScale` (attrs: `pointsRequired`)
- `::: rubric-level` → `RubricLevel` (attrs: `ordinal` → `levelOrdinal`, `points` → `levelPoints`)
- `::: learning-component` → `LearningComponent` (attrs: `action` → `typeOfAction`,
  `objective` → `hasLearningObjective` ref)
- `::: instructional-pattern` → `InstructionalPattern` (attrs: `type` → `additionalType`,
  `title` → `name`)

Note: the plugin renders `Practice` for `::: practice`, `Assessment` for `::: assessment`, and
`Quiz` is *not* a dedicated directive — a quiz is authored as `::: assessment type="Quiz"`. There
is no directive for `Course`/`Unit`/`Module`/`Lesson` directly; `::: instructional-pattern
type="Lesson"` is the closest. Flag absence of a `Lesson`/`Unit`/`Module` directive as a plugin
limitation, not a content error.

## 4. Google Docs export (Apps Script add-on)

The Google Docs add-on (`google-apps-script/`) inserts components and exports to HTML with
microdata. Output shape:

```html
<div class="oer-component oer-learning-objective"
     itemscope itemtype="http://oerschema.org/LearningObjective">
  <meta itemprop="skill" content="analyze data" />
  <meta itemprop="forCourse" content="STAT-101" />
  <h3>🎯 Learning Objective</h3>
  <p itemprop="description">Students will be able to analyze statistical data...</p>
</div>
```

When auditing Docs export HTML, treat it as microdata (surface 1) with `oer-component` class
wrappers. The add-on currently emits `LearningObjective`, `Assessment`, and `Practice` components;
gaps in Docs export (no `Rubric`, no `Lesson` wrapper) are component/add-on limitations — hand to
`oerschema-integration-finder` for the add-on code, not the content.

## Choosing the surface

| Content location | Surface |
|---|---|
| HAX `pages/*.html` | microdata |
| VitePress `.md` | `:::` directives |
| Course catalog / outline JSON feed | JSON-LD |
| Google Docs export HTML | microdata (Apps Script flavor) |
| A `<script type="application/ld+json">` already in a page | JSON-LD (keep it JSON-LD) |

If a page mixes microdata and JSON-LD, that is valid but redundant — recommend one and note the
other as an advisory.
