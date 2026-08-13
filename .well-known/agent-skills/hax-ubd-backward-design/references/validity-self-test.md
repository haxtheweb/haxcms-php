# UbD Assessment Validity Self-Test

After Wiggins & McTighe, *Understanding by Design* (Expanded 2nd ed.), Figure 8.5.
Run this on every proposed Stage 2 assessment. **Goal: make every answer
"very unlikely."** Any "likely" answer is a defect that must be redesigned before
the assessment is accepted.

The test guards the core UbD distinction: an assessment can be *interesting*,
*fun*, *hands-on*, and *well-graded* and still fail to provide valid evidence of
the targeted understanding. Validity is about what you can *infer* from the
evidence, not about whether the task is engaging.

## The seven questions

### Could a student do WELL on the assessment by…

**Q1 — Guessing:** Making clever guesses based on limited understanding?
**Q2 — Parroting:** Parroting back or plugging in what was learned, with accurate
recall but limited or no understanding?
**Q3 — Effort:** Making a good-faith effort, with lots of hard work and
enthusiasm, but with limited understanding?
**Q4 — Lovely product:** Producing lovely products and performances, but with
limited understanding?
**Q5 — Articulate intelligence:** Applying natural ability to be articulate and
intelligent, with limited understanding of the content in question?

### Could a student do POORLY on the assessment by…

**Q6 — Irrelevant task:** Failing to meet the performance goals despite having a
deep understanding of the big ideas? (e.g., the task is not relevant to the goals.)
**Q7 — Arbitrary criteria:** Failing to meet the scoring/grading criteria used,
despite having a deep understanding of the big ideas? (e.g., some criteria are
arbitrary, placing undue emphasis on things unrelated to the desired results.)

## Answer scale

For each question: `very-unlikely | unlikely | likely | very-likely`.

**Pass condition:** all seven = `very-unlikely` (accept "unlikely" as a yellow
flag worth noting, but only `very-unlikely` is a clean pass).

## Defect → redesign patterns

| Failing question | Root cause | Redesign |
|---|---|---|
| Q1 (guessing) | Selected-response only; items too easy to eliminate | Add constructed response (`simple-fields`) requiring justification; require "show your work" |
| Q2 (parroting) | Task cues the exact procedure/fact taught | Make the task a *problem* not an *exercise* (Fig 7.6): remove cues, use a novel context |
| Q3 (effort) | Rubric rewards effort/completion over understanding | Reframe rubric criteria around facets + impact, not completion/length |
| Q4 (lovely product) | Polish/presentation over-weighted | Split rubric: separate "understanding" criteria from "craft" criteria; weight understanding higher |
| Q5 (articulate) | Verbal fluency mistaken for understanding | Require transfer: have the student apply the explanation to a *new* problem/situation |
| Q6 (irrelevant task) | Task doesn't actually target the Stage 1 understanding | Rewrite the task so `targetsUnderstanding` is explicit and the performance reveals it |
| Q7 (arbitrary criteria) | Criteria measure things unrelated to the goals | Drop criteria not tied to a Stage 1 target; keep only facet-aligned criteria + impact |

## Reliability reminder

Validity is necessary but not sufficient. Also confirm **reliability**: the
assessment is part of a *scrapbook* of multiple measures over time
(`progress-donut` / `simple-progress` / `promise-progress` + `grade-book`), not a
single high-stakes snapshot. One valid task can still produce an anomalous
result; a pattern of evidence is what justifies the inference that a student
*understands*.

## Facet coverage check (companion to validity)

For each targeted understanding, confirm the evidence taps the facets that most
strongly reveal it. Selected-response (`multiple-choice`, `fill-in-the-blanks`,
`matching-question`, `sorting-question`, `tagging-question`) is "insufficient and
sometimes misleading" for understanding on its own — every understanding needs at
least one performance-based evidence source (GRASPS task, academic prompt via
`simple-fields`, or a facet-specific performance).
