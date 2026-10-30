---
name: hax-reflection-prompts
description: "Write reflection prompts for HAX pages matched to the learner's stage (summarize → give an example → relate → find contradictions → self-critique). Use for \"add reflection\", \"metacognition\" or a unit with no reflection step."
version: 1.0.0
license: Apache-2.0
metadata:
  author: PRAW
  tags: [instructional-design, reflection, metacognition, experiential-learning, kolb, situated-learning, hax]
  requirements: "A HAX page or unit and the learners' stage (from hax-proficiency-scaffolding or the user). Returns prompt markup; does not edit pages."
---

# HAX Reflection Prompts

Reflection turns an experience into knowledge that transfers (Kolb's experiential cycle; Schön's
reflection-in-action). But the right kind of reflection changes as learners improve: a novice
cannot critique their own hypotheses, and an expert gains nothing from summarizing what they just
read. ELEVATE-XR sets one reflection type per Dreyfus stage. This skill writes prompts of that type
for a HAX page and picks the element that presents them.

## When to Use

**Trigger conditions:**
- "Add reflection" / "get learners to think about their thinking" / "metacognition"
- `hax-proficiency-scaffolding` or `hax-instructional-events-audit` reports a missing reflection step
- A case, simulation, lab or field task needs a debrief

**When NOT to use (with redirect):**
- Checking whether answers are right → `hax-feedback-design`
- Essential questions for a whole unit → `hax-ubd-essential-questions`
- Self-knowledge as an assessed facet of understanding → `hax-ubd-six-facets`

## Scope

Non-mutating. Returns prompts and markup; apply with `hax-site-building` / `hax-claudehax`.

## Prompt types by stage

| Stage | Reflection type | Prompt stems |
|---|---|---|
| Novice | Summarize the gap between desired and actual performance | "What should correct performance look like here? Where did yours differ?" / "Which step did you miss or get out of order?" |
| Advanced beginner | Explain and exemplify desired performance | "Give an example of a situation where this step matters." / "Explain to a new colleague why step 2 comes before step 3." |
| Competent | Relate and transfer | "Why did you choose that action, and what followed?" / "Where else at work would this apply? What would change?" / "What do you still not know how to do?" |
| Proficient | Find contradictions across cases | "How do these two cases differ, and why did the same approach fail in one?" / "Which cue did you ignore that turned out to matter?" |
| Expert | Self-critique and alternatives | "What other explanation fits this evidence?" / "Which of your assumptions would you test first?" / "How would you teach this decision to someone else?" |

Put reflection right after the experience it is about (in action or immediately after), not at the end of a long unit.

## HAX elements

| Use | Element and pattern |
|---|---|
| Prompt, then compare with an expert's reflection | `self-check`: prompt in `slot="question"`, the expert's model reflection as the revealed content |
| Pause-and-think prompt with no answer | `stop-note` with `status="info"` (an unset status shows the red "stop" style) |
| Self-rating that routes to next steps | `self-reflective-nudge` with `input="grade"` or `input="mood"` and nudges that link onward |
| Optional deeper reflection | `a11y-collapse` with `heading-button` ("Going further") |
| Structured debrief of a case | An `editable-table` with columns such as Expected / Actual / Why / Next time |

Note: HAX has no element that stores a learner's free-text reflection. Prompts are for thinking,
not submission. If reflections must be collected, link to the course's LMS assignment and say so.

## Methodology

1. Get the stage (from `hax-proficiency-scaffolding`, or ask what the learners can already do).
2. Find the experience to reflect on: a worked example, a case, a simulation, a question set.
3. Write one or two prompts of the stage's type, specific to that experience (name the case, the step, the decision).
4. Choose the element: use `self-check` when an expert model answer helps (novice to competent), `stop-note` when it would short-circuit thinking (proficient, expert). At those stages, if comparison with an expert is useful, put it in a debrief (`a11y-collapse` with `heading-button`, or the next page) after the learner has committed to their own answer.
5. Place it immediately after the experience.

## Output Format

```
# Reflection prompts: {page}
Stage: {stage} → type: {type}
- After {experience}: "{prompt}"
  ```html
  <self-check title="Reflect"><span slot="question">{prompt}</span>{model reflection}</self-check>
  ```
## Handoff
{apply via hax-site-building}
```

## Acceptance criteria

- Each prompt names the specific experience, not "reflect on this page".
- The prompt type matches the stage table.
- Each prompt sits directly after its experience.

## Gotchas

- **Generic prompts get generic answers.** "What did you learn?" is not a reflection prompt.
- **Model answers can cut thinking short** for advanced learners; leave them out at proficient and expert.
- **Mood is not reflection.** A `self-reflective-nudge` mood check is useful for routing, but pair it with a content prompt.

## References

- Stanney, Skinner & Hughes (2023), ELEVATE-XR, reflection-in-action by stage. https://doi.org/10.1080/10447318.2023.2188529
- Kolb, D. A. (2015). *Experiential Learning* (2nd ed.). Pearson.
- Schön, D. A. (1983). *The Reflective Practitioner*. Basic Books.
- Ertmer, P. A., & Newby, T. J. (1996). The expert learner: strategic, self-regulated, and reflective. *Instructional Science*, 24, 1–24.
