---
name: hax-ubd-grasps
description: >
  Frame UbD GRASPS performance tasks (Goal, Role, Audience, Situation,
  Performance/Product, Standards) and select authentic-context HAX media. Use when
  the user says "build a GRASPS task", "make this assessment authentic", "design a
  performance task", or "frame a real-world task". Companion to hax-ubd-stage2.
version: 1.0.0
license: MIT
metadata:
  author: PRAW
  tags: [instructional-design, understanding-by-design, ubd, grasps, performance-task, authentic-assessment, hax]
  requirements: "A targeted enduring understanding to assess (manifest.stage1). Emits a GRASPS task spec + authentic-context media selection."
---

# HAX UbD GRASPS Task Framing

Frame authentic **GRASPS** performance tasks and pick the right HAX
authentic-context media. This is the focused companion to `hax-ubd-stage2` (which
owns the full evidence scrapbook) — use it when the user specifically needs a
performance task framed or made authentic.

## The six letters (UbD Fig 7.7)

| Letter | Element | Prompt stem |
|---|---|---|
| G | Goal | Your task is… / The goal is to… / The challenge is… / The obstacles are… |
| R | Role | You are… / You have been asked to… / Your job is… |
| A | Audience | Your clients are… / The target audience is… / You need to convince… |
| S | Situation | The context is… / The challenge involves dealing with… |
| P | Performance/Product | You will create a… in order to… / You need to develop… so that… |
| S | Standards | Your performance needs to… / Your work will be judged by… / Your product must meet… |

## When to Use

- "Build a GRASPS task" / "make this assessment authentic" / "design a performance task"
- "Frame a real-world task for X" / "give this task an audience and purpose"
- You are `hax-ubd-stage2` and are framing the core performance task

**When NOT:** the full evidence scrapbook (rubric, validity, reliability) → `hax-ubd-stage2`. Cognitive level → `grad-blooms`.

## Authenticity checklist (UbD Ch. 7)

A task is authentic if it:
- Is **realistically contextualized** (replicates real-world ways knowledge is tested)
- Requires **judgment and innovation** (not a cued plug-in)
- Asks the student to **"do the subject"** (resembles work in the field)
- Replicates **challenging, "noisy"** situations (constraints, audience, purpose)
- Assesses **integrated repertoire use** (the whole game, not just sideline drills)
- Allows **rehearsal, resources, feedback** (not mystery testing)

If a task fails ≥2 of these, it is an exercise, not a problem — rework it and mark
`isProblemNotExercise` accordingly.

## HAX authentic-context media picker

| Context need | Component |
|---|---|
| Video scenario / documentary context | `video-player` |
| Audio interview / oral history / radio context | `audio-player` (prefer via `media-playlist`) |
| Multi-asset media sequence (audio program) | `media-playlist` + `audio-player` |
| Compare two visuals (then/now, two designs, two maps) | `image-compare-slider`, `a11y-compare-image` |
| Interactive image map (explore a scene/diagram) | `lrndesign-imagemap` |
| Constructed response / product / report | `simple-fields` |
| Rubric / standards / criteria display | `editable-table` |

## Output Format

```
# GRASPS Task: {title}
## Targeted understanding
{verbatim from manifest.stage1.understandings}
## G — Goal
{...}
## R — Role
{...}
## A — Audience
{...}
## S — Situation
{...}
## P — Performance/Product
{...}
## S — Standards
{...}
## Authenticity check
{6 criteria → pass/fail; isProblemNotExercise: true/false}
## HAX context media
{components + placement}
## HAX product + rubric
{simple-fields for product; editable-table for rubric}
```

## Acceptance criteria

- All six GRASPS letters filled.
- Authenticity: passes ≥5 of 6 checks; `isProblemNotExercise: true`.
- `targetsUnderstanding` is stated verbatim from Stage 1.
- Context media chosen from the picker above (no invented tags).
- Product captured via `simple-fields`; standards/rubric via `editable-table`.

## Gotchas

- **A GRASPS task can still be an exercise.** If the Situation gives away the exact approach, it's a cued plug-in. Remove cues; make the student decide *which* knowledge to use.
- **Audience and purpose transform a task.** A "report on nutrition" becomes authentic when framed as "advise a specific client with specific constraints." Don't skip A and the purpose in P.
- **Not every assessment needs GRASPS.** Use it for the core performance task(s); quizzes and academic prompts round out the scrapbook without GRASPS framing.

## Dependencies

- **Reads:** the targeted understanding (from `manifest.stage1`)
- **Used by:** `hax-ubd-stage2`
- **Sibling:** `hax-ubd-six-facets` (which facets the task should reveal)

## References

- `../hax-ubd-backward-design/references/ubd-element-map.md` — Stage 2 component map
- `../hax-ubd-backward-design/references/validity-self-test.md` — run on the framed task
- Source: Wiggins & McTighe (2005), *UbD* Ch. 7, Fig 7.6–7.7.
