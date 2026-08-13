# UbD Design Standards Checklist

Used by `hax-ubd-backward-design` (self-review step) and `hax-ubd-unit-audit`
(diagnostic step). Each standard maps to a manifest field so it can be checked
mechanically, not just by feel.

A unit is **Aligned** only if every standard passes. **Partial** = goals present
but evidence or sequence incomplete. **Activity-oriented** = lots of activities,
no aligned evidence (twin sin #1). **Coverage-bound** = content march, no big
ideas / no depth (twin sin #2).

## The 12 standards

1. **Big-idea focus present, visible, transferable.**
   Manifest: `stage1.bigIdeas` non-empty; each idea transfers beyond this unit.
   Fail signal: `bigIdeas` empty, or items are unit-specific topics.

2. **Enduring understandings are full-sentence propositions.**
   Manifest: every `stage1.understandings[*]` starts with "Students will
   understand that…" and is a proposition, not a topic or a fact.
   Fail signal: noun phrases ("The Civil War"), or facts dressed as
   understandings.

3. **Overarching + topical essential questions present and genuinely open.**
   Manifest: `stage1.essentialQuestions.overarching` and `.topical` both
   non-empty; each Q has no single right answer.
   Fail signal: a Q with one correct answer (that is a *leading* question →
   reclassify to K/S).

4. **Predictable misunderstandings named, pre-assessed, AND confronted.**
   Manifest: `stage1.misunderstandings[*]` has `misconception`, `preAssess`,
   and `confront` all populated.
   Fail signal: misconceptions named but never pre-assessed, or never
   confronted during instruction.

5. **Stage 1 ↔ Stage 2 alignment (evidence targets every understanding).**
   Manifest: for every `stage1.understandings[*]`, at least one
   `stage2.performanceTasks[*].targetsUnderstanding` (or an academic prompt)
   references it verbatim.
   Fail signal: an understanding with no evidence, or evidence with no
   targeted understanding.

6. **≥1 GRASPS performance task; marked problem-not-exercise.**
   Manifest: `stage2.performanceTasks` has ≥1 entry with
   `isProblemNotExercise: true` and all six GRASPS letters filled.
   Fail signal: only quizzes/exercises, or a GRASPS task that is really an
   exercise (single right approach, no cues-free problem).

7. **Six-facet breadth in evidence; rubric criteria facet-mapped.**
   Manifest: `stage2.rubric.criteria` each map to a named facet; evidence
   across the unit spans >1 facet where the understandings warrant it.
   Fail signal: rubric scores only correctness/presentation; all evidence is
   a single facet (e.g., all Explanation, no Application).

8. **Validity self-test passes for each assessment.**
   Manifest: `stage2.validitySelfTest` has all seven answers "very-unlikely"
   and `defects` is empty.
   Fail signal: any answer "likely" (see `validity-self-test.md`).

9. **Reliability: multiple measures over time (not one snapshot).**
   Manifest: `stage2.longitudinalEvidence` non-empty; evidence collected
   across the unit, not one end-of-unit test.
   Fail signal: a single high-stakes test as the only evidence.

10. **WHERETO sequence present; Exhibit references a real Stage 2 task.**
    Manifest: `stage3.whereto.exhibit.taskRef` resolves to a real
    `stage2.performanceTasks[*].id`; `stage3.sequence` non-empty.
    Fail signal: Exhibit invents a new endpoint, or no sequence.

11. **Uncoverage over coverage.**
    Manifest/inspection: depth via progressive disclosure (`a11y-collapse` /
    `a11y-tabs` with `heading-button`), not a page-by-page textbook march.
    Fail signal: every standard sub-topic gets equal shallow treatment.

12. **Cross-stage triangulation (Stage 1 ↔ 2 ↔ 3 coherent).**
    Manifest: `validation.alignmentTriangulated` true — every Stage 3 activity
    either equips for a Stage 2 performance or is an Explore/Equip with a
    stated Stage 1 justification; no orphan activities.
    Fail signal: activities that do not serve any Stage 1/2 target (the
    activity-oriented twin sin).

## Rating → diagnosis mapping

| Rating | Meaning | Next action |
|---|---|---|
| Aligned | All 12 pass | Publish / teach |
| Partial | Goals present, evidence or sequence incomplete | Run the missing stage skill |
| Activity-oriented | Lots of activities, weak/no aligned evidence (twin sin #1) | Rebuild Stage 2 from Stage 1 |
| Coverage-bound | Content march, no big ideas / no depth (twin sin #2) | Rebuild Stage 1 (find the big ideas) |

"Coverage" is a *negative* term; "survey"/"overview" is legitimate. Do not flag a
purposeful, transparent overview as coverage-bound — coverage is content march
with no overarching intellectual purpose.
