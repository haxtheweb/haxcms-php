---
name: hax-ubd-stage2
description: >
  UbD Stage 2 — think like an assessor: build a scrapbook of acceptable evidence,
  frame ≥1 GRASPS performance task, map the six facets to rubric criteria, enforce
  problem-not-exercise, and run the 7-question validity self-test. Use when the
  user says "what evidence do I need", "design a performance task", "build a
  GRASPS task", "what assessments prove understanding", or "is this assessment
  valid". Encodes UbD Ch. 7–8. The most distinctive UbD skill.
version: 1.0.0
license: MIT
metadata:
  author: PRAW
  tags: [instructional-design, understanding-by-design, ubd, stage2, assessment, grasps, six-facets, validity, hax]
  requirements: "manifest.stage1 MUST be populated (Stage 2 is derived from goals). Writes manifest.stage2 + assessment pages + editable-table rubrics."
---

# HAX UbD Stage 2 — Thinking Like an Assessor

Build the **scrapbook of acceptable evidence** for a unit: frame at least one
**GRASPS** performance task, map the **six facets** to rubric criteria, ensure at
least one genuine **problem** (not just exercises), and run the **validity
self-test** on every assessment. This is the most distinctive UbD skill — it
reverses the natural instinct to design activities first.

**The core move:** think like an assessor, not an activity designer. An assessor
asks "what sufficient, revealing evidence of understanding do I need?" An
activity designer asks "what fun things will we do?" The first column produces
aligned evidence; the second produces the apples unit (fun, but no understanding
target).

## When to Use

**Trigger conditions:**
- "What evidence do I need" / "design a performance task" / "build a GRASPS task"
- "What assessments prove understanding" / "write a rubric for understanding" / "is this assessment valid"
- You are `hax-ubd-backward-design` and have reached step 4 of its methodology

**When NOT to use (with redirect):**
- Fun-activity ideation with no evidence target → that is the activity-designer trap; refuse and redirect to Stage 1 first
- Page-level chunking → `hax-content-chunking-audit`
- Sequencing activities → `hax-ubd-stage3`

## Scope

**Mutating.** Writes `manifest.stage2` + assessment pages + `editable-table`
rubrics. Does NOT sequence (Stage 3) or set goals (Stage 1).

## Inputs

- `manifest.stage1` — **required**. Stage 2 is derived from goals; do not invent evidence without targets.

## Methodology

1. **Map the scrapbook** across the assessment continuum (UbD Fig 7.4/7.5): performance tasks (T) + academic prompts + quiz/test items + informal checks (all OE). Aim for *variety* + *longitudinal spread* — a scrapbook, not a single snapshot.

2. **Frame ≥1 core GRASPS task** (UbD Fig 7.7 prompts): Goal, Role, Audience, Situation, Performance/Product, Standards. The task must be **authentic**: realistically contextualized, requires judgment/innovation, asks the student to "do the subject," messy (constraints/noise/audience), criteria known in advance, rehearse-able with feedback. (Full prompt bank: `hax-ubd-grasps` companion.)

3. **Problem vs. exercise check** (UbD Fig 7.6): confirm ≥1 task is a genuine *problem* — no cues about how to frame/solve it, multiple approaches possible, realistically "noisy" setting, justified solution (not a single plugged-in answer). Exercises plug in on cue; problems require deciding *which* knowledge to use. Mark it `isProblemNotExercise: true`.

4. **Six-facet rubric blueprint** (UbD Fig 7.9): for each targeted understanding, pick the facet(s) that yield the strongest evidence and build `editable-table` analytic-trait criteria. Verbs per facet:
   - Explanation → explain/justify/verify/prove
   - Interpretation → interpret/make-meaning/tell significance
   - Application → apply/transfer/use in new context
   - Perspective → critique/compare/question assumptions
   - Empathy → role-play/relate
   - Self-Knowledge → self-assess/reflect
   Include at least one **impact** criterion (effect on audience / real-world consequence), not just correctness + craft. (Full facet lens: `hax-ubd-six-facets` companion.)

5. **Validity self-test** (`../hax-ubd-backward-design/references/validity-self-test.md`): run the 7 questions on every proposed assessment. Target: all "very-unlikely." Flag any "likely" as a defect and apply the matching redesign pattern before accepting.

6. **Reliability check:** ensure multiple measures over time (`progress-donut` / `simple-progress` / `promise-progress` + `grade-book`), not one high-stakes event. One valid task can still be anomalous; a *pattern* of evidence justifies the inference.

## HAX Component Map (Stage 2)

| Evidence type | Component | Notes |
|---|---|---|
| GRASPS performance task | authored page + `video-player`/`media-playlist`/`audio-player`/`image-compare-slider` (authentic context) + `simple-fields` (product) + `editable-table` (rubric/standards) | the "do the subject" page |
| Academic prompts | `stop-note` / `self-check` + `simple-fields` (constructed response) | no essay element → `simple-fields` |
| Quiz & test items | `multiple-choice`, `fill-in-the-blanks`, `matching-question`, `sorting-question`, `tagging-question` | selected/closed response only |
| Informal checks | `self-check`, `flash-card`, `stop-note` | ongoing, ungraded |
| Six-facet rubric | `editable-table` (analytic-trait) | no rubric element → `editable-table` |
| Longitudinal evidence | `progress-donut`, `simple-progress`, `promise-progress`, `grade-book` | "scrapbook not snapshot" |
| Perspective evidence | `image-compare-slider`, `a11y-compare-image` | compare viewpoints |
| Portfolio exhibit | `clean-portfolio-theme`, `glossy-portfolio-theme` | for Exhibit stage |

Full map (all stages): `../hax-ubd-backward-design/references/ubd-element-map.md`.

## Output Format

```
# Stage 2 — Acceptable Evidence: {unit}
## Evidence scrapbook
- T (performance): {GRASPS summary} → page {slug}; facets: {...}; problem-not-exercise: yes; targets: {U}
- OE (academic prompts): {...} → stop-note + simple-fields
- OE (quiz/test): {...} → {components}
- OE (informal checks): {...} → {components}
## GRASPS task {id}
G: {...} | R: {...} | A: {...} | S: {...} | P: {...} | S (standards): {...}
## Rubric (facet-mapped)
| Criterion | Facet | Level 4 | Level 1 |
## Validity self-test
{7 questions → answers; defects + redesign}
## Reliability
{longitudinal evidence plan: components + cadence}
## HAX handoff
{page + component list per evidence item}
```

## Acceptance criteria

- ≥1 GRASPS task with all 6 letters filled and `isProblemNotExercise: true`.
- Every `performanceTasks[*].targetsUnderstanding` exists verbatim in `manifest.stage1.understandings` (Stage 1 ↔ Stage 2 alignment invariant).
- Rubric criteria each map to a named facet; ≥1 criterion is an **impact** criterion, not just correctness/craft.
- Validity self-test: all 7 answers "very-unlikely" (or defects logged with a redesign plan).
- Selected-response items are NOT the *only* evidence of understanding for any targeted U — every U has ≥1 performance-based evidence source.
- `longitudinalEvidence` is non-empty (reliability: scrapbook, not snapshot).

## Gotchas

- **"Interesting project" ≠ valid evidence** (UbD Fig 7.6). A fun task that doesn't reveal the targeted understanding is a trap — redesign or drop it. Validity is about what you can *infer*, not whether the task is engaging.
- **Don't let `multiple-choice` carry understanding alone.** Selected response is "insufficient and sometimes misleading" for understanding. It rounds out the scrapbook for K/S; it cannot be the sole evidence of an enduring understanding.
- **Authenticity is about context + judgment + "doing the subject,"** not just "hands-on." A cooking activity can be hands-on and inauthentic if it doesn't reveal the targeted science understanding.
- **A valid indirect test can still be inauthentic; an authentic task can still be invalid.** Run the self-test regardless of format.
- **Don't duplicate the rubric's craft criteria as understanding criteria.** A lovely product with limited understanding (self-test Q4) is the classic validity failure — keep understanding criteria separate from and weighted above presentation.

## Dependencies

- **Reads:** `manifest.stage1` (required); `grad-blooms` (facet ↔ cognitive level)
- **Hands off to:** `hax-ubd-stage3` (which reads `manifest.stage2`)
- **Companions:** `hax-ubd-grasps` (GRASPS prompt bank), `hax-ubd-six-facets` (facet lens + rubric criteria)
- **Uses:** `hax-design-system` (DDD tokens on rendered components)

## References

- `../hax-ubd-backward-design/references/manifest-schema.md` — `stage2` fields
- `../hax-ubd-backward-design/references/ubd-element-map.md` — Stage 2 component map
- `../hax-ubd-backward-design/references/validity-self-test.md` — the 7-question self-test + defect→redesign patterns
- `../hax-ubd-backward-design/references/design-standards.md` — standards 5–9
- PRAW RULES.md: `~/Documents/git/haxtheweb/praw/RULES.md`
- Source: Wiggins & McTighe (2005), *UbD* Ch. 7–8.
