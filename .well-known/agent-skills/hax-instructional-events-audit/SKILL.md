---
name: hax-instructional-events-audit
description: "READ-ONLY audit of a HAX lesson against Gagné's nine events of instruction and Merrill's First Principles, with HAX remediation. Use for \"is this lesson complete\", \"what is missing from this lesson\" or lessons that only present content."
version: 1.0.0
license: Apache-2.0
metadata:
  author: PRAW
  tags: [instructional-design, gagne, nine-events, merrill, first-principles, lesson-design, audit, hax, diagnostic]
  requirements: "A HAX lesson: one page or a small group of pages (site.json + pages/*.html, a JOS node, raw HTML or markdown). Emits a report; does NOT edit pages or site.json."
---

# HAX Instructional Events Audit (Read-Only Diagnostic)

Checks whether a lesson does everything a lesson has to do, not just present information. Uses two
complementary checklists:

- **Gagné's nine events of instruction** (the conditions of learning ELEVATE-XR builds on): what the
  lesson must do, in roughly this order.
- **Merrill's First Principles of Instruction**: whether the lesson is built around a real task and
  moves through activation, demonstration, application and integration.

Scope sits between the other audits: `hax-content-chunking-audit` works on paragraphs and sections,
`hax-ubd-unit-audit` works on whole units, this works on a **lesson** (one page or a few pages that
teach one objective).

## When to Use

**Trigger conditions:**
- "Is this lesson complete?" / "what's missing from this lesson?" / "this page is just reading"
- Reviewing converted content (OpenStax, legacy CMS imports) that presents but never practices
- Before publishing a lesson built by hand or by an agent

**When NOT to use (with redirect):**
- Unit-level alignment of goals, evidence and activities → `hax-ubd-unit-audit`
- Page length and walls of text → `hax-content-chunking-audit`
- Reaching all learners → `hax-udl-audit`
- Whether the support level fits the audience → `hax-proficiency-scaffolding`

## Scope: this skill is READ-ONLY

Diagnoses and recommends only. Never edit `pages/*.html` or `site.json`. Hand fixes to
`hax-site-building` / `hax-claudehax`.

## Inputs

- A lesson: one page, or the pages that teach one objective (give their slugs)
- Optional: the learners' stage, which changes what "guidance" should look like

## Detection heuristics (Gagné)

| # | Event | Present when the lesson has… | HAX remediation |
|---|---|---|---|
| 1 | Gain attention | An opening scenario, question, surprising fact or media before the exposition | `media-image` / `video-player` hook; `stop-note` with a provocative question |
| 2 | Inform of the objective | A stated outcome ("you will be able to…") or `has-learning-objective` / OER Schema objective near the top | `stop-note` with the objective; `oer-schema` learning objective |
| 3 | Stimulate recall of prior learning | A reference back to earlier content, a recap, or a warm-up question | `self-check` or `flash-card` warm-up on the prerequisite; link to the earlier page |
| 4 | Present the content | Explanation, examples, media (almost always present) | — (check chunking instead) |
| 5 | Provide learning guidance | Worked examples, annotated media, cues on what to notice, mnemonics | `lrndesign-imagemap` hotspots, `lrndesign-sidenote`, worked-example steps, `lrn-vocab` |
| 6 | Elicit performance | At least one question or task the learner does | question elements, `self-check`, `h5p-element`, a `worksheet-download` task |
| 7 | Provide feedback | Non-empty feedback slots, or a revealed answer | Fill `feedbackIncorrect` / `feedbackCorrect` / `hint` (see `hax-feedback-design`) |
| 8 | Assess performance | An end-of-lesson check distinct from practice | A short question set at the end; `max-attempts` set |
| 9 | Enhance retention and transfer | Application to a new situation, a summary the learner produces, a later retrieval point | Transfer question on a new case; reflection (`hax-reflection-prompts`); retrieval later (`hax-practice-and-retrieval`) |

## Detection heuristics (Merrill)

| Principle | Present when… |
|---|---|
| Problem-centered | The lesson is framed around a real task or problem the learner will face, shown early |
| Activation | Prior knowledge or experience is called up before new material (event 3) |
| Demonstration | The skill is shown, not only described (worked example, video demonstration) |
| Application | The learner does the skill with feedback and decreasing help (events 6–7) |
| Integration | The learner explains, defends or uses it in their own context (event 9, reflection) |

## Methodology

1. Read the lesson's pages in order (see `hax-site-structure`).
2. For each Gagné event and Merrill principle, mark **Present** / **Weak** / **Missing**, quoting the
   element or sentence that counts as evidence.
3. Note order problems (for example, the assessment comes before any practice; the objective appears at the end).
4. Map each Weak or Missing item to a real HAX remediation from the tables.
5. Rate the lesson: **Complete** (all present) | **Presentational** (events 6–9 missing: the common
   failure) | **Partial** (anything else).

## Output Format

```
### 📊 Instructional Events Diagnostics
* **Rating:** [Complete / Partial / Presentational]
* **Primary gap:** [one sentence]

### 🔍 Gagné's nine events
| # | Event | Status | Evidence | HAX remediation |

### 🔍 Merrill's First Principles
| Principle | Status | Evidence |

### 🛠 Implementation handoff
* [one line per fix → skill / CLI]
```

## Acceptance criteria

- Every status cites evidence from the page or says "none found".
- Every remediation is a real HAX element or a handoff to a named skill.
- Order problems are reported separately from missing events.

## Gotchas

- **Not every page needs all nine.** A lesson spread across three pages can satisfy event 1 on the
  first and event 8 on the last. Audit the lesson, not each page, when the user gives several pages.
- **Feedback must be visible content.** A question with empty feedback slots satisfies event 6 but not event 7.
- **Guidance depends on the stage.** Heavy guidance is right for novices and wrong for experts; if the
  stage is known, apply `hax-proficiency-scaffolding`'s mismatch rules before flagging event 5.

## References

- Gagné, R. M., Wager, W. W., Golas, K. C., & Keller, J. M. (2005). *Principles of Instructional Design* (5th ed.). Wadsworth.
- Merrill, M. D. (2002). First principles of instruction. *Educational Technology Research and Development*, 50(3), 43–59.
- Stanney, Skinner & Hughes (2023), ELEVATE-XR (conditions of learning across proficiency stages). https://doi.org/10.1080/10447318.2023.2188529
