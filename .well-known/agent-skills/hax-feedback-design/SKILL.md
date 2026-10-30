---
name: hax-feedback-design
description: "Write and audit feedback, hints and evidence for HAX question elements, matched to the learner's stage (right/wrong → explanation → advice → strategy hint → delayed). Use for \"improve my quiz feedback\", \"add hints\" or empty feedback slots."
version: 1.0.0
license: Apache-2.0
metadata:
  author: PRAW
  tags: [instructional-design, feedback, formative-assessment, hints, questions, hax, audit]
  requirements: "HAX page HTML (pages/*.html) or a site path. Audit mode is read-only and code-computable; write mode returns slot markup for the user or hax-site-building to apply."
---

# HAX Feedback Design

Feedback is among the most powerful influences on learning, and it can also make learning
worse (about a third of feedback interventions in Kluger & DeNisi's meta-analysis lowered performance) when it is vague, praises the person instead of the work, or hands the answer to someone who
should be working it out. HAX question elements already have slots for each kind of feedback; most
authored questions leave them empty. This skill fills them in at the right level for the learner,
and audits pages for missing or mismatched feedback.

## When to Use

**Trigger conditions:**
- "Improve the feedback on my quiz" / "add hints" / "my questions just say correct or incorrect"
- After `hax-proficiency-scaffolding` sets a feedback level for a page
- Reviewing a site for questions with empty `feedbackIncorrect`, `hint` or `evidence` slots
- Writing new `multiple-choice`, `true-false-question`, `short-answer-question`, `sorting-question`,
  `matching-question`, `tagging-question`, `fill-in-the-blanks` or `mark-the-words` items

**When NOT to use (with redirect):**
- Choosing which question type or Bloom's level → `grad-blooms`
- Rubric feedback on a performance task → `hax-ubd-stage2` / `hax-ubd-six-facets`
- Where the questions go across the unit → `hax-practice-and-retrieval`

## Scope

- **Audit mode** (read-only): count and classify feedback across pages; every check below can be
  computed from the HTML without AI judgment, so it is suitable for a stats dashboard.
- **Write mode**: return slot markup for each question. Does not edit pages itself; hand the markup
  to `hax-site-building` / `hax-claudehax`.

## The controls HAX gives you

All eight question elements share `QuestionElement`:

| Slot / attribute | When the learner sees it | Use it for |
|---|---|---|
| `slot="feedbackIncorrect"` | After a wrong answer | What was wrong and what to do next |
| `slot="feedbackCorrect"` | After a right answer | Confirm why it is right; extend |
| `slot="hint"` | Only after a wrong answer, under "Need a hint?" (never on request) | A cue or strategy, not the answer |
| `slot="evidence"` | After a right answer | Source, reasoning or worked solution |
| `slot="content"` | Open before answering, collapsed after; hidden in edit mode | Related material to revisit |
| `max-attempts` | Limits tries (unset = unlimited) | Stakes; pair with the feedback level |
| `randomize` | Shuffles answers | Stops position memorizing on repeat attempts |

`self-check` (prompt, then a revealed answer) suits open questions and delayed, compare-with-expert feedback.

## The feedback ladder

| Level (stage) | `feedbackIncorrect` | `hint` | `feedbackCorrect` / `evidence` | `max-attempts` |
|---|---|---|---|---|
| Outcome (novice) | States the correct answer plus one line of why | Optional: point to the part of the page that has it | Restate the fact in one line | Unset |
| Elaborative (advanced beginner) | Why the chosen answer is wrong and why the right one is right; name the likely misconception | Name the rule or concept to apply | Why it is right; link the rule | Unset |
| Facilitative (competent) | Advice or a suggestion ("check the order of X and Y"), invite another try; do not reveal the answer | A question that redirects attention | Brief confirmation plus a transfer question ("where else would this apply?") | 2–3 |
| Strategy (proficient) | Name the strategy that would expose the error, not the error itself | A cue to check or an alternative hypothesis to consider | Ask the learner to explain their reasoning (self-explanation) | 2 (the hint only appears after a wrong answer, so a retry must remain) |
| Delayed (expert) | Leave empty; put a debrief after the task that points out inconsistencies | Leave empty | Leave empty; debrief compares with expert reasoning | 1 |

## Writing rules

1. **About the task, never the person.** "Recheck the units in step 2", not "Great job!" or "You've got this".
   Praise and self-focused feedback can lower performance (Kluger & DeNisi, 1996; Hattie & Timperley, 2007).
2. **Say what to do next.** Every `feedbackIncorrect` ends with an action.
3. **Target the misconception.** For each wrong option, know why someone would pick it.
   `multiple-choice` has one `feedbackIncorrect` slot for all wrong answers, so name the most
   common misconception there. Per-option feedback (`data-selected` / `data-unselected` on each
   answer `input`) is displayed only by `tagging-question` and `mark-the-words`; do not write it
   for other types, it will never show.
4. **Hints never contain the answer** above the novice level.
5. **Keep it short:** two or three sentences per slot. Longer material goes in `content` or a link.
6. **Feedback must be correct.** Check the reasoning against the page's own content and sources.

## Audit checks (code-computable)

For each question element on each page, record:
- empty `feedbackIncorrect` / `feedbackCorrect` / `hint` / `evidence` slots (or slots missing)
- `max-attempts` set or unset; `randomize` on answer-choice items
- hint text that contains a correct answer (for choice items, the `value` of an `input[data-correct]` child) → answer leak
- feedback text addressed to the person ("you're", "great job", "awesome") with no task content

Report per page and per site: counts, percentage of questions with any elaborative feedback, and the
first five offending questions with their page.

## Output Format

```
# Feedback {audit | plan}: {site or page}
## Summary
Questions: {n} | with feedbackIncorrect: {n} | with hint: {n} | answer leaks: {n} | person-focused: {n}
## Per question
- {page} / "{question}" ({element}) — level: {target} — issues: {list}
  ```html
  <div slot="feedbackIncorrect"><p>…</p></div>
  <div slot="hint"><p>…</p></div>
  ```
## Handoff
{apply via hax-site-building / hax-claudehax}
```

## Acceptance criteria

- Every question has a target level, taken from `hax-proficiency-scaffolding` or stated by the user.
- Every wrong-answer path has a next action.
- No hint leaks the answer above the novice level; no feedback is person-focused.

## Gotchas

- **Default messages are outcome-only.** The built-in "Correct"/"Incorrect" text is the novice level; anything higher needs slot content.
- **Delayed feedback still has to happen.** If you leave slots empty for experts, the debrief section is mandatory.
- **Do not set `correct-text` / `incorrect-text` attributes.** They are not observed attributes on the base class; put text in the feedback slots.

## References

- Shute, V. J. (2008). Focus on formative feedback. *Review of Educational Research*, 78(1), 153–189.
- Hattie, J., & Timperley, H. (2007). The power of feedback. *Review of Educational Research*, 77(1), 81–112.
- Kluger, A. N., & DeNisi, A. (1996). The effects of feedback interventions on performance. *Psychological Bulletin*, 119(2), 254–284.
- Fyfe, E. R., & Rittle-Johnson, B. (2016). Feedback both helps and hinders learning. *Journal of Educational Psychology*, 108(1), 82–97.
- Stanney, Skinner & Hughes (2023), ELEVATE-XR, feedback progression by stage. https://doi.org/10.1080/10447318.2023.2188529
- Question base class: https://github.com/haxtheweb/webcomponents/blob/master/elements/multiple-choice/lib/QuestionElement.js
