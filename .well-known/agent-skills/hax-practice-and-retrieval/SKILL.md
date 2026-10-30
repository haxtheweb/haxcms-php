---
name: hax-practice-and-retrieval
description: "Plan retrieval practice, spacing, interleaving and deliberate practice across a HAX site so learning lasts. Use for \"learners forget this\", \"add review\", \"build practice\" or a unit where each concept appears only once."
version: 1.0.0
license: Apache-2.0
metadata:
  author: PRAW
  tags: [instructional-design, retrieval-practice, spacing, interleaving, deliberate-practice, retention, hax]
  requirements: "A HAX site path (site.json + pages/*.html) or a unit outline with its concepts. Emits a practice map; does not edit pages."
---

# HAX Practice and Retrieval

Most course pages teach a concept once and move on. Durable learning comes from **pulling it back
out of memory** (retrieval practice), **spread over time** (spacing), **mixed with similar ideas**
(interleaving) and **aimed at weaknesses with feedback** (deliberate practice). These are among the
best-supported findings in learning science. This skill maps every key concept in a HAX unit to
later retrieval points and picks the HAX elements that deliver them.

It is the "Repeat" step at the end of every ELEVATE-XR learning loop
(`hax-proficiency-scaffolding`), made concrete.

## When to Use

**Trigger conditions:**
- "Learners forget this by next week" / "add review" / "build in practice"
- A unit where each concept is checked once, on the page that teaches it, or never
- Designing a final review, a study page, or flash-card decks
- After `hax-ubd-stage3` sequences a unit

**When NOT to use (with redirect):**
- Feedback wording on each item → `hax-feedback-design`
- Whether an item is at the right Bloom's level → `grad-blooms`
- Summative evidence of understanding → `hax-ubd-stage2`

## Scope

Non-mutating. Emits a concept-by-page practice map and the items to add. Apply with
`hax-site-building` / `hax-claudehax`.

## Methodology

1. **List the concepts.** From the unit's objectives (UbD Stage 1 if present) and page headings,
   list the facts, concepts and procedures learners must keep. Note the page where each is taught.
2. **Retrieval on first teaching.** Each concept gets one low-stakes retrieval item on its own page,
   at least after the explanation (a pre-question before it can also help), with feedback.
3. **Space it.** Schedule each concept to come back at growing distances: roughly the next page or
   two, again a few pages later, and in the unit review. HAX has no scheduler, so page distance
   stands in for time; where the course runs on a calendar, put review pages at about a week and
   about a month after first teaching.
4. **Interleave once concepts are distinct.** After learners can tell concepts apart one at a time,
   mix them in review sets so the learner must choose which idea applies (blocked practice first,
   interleaved later). Use `randomize` on choice items, and mix cards from several pages into a `flash-card-set` by hand (the set does not shuffle).
5. **Make retrieval effortful but successful.** Prefer recall (`short-answer-question`,
   `fill-in-the-blanks`, `self-check`) over recognition (`multiple-choice`) once the learner is past
   novice. Aim for most attempts to succeed with effort; if almost all fail, add support upstream.
6. **Deliberate practice for skills.** For procedures, give repeated attempts on the hardest step
   in isolation, with feedback, then reassemble the whole task. Use `concept-quest-game` (its recap
   lists tries and what to review) or several varied cases checked with question elements.
7. **Close the loop.** End each unit with a cumulative review page that samples every concept, and
   a `self-reflective-nudge` pointing low scorers back to specific pages.
8. **Emit** the practice map.

## HAX element map

| Purpose | Elements |
|---|---|
| Quick recall | `flash-card`, `flash-card-set`, `self-check`, `fill-in-the-blanks`, `short-answer-question` |
| Recognition (novices) | `multiple-choice`, `true-false-question`, `matching-question` |
| Discrimination / interleaving | `tagging-question`, `sorting-question`, `concept-runner-game` (good vs bad concepts at speed), `multiple-choice` with `randomize` mixing items from several pages |
| Game-based retrieval with review | `concept-quest-game` (wrong answers cost a heart; recap shows tries and what to review) |
| Routing to review | `self-reflective-nudge` with `data-link` to the page to revisit |
| Review page structure | `a11y-collapse` (with `heading-button`) per concept, each holding its retrieval items |

## Output Format

```
# Practice map: {unit}
| Concept | Taught on | Retrieval 1 (same page) | Retrieval 2 | Retrieval 3 | Unit review | Element |
## Interleaved sets
{review set name → concepts mixed → elements}
## Deliberate practice
{skill → isolated hard step → items → reassembly}
## Gaps found (existing site)
- {concept} appears only on {page}; never retrieved again
## Handoff
{items to write via hax-feedback-design; apply via hax-site-building}
```

## Acceptance criteria

- Every key concept has at least three retrieval points, at least one of them on a later page.
- Interleaving appears only after each mixed concept has been practiced on its own.
- Every retrieval item has feedback (via `hax-feedback-design`).
- A cumulative review closes the unit.

## Gotchas

- **Re-reading is not retrieval.** A summary page does not count; the learner must produce or choose an answer.
- **Do not interleave too early.** Novices mixing concepts they cannot yet tell apart just get confused.
- **Spacing feels worse.** Learners rate massed practice as more effective while it is happening; say so if the user worries about difficulty.
- **Volume claims.** Do not promise hour targets. Deliberate practice matters, but Macnamara et al. (2014) found it explains far less of performance than the 10,000-hour rule suggests.

## References

- Roediger, H. L., & Karpicke, J. D. (2006). Test-enhanced learning. *Psychological Science*, 17(3), 249–255.
- Cepeda, N. J., et al. (2006). Distributed practice in verbal recall tasks. *Psychological Bulletin*, 132(3), 354–380.
- Rohrer, D., & Taylor, K. (2007). The shuffling of mathematics problems improves learning. *Instructional Science*, 35, 481–498.
- Dunlosky, J., et al. (2013). Improving students' learning with effective learning techniques. *Psychological Science in the Public Interest*, 14(1), 4–58.
- Ericsson, K. A., Krampe, R. T., & Tesch-Römer, C. (1993). The role of deliberate practice. *Psychological Review*, 100(3), 363–406.
- Macnamara, B. N., Hambrick, D. Z., & Oswald, F. L. (2014). Deliberate practice and performance. *Psychological Science*, 25(8), 1608–1618.
- Bjork, R. A. (1994). Memory and metamemory considerations in the training of human beings. In J. Metcalfe & A. P. Shimamura (Eds.), *Metacognition: Knowing about knowing* (pp. 185–205). MIT Press.
