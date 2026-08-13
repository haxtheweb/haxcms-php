---
name: hax-ubd-backward-design
description: >
  Design HAX course units via Understanding by Design (UbD) backward design — start
  from desired results, derive acceptable evidence, then plan learning experiences.
  Produces a UbD unit plan AND the HAX site skeleton (site.json node + WHERETO pages
  with OER schema metadata) that embodies it. Use when the user says "design a unit
  on X", "backward-design this module", "turn this content into a HAX unit", "build
  a course around big ideas", or "redesign this unit for understanding".
version: 1.0.0
license: MIT
metadata:
  author: PRAW
  tags: [instructional-design, understanding-by-design, ubd, backward-design, hax, course-design, unit-planning]
  requirements: "A topic, standard, or existing HAX page content to design from. Writes a manifest to files/ubd/<unit-slug>.manifest.json and hands site structure off to the hax CLI."
---

# HAX UbD Backward Design (Orchestrator)

Run the full three-stage Understanding by Design process over a topic, standard,
or existing content, and emit **both** a UbD unit plan **and** the HAX site
skeleton that embodies it. This skill owns the workflow, the UbD template, the
design standards, and the iterative plan→revise→teach→assess cycle. It composes
the three stage skills (`hax-ubd-stage1/2/3`) and is the highest-leverage entry
point of the UbD family.

## The worldview in one paragraph

Teachers are *designers*, and the best designs work *backward* from desired
results. UbD attacks two "twin sins": **activity-oriented design** (fun
activities that lead nowhere intellectually — "hands-on without being minds-on")
and **coverage** (marching through a textbook, "teaching by mentioning it").
Both fail because no guiding intellectual purpose or clear priorities frame the
learning. The fix is the three stages: identify desired results → determine
acceptable evidence → plan learning experiences. HAX is a natural substrate
because its element catalog maps cleanly onto every UbD construct (quizzes,
performance contexts, reflection, rubrics, progressive disclosure).

## When to Use

**Trigger conditions:**
- "Design a unit on X" / "backward-design this module" / "build a course around big ideas"
- "Turn this content into a HAX unit" / "redesign this unit for understanding"
- handed a standard, topic, or existing `pages/*.html` and asked to make it a coherent unit

**When NOT to use (with redirect):**
- Single-page readability / "wall of text" / chunking → `hax-content-chunking-audit` (page scope)
- One objective's cognitive level/verb → `grad-blooms` (cognitive level)
- Brand-new empty site with no learning goals yet → `hax-site-building`
- Importing an OpenStax book → `hax-openstax2hax` (then feed its output INTO this skill)
- Auditing an existing unit without redesigning → `hax-ubd-unit-audit`

## Scope

**Mutating.** Produces a manifest (`files/ubd/<unit-slug>.manifest.json`) + a
`site.json` patch + a page-content handoff list. Does NOT author page content
itself — delegates content authoring to `hax-claudehax` / `hax-site-building`.

## Inputs

- `source`: a standard text, topic phrase, URL, or existing `pages/*.html` content
- `scope`: `unit` (default) | `course`/`program` — refuse `lesson` (see Gotchas)
- `audience`: grade level / learners (shapes Tailor + media choices)
- optional `courseContext.programBigIdeas`: the program-level big ideas this unit nests under

## Methodology

1. **Ingest source.**
   - If content: parse current structure (optionally via `hax-content-chunking-audit`).
   - If standard: capture it verbatim into `source.ref`.
2. **Initialize manifest** with `unitId` (kebab-case, will match the site.json node slug), `title`, `source`. Schema: `references/manifest-schema.md`.
3. **Stage 1 → call `hax-ubd-stage1`** → write `manifest.stage1`.
4. **Stage 2 → call `hax-ubd-stage2`** (reads `stage1`) → write `manifest.stage2`.
5. **Stage 3 → call `hax-ubd-stage3`** (reads `stage1` + `stage2`) → write `manifest.stage3`.
6. **Materialize the site skeleton:**
   - `site.json` unit node + child pages from `stage3.sequence` (order = WHERETO order).
   - OER schema metadata on each page from `stage1` (G/U → `oer-schema` `learningOutcome`).
   - Hand off per-page content authoring to `hax-claudehax` / `hax-site-building`.
7. **Self-review** against the 12 UbD design standards (`references/design-standards.md`). Design is iterative — on any failure, revisit the offending stage and loop.
8. **Persist the manifest** to `files/ubd/<unit-slug>.manifest.json` and set `validation.*` from the self-review.

## Output Format

```
# UbD Unit Plan: {title}
## Source & context
source: {type/ref} | scope: {unit|course} | strand: {...} | program big ideas: {...}
## Stage 1 — Desired Results
G: {goals}
U: {enduring understandings — full sentences}
Q (overarching): {...} | Q (topical): {...} | Entry: {...}
K: {...} | S: {...}
Predictable misunderstandings: {misconception → pre-assess / confront}
## Stage 2 — Acceptable Evidence
Performance tasks (GRASPS): {summary; facets; problem-not-exercise}
Other evidence: {academic prompts / quiz-test / informal checks}
Rubric (facet-mapped): {criteria → facet}
Validity self-test: {pass / defects}
Longitudinal evidence: {components}
## Stage 3 — WHERETO
W/H/E/R/E/T/O: {each → HAX components + page slug}
## Site skeleton
site.json patch: {unit node + child page slugs in WHERETO order}
Page-content handoff: {one line per page → component list}
## Design-standards self-review
{12 standards → pass/fail; overall rating: Aligned | Partial | Activity-oriented | Coverage-bound}
```

## Acceptance criteria (a unit is "done" only when all hold)

- Every Stage 1 element (G/U/Q/K/S + ≥1 misunderstanding) is present and non-empty.
- Every U is a full-sentence proposition ("Students will understand that…").
- ≥1 GRASPS performance task with `isProblemNotExercise: true`.
- Every `stage2.performanceTasks[*].targetsUnderstanding` exists verbatim in `stage1.understandings` (alignment invariant).
- `stage3.whereto.exhibit.taskRef` resolves to a real `stage2.performanceTasks[*].id`.
- Validity self-test: all 7 answers "very-unlikely" (or defects logged with a redesign plan).
- No recommended component is outside the verified registry (`references/ubd-element-map.md`).
- Overall design-standards rating = Aligned (or Partial with a stated next-stage action).

## Gotchas

- **Do not skip to Stage 3.** The twin sins live in starting with activities. Even if the user hands you activities first, route them through Stage 1→2 before sequencing.
- **A single lesson is too short for UbD.** Wiggins & McTighe explicitly discourage lesson-level UbD (a lesson can't develop big ideas, EQs, and authentic applications). Refuse `scope: lesson` and bump to unit scope.
- **Units nest in courses.** If `courseContext.programBigIdeas` is empty, ask for the program-level framing before proceeding — otherwise the unit's big ideas will lack a home.
- **Iterative, not linear.** Stage 2 may reveal that a Stage 1 "understanding" was really a fact; Stage 3 may reveal a missing prerequisite skill. Revisit prior stages. Update the manifest, don't fork it.
- **Don't author page content here.** This skill produces the skeleton + handoff. Page content authoring belongs to `hax-claudehax` / `hax-site-building` so the CLI owns `site.json` structure.

## CLI & rules (from PRAW RULES.md)

- Use the local/global `hax` command — **not** `npx hax`.
- When scripting, pass automation flags: `--y --no-i --auto` (add `--quiet` / `--skip` as needed).
- Never hand-edit `site.json` for structure — use `hax site node:add` / `site:items-import`. The CLI owns page structure; this skill owns the plan + page *content* handoff.
- `a11y-collapse` MUST set `heading-button`.
- Audio via `media-playlist` + `audio-player`; inputs via `simple-fields`; tables via `editable-table`; educational elements get `oer-schema` metadata.
- No optional chaining (`?.`) in any generated code; use `globalThis` not `window`.

## Dependencies

- **Calls:** `hax-ubd-stage1`, `hax-ubd-stage2`, `hax-ubd-stage3`
- **Hands off to:** `hax-claudehax`, `hax-site-building` (site materialization), `hax-design-system` (DDD tokens on rendered components)
- **Reads:** `grad-blooms` (objective verbs / cognitive level)
- **Feeds from:** `hax-openstax2hax` (textbook import → backward design)
- **Is audited by:** `hax-ubd-unit-audit`

## References

- `references/manifest-schema.md` — the UbD unit manifest (the shared data object)
- `references/ubd-element-map.md` — consolidated UbD → HAX element map (single source of truth)
- `references/design-standards.md` — the 12 UbD design standards + rating → diagnosis mapping
- `references/validity-self-test.md` — the 7-question assessment validity self-test
- PRAW RULES.md: `~/Documents/git/haxtheweb/praw/RULES.md`
- Source: Wiggins, G. & McTighe, J. (2005). *Understanding by Design* (Expanded 2nd ed.). ASCD.
