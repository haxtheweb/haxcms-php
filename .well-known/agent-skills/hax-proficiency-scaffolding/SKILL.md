---
name: hax-proficiency-scaffolding
description: "Match a HAX unit's scaffolding to its learners' skill stage (novice to expert) using ELEVATE-XR: objectives, learning loop, stress, complexity, assistance, feedback and fidelity. Use for \"who is this for\", \"too easy / too hard for experts\" or adaptive paths."
version: 1.0.0
license: Apache-2.0
metadata:
  author: PRAW
  tags: [instructional-design, andragogy, dreyfus, expertise, scaffolding, cognitive-load, expertise-reversal, elevate-xr, hax]
  requirements: "A unit to design or audit: a HAX site path (site.json + pages/*.html) or a planned unit outline, plus a description of the intended learners. Design mode emits a plan; audit mode emits a report. Neither edits pages."
---

# HAX Proficiency Scaffolding (ELEVATE-XR for the web)

Fit the amount of support in a HAX unit to **where the learner is on the road to expertise**.
The same page cannot serve a novice and an expert: support that a novice needs gets in an
expert's way (the *expertise reversal effect*), and the open-ended challenge an expert needs
overwhelms a novice. This skill profiles the learners on the five Dreyfus stages, then sets the
objective level, the learning loop and five scaffolding dials for each page from a
research-based crosswalk, and maps every setting to a real HAX element.

Framework: Stanney, Skinner & Hughes (2023), *ELEVATE-XR*. It unifies behaviorism, cognitive
load theory, Component Display Theory, Gagné's conditions of learning, embodied, constructivist,
experiential (Kolb) and situated learning theory on top of the Dreyfus skill-acquisition model
and Bloom's revised taxonomy. The authors note it applies beyond XR to any computer-based
training; this skill is that translation for HAX sites.

## When to Use

**Trigger conditions:**
- "Who is this course for?" / "is this too basic for experienced staff?" / "too hard for beginners?"
- Designing professional, workforce or continuing-education content for adults with mixed experience
- A unit should take learners from novice toward competence or expertise across several pages
- Building an adaptive or branching path ("send people who struggle here, people who know it there")
- Before `hax-ubd-stage3` when the audience's prior skill is uneven or unknown

**When NOT to use (with redirect):**
- Cognitive level of one objective → `grad-blooms` (this skill picks the level from the learner's stage; `grad-blooms` writes and checks the objective)
- Page length and wall-of-text problems → `hax-content-chunking-audit`
- Unit goals and evidence → `hax-ubd-stage1` / `hax-ubd-stage2`
- What the feedback on a question should say → `hax-feedback-design`
- Whether all learners can access the content → `hax-udl-audit` / `hax-a11y-audit`

## Scope

Two modes, both **non-mutating**:
- **Design:** produce a learner profile and a per-page scaffolding plan for a unit being built.
- **Audit:** read an existing unit and report pages whose scaffolding does not fit the stated audience.

Hand the plan to `hax-site-building` / `hax-claudehax` to apply it.

## Inputs

- The intended learners, in the user's words (role, prior experience, what they can already do)
- A HAX site path, a unit slug, or a planned outline
- Optional: a UbD manifest at `files/ubd/<unit-slug>.manifest.json` (reuse its Stage 1 goals)

## Methodology

1. **Profile the learners.** Place the audience on a Dreyfus stage using the descriptions in
   `references/elevate-crosswalk.md` (what they can do, not their job title or years). If the
   audience spans stages, record the range and plan for the lowest stage with fast-forward routes
   (step 6). If the stage is unknown, ask one question: "What can they already do without help?"
2. **Set the destination.** Decide the stage the unit should reach. One unit rarely moves a learner
   more than one stage; say so when the user expects more.
3. **Pick the objective level and activities.** Take the Bloom's level and verbs for the target
   stage from the crosswalk (Novice → Remember, Advanced beginner → Understand, Competent → Apply,
   Proficient → Analyze, Expert → Evaluate/Create). Cover facts, concepts and procedures at that
   level (Component Display Theory). Hand the wording to `grad-blooms`.
4. **Lay out the learning loop.** Each stage has its own loop (crosswalk table). Map each step of the
   loop to a page or page section, in order, using the element map in
   `references/scaff-element-map.md`.
5. **Set the five SCAFF dials** for every page: **S**tress, **C**omplexity, **A**ssistance,
   **F**eedback, **F**idelity. Use the stage's settings from the crosswalk and the HAX controls in
   the element map. Fade assistance and raise complexity across the unit; never raise all five at once.
6. **Handle mixed audiences.** Do not average. Build for the lowest stage and add:
   - a short pre-check (a few question elements) followed by a `self-reflective-nudge` where the
     learner reports how they did; its nudges link (`data-link`) to a refresher page for low answers
     and a challenge page for high ones (the nudge routes on the learner's self-report; question
     elements cannot route on their own);
   - `a11y-collapse` (with `heading-button`) sections titled "Need a refresher?" for support and
     "Ready for a harder case?" for extension, so experts skip support rather than wade through it.
7. **Check for expertise reversal and overload** (audit mode, and a self-check in design mode) using
   the rules below.
8. **Emit** the output below.

## Mismatch rules (flag these)

Over-support for the audience (expertise reversal), when the target stage is Competent or higher:
- every question has a `hint` slot that gives the answer away, or worked examples are never faded
- unlimited attempts (no `max-attempts`) on every item, with only right/wrong feedback
- step-by-step instructions for a task the audience already performs on the job

Overload for the audience, when the target stage is Novice or Advanced beginner:
- an open-ended or ill-defined task appears before any worked example of the procedure
- a high-fidelity image, video or simulation with no orienting cue (no hotspots, labels or "look for" prompt)
- time pressure or penalties (`concept-runner-game`, low `max-attempts`) on first exposure; `concept-quest-game` hearts are a mild penalty
- several new concepts introduced in one section with no practice between them

Loop gaps:
- no reflection step anywhere in the loop, or reflection of the wrong kind for the stage (see crosswalk)
- no repetition: concepts appear once and are never retrieved again (hand to `hax-practice-and-retrieval`)

## Output Format

```
# Proficiency scaffolding: {unit}
## Learner profile
Stage now: {stage} | Target: {stage} | Range: {if mixed}
Evidence: {what the learners can already do}
## Objective
{Bloom's level + verb + content, per grad-blooms}
## Learning loop → pages
1. {Situate} → {page/section}: {elements}
2. ...
## SCAFF settings per page
| Page | Stress | Complexity | Assistance | Feedback | Fidelity | HAX controls |
## Mixed-audience routing
{pre-check / nudge placement and link targets}
## Findings (audit mode)
- {page}: {rule broken} → {fix with a real element}
## Handoff
{hax-feedback-design for feedback text, hax-practice-and-retrieval for repetition, hax-site-building to apply}
```

## Acceptance criteria

- The learner profile states the evidence for the stage, not just a label.
- Every loop step maps to a page or section and to real HAX elements from the element map.
- Every page has all five dials set, and assistance never increases from one page to the next.
- Mixed audiences get routing (step 6), not one averaged path.
- No tag outside the element map; every `a11y-collapse` sets `heading-button`.

## Gotchas

- **Stage is about the task, not the person.** An expert nurse is a novice at a new device. Profile per skill.
- **Time estimates are weak.** The paper's hours per stage (20, 40, 100, 2,000, 10,000) extrapolate
  from popular sources and the contested 10,000-hour rule. Do not promise them; plan repetition instead.
- **Embodiment is limited on the web.** HAX pages can model and check a physical procedure, but the
  Competent stage's "embody" step needs real or simulated practice. Say where practice happens
  (lab, job, `h5p-element` simulation, `worksheet-download` field task) rather than pretending a page is practice.
- **The XR form-factor row does not transfer directly.** Use the fidelity ladder in the element map instead.
- **The framework is proposed, not yet validated.** Its parts rest on established research
  (cognitive load, worked examples, fading, expertise reversal, feedback); cite those, not the framework's ROI claims.

## Dependencies

- **Reads:** site.json / pages (via `hax-site-structure`), optional UbD manifest
- **Hands off to:** `grad-blooms` (objective wording), `hax-feedback-design`, `hax-practice-and-retrieval`,
  `hax-reflection-prompts`, `hax-ubd-stage3` (sequence), `hax-site-building` / `hax-claudehax` (apply)

## References

- `references/elevate-crosswalk.md`: per-stage learner description, objective, verbs, loop, SCAFF settings, reflection and appraisal
- `references/scaff-element-map.md`: each dial and loop step mapped to HAX elements, plus the fidelity ladder
- Stanney, K. M., Skinner, A., & Hughes, C. (2023). ELEVATE-XR. *International Journal of Human–Computer Interaction*, 39(11), 2177–2198. https://doi.org/10.1080/10447318.2023.2188529
- Dreyfus, S. E. (2004). The five-stage model of adult skill acquisition. *Bulletin of Science, Technology & Society*, 24(3), 177–181.
- Kalyuga, S., Ayres, P., Chandler, P., & Sweller, J. (2003). The expertise reversal effect. *Educational Psychologist*, 38(1), 23–31.
- Renkl, A., & Atkinson, R. K. (2003). Structuring the transition from example study to problem solving. *Educational Psychologist*, 38(1), 15–22.
