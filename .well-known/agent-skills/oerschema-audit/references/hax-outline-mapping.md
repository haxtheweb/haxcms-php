# HAX site.json (JSON Outline Schema) → OER Schema mapping

A HAX site's structure lives in `site.json` as a JSON Outline Schema (JOS) tree: an `items` array
where each node has `id`, `title`, `slug`, `indent` (depth), `order`, `parent`, and `location`
(the page HTML path, usually `pages/<slug>.html`). This reference maps that outline onto OER Schema
classes and the relationship properties that wire them into a course graph. Use it when auditing a
HAX site's *structural* schema coverage (does the outline map to a Course → Unit → Lesson graph?).

## Structural mapping (by indent / role)

| HAX outline role | OER class | Rationale |
|---|---|---|
| The site itself (the whole `site.json`) | `Course` | A HAX site is an instructional course; `metadata.site.name` is the course name. |
| `indent: 1` nodes (top-level pages) | `Unit` or `Module` | Top-level divisions of the course. Use `Unit` for time-bounded divisions, `Module` for thematic groupings. |
| `indent: 2` nodes | `Lesson` | A single learning session within a unit/module. |
| `indent: 3+` nodes | `Lesson` or `LearningComponent` | Deeper leaves are sub-lessons or individual components (an activity, a reading). |
| A page that is primarily a quiz/test | `Assessment` (or `Quiz`) | Regardless of indent, a quiz page is an Assessment. |
| A page that is primarily an activity/project | `Task` / `Activity` / `Project` | A hands-on page is a Task subtype, not a Lesson. |
| A page that lists objectives | (container) + `LearningObjective` children | The page itself is a Lesson/Unit; each objective is a `LearningObjective` node. |
| A page that is a syllabus | `CourseSyllabus` | Linked from the Course via `syllabus`. |
| The site's table of contents | `TableOfContents` → `TableOfContentsEntry` | The JOS tree itself maps to a ToC; each outline node is a `TableOfContentsEntry` with `title` and `forComponent`. |

## Relationship wiring

Once nodes are classed, wire them with these properties (see `vocabulary.md` for domain/range):

- **Course → Unit/Module/Lesson:** `hasComponent` (domain `LearningComponent`, range
  `LearningComponent`). The Course is a `Resource`; Units/Modules/Lessons are
  `LearningComponent` subclasses, so `hasComponent` chains down the outline.
- **Child → Parent (reciprocal):** `forComponent` on each child pointing back at its parent. This
  is the inverse of `hasComponent`. Express at least one direction; both is best.
- **Any node → Course:** `forCourse` (domain `Resource`, range `Course`). Every page in the site
  belongs to the Course.
- **Lesson → LearningObjective:** `hasLearningObjective` (domain `InstructionalPattern`, range
  `LearningObjective`).
- **Lesson → Task/Activity:** `doTask` (domain `LearningComponent`, range `Task`).
- **Assessment → Activity:** `assessing` (domain `Assessment`, range `Activity`).
- **Course → CourseSection:** `section` (domain `Course`, range `CourseSection`) — only if the
  site models term-specific sections.
- **Outline tree → ToC:** `entry` (domain `TableOfContents`, range `TableOfContentsEntry`) and
  each entry's `forComponent` pointing at the page it references.

## How schema gets onto the outline

The outline (`site.json`) itself is JOS, not OER Schema. OER Schema metadata is applied in two
places:
1. **Per-page microdata** in `pages/<slug>.html` (wrap the page content in
   `<div itemscope itemtype="http://oerschema.org/{Class}">`).
2. **A site-level JSON-LD block** (optional) that serializes the whole course graph — useful for
   course-catalog discovery. Emit one `Course` node with `hasComponent` references to each page's
   `@id`.

When auditing, check that *each page* declares its OER class (surface 1) and that the relationship
properties point at the right siblings/parents. A page tagged `Lesson` with no `forCourse` and no
`hasLearningObjective` is a Partial finding (present but unwired).

## metadata.site.name rule

Per PRAW rules, `metadata.site.name` must align with the site folder name and is used to establish
basePath. When mapping the site to a `Course`, use `metadata.site.name` as the `Course` `name` and
do not rename it. If `metadata.site.name` is missing or mismatched, flag it as a structural
advisory (and hand the structure fix to `hax-site-building` / the `hax` CLI — never hand-edit
`site.json`).

## Worked mapping

A HAX site `dmd-100` with outline:
- `dmd-100` (site) → `Course` (name "dmd-100", `courseIdentifier` "DMD-100")
  - `unit-1` (indent 1) → `Unit` (`forCourse` → Course, `hasComponent` → its lessons)
    - `lesson-1` (indent 2) → `Lesson` (`forCourse` → Course, `forComponent` → Unit-1,
      `hasLearningObjective` → its objectives, `doTask` → its tasks)
      - `lesson-1-quiz` (indent 3, a quiz) → `Quiz` (`forCourse` → Course, `forComponent` →
        Lesson-1, `assessing` → the activity it assesses, `gradingFormat` → points)

Audit this site by checking each `pages/<slug>.html` for the matching `itemscope itemtype` and the
relationship `itemprop`s above.
