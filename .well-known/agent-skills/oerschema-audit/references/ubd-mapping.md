# UbD artifacts → OER Schema mapping

The Understanding by Design skill family (`hax-ubd-stage1`, `hax-ubd-stage2`, `hax-ubd-stage3`,
`hax-ubd-six-facets`, `hax-ubd-grasps`, `hax-ubd-essential-questions`, `hax-ubd-backward-design`)
produces unit plans and HAX site skeletons that already reference "OER schema metadata." This
reference maps each UbD artifact onto the OER Schema class/property that should carry it, so an
audit of a UbD-derived unit can verify the metadata was actually applied. Use it when the content
originated from a backward-design unit.

## Unit level

| UbD artifact | OER class | Properties |
|---|---|---|
| The unit itself | `Unit` (subClassOf `InstructionalPattern`) | `name`, `forCourse`, `hasComponent` → lessons/activities, `hasLearningObjective` → the unit's objectives |
| The course the unit belongs to | `Course` | `courseIdentifier`, `institution`, `department`, `termOffered` |
| Transfer goals (long-term aims) | `LearningObjective` | `skill`, `description`, `forCourse` (these are course-level objectives) |

## Stage 1 — Desired Results

| UbD artifact | OER class/property | Notes |
|---|---|---|
| Enduring Understandings (full-sentence propositions) | `LearningObjective.description` (or a dedicated `LearningObjective` per EU) | EUs are understandings, not skills; model each EU as a `LearningObjective` whose `description` is the full-sentence proposition and whose `skill` captures the understanding's verb. |
| Overarching Essential Questions | `LearningObjective` with `description` = the question, or keep as content | Essential questions are openers, not outcomes; if modeled, use `LearningObjective` and note in `description`. Often left as authored prose inside the Lesson. |
| Topical Essential Questions | same | Scoped to the unit; attach to the `Unit` as authored content. |
| Knowledge and Skills (Stage 1 K/S) | `LearningObjective.skill` (skills) + `LearningObjective.description` (knowledge) | Discrete knowledge/skill statements map cleanly to `LearningObjective`. |
| Predictable Misunderstandings | authored prose (no dedicated OER class) | No OER class for misconceptions; keep in page content. Do not invent a class. |

## Stage 2 — Evidence

| UbD artifact | OER class/property | Notes |
|---|---|---|
| GRASPS performance task | `Assessment` (or `Project` if it is a multi-activity project) | `name`, `assessing` → the `Activity`/`Project` being assessed, `gradingFormat`, `rubric` → the `Rubric`. A GRASPS task that is a collection of activities → `Project` (subClassOf `Activity`). |
| Other evidence (quizzes, tests, academic prompts) | `Assessment` / `Quiz` / `Submission` | Quiz → `Quiz`; written submission → `Submission`; other → `Assessment`. |
| Rubric criteria (six-facet-mapped) | `Rubric` → `RubricCriterion` | `rubricType` = "analytic" (facet-mapped rubrics are analytic); each criterion `hasCriterion`; facet mapping lives in the criterion `description`. |
| Rubric performance levels | `RubricScale` → `RubricLevel` | `rubricScale` on the Rubric; `hasLevel` on the Scale; each `RubricLevel` carries `levelOrdinal` and `levelPoints`. `pointsRequired` on the Scale. |
| Self/peer assessment | `Assessment` with `assessing` → the `Activity` | Mark the assessor in authored content; no dedicated OER property for assessor role. |

## Stage 3 — Learning Plan (WHERETO)

| UbD artifact | OER class/property | Notes |
|---|---|---|
| WHERETO learning activities | `Task` / `Activity` / `Practice` | Each learning activity is a `Task` (or `Activity`/`Practice` subclass). Use `typeOfAction` to tag the learning verb. |
| Hook / "Where/Why" (W) | `Activity` with `typeOfAction` = `Watching`/`Listening`/`Discussing` | The hook is an engagement activity. |
| Equip (E) | `Activity`/`Practice` with `typeOfAction` = `Reading`/`Making`/`Researching` | Skill-building activities. |
| Explore/Experience (Rethink, R) | `Activity` with `typeOfAction` = `Reflecting`/`Researching` | |
| Evaluate/Revise (E2, R) | `Assessment` (formative) | Formative checks → `Assessment`/`Quiz`. |
| Tailor (T) | authored content (differentiation) | No OER property for differentiation; keep in content. |
| Organize (O) | the `Unit`/`Lesson` sequence via `hasComponent` | The WHERETO sequence is the `hasComponent` ordering on the Unit/Lesson. |

## ActionType ↔ learning-verb tagging

The `typeOfAction` property (domain `Task`, range `ActionType`) is the OER Schema hook for the
learning verb a UbD activity targets. Map UbD activity verbs to `ActionType` subclasses:

- Reading a text → `Reading`
- Writing / producing text → `Writing`
- Making / constructing → `Making`
- Researching / investigating → `Researching`
- Listening (lecture, podcast) → `Listening`
- Watching (video, demo) → `Watching`
- Reflecting / journaling → `Reflecting`
- Discussing / Socratic seminar → `Discussing`
- Observing (field observation, lab observation) → `Observing`
- Presenting / performing → `Presenting`
- Assessing (peer/self critique) → `Assess`

A `typeOfAction` value that is not one of these is a range violation (flag it).

## Facet → criterion bridge

When a UbD rubric criterion is facet-mapped (via `hax-ubd-six-facets`), the facet (Explanation,
Interpretation, Application, Perspective, Empathy, Self-Knowledge) is performance criteria, not an
OER class. Put the facet name in the `RubricCriterion` `description` (authored content); do not
invent an OER class for facets. The `criterionWeight` property carries the weight.

## Audit checklist for a UbD-derived unit

When auditing a unit page that came from `hax-ubd-backward-design`, verify:
1. The unit is tagged `Unit` (or `Module`) with `forCourse` and `hasComponent`.
2. Each enduring understanding / knowledge-skill is a `LearningObjective` linked via
   `hasLearningObjective` on the Unit/Lesson.
3. The GRASPS task is tagged `Assessment` (or `Project`) with `assessing` and `rubric`.
4. The rubric is a `Rubric` with `hasCriterion` → `RubricCriterion` and `rubricScale` →
   `RubricScale` → `hasLevel` → `RubricLevel`.
5. Each WHERETO activity is a `Task`/`Activity`/`Practice` with a valid `typeOfAction`.
6. No invented classes/properties for EUs, essential questions, misconceptions, facets, or
   differentiation — those live in authored content.

If the UbD unit skeleton was supposed to ship OER metadata (per `hax-ubd-backward-design`) but the
page has none, that is an **Uncovered** finding — the metadata was dropped between design and
authoring.
