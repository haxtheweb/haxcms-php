---
name: hax-ubd-six-facets
description: >
  Apply the UbD six facets of understanding (Explanation, Interpretation,
  Application, Perspective, Empathy, Self-Knowledge) to write understandings and
  build facet-mapped editable-table rubric criteria. Use when the user says "what
  facet does this target", "build a rubric for understanding", or "which facets
  should this assessment tap". Companion to hax-ubd-stage2; bridges to grad-blooms
  (facets = performance criteria; Bloom's = cognitive level).
version: 1.0.0
license: MIT
metadata:
  author: PRAW
  tags: [instructional-design, understanding-by-design, ubd, six-facets, rubric, understanding, hax]
  requirements: "A targeted understanding or assessment to map to facets. Emits facet-mapped editable-table rubric criteria."
---

# HAX UbD Six Facets of Understanding

Use the six facets as a **lens for writing understandings** and a **blueprint for
rubric criteria**. This is the focused companion to `hax-ubd-stage2`. The facets
are *criteria for judging understanding*, not learning styles — they describe
what teachers need to *see* to conclude a student understands.

## The six facets (UbD Ch. 4)

| Facet | Definition | Key verbs | "A student who really understands…" |
|---|---|---|---|
| Explanation | Sophisticated, justified accounts | explain, justify, verify, prove, generalize, predict | shows their work; gives good reasons; connects facts into a theory |
| Interpretation | Meaningful narratives/translations | interpret, make meaning, tell significance, translate | finds significance; tells a powerful story; makes the unfamiliar accessible |
| Application | Effective use in new contexts | apply, transfer, adapt, customize | uses knowledge wisely in diverse, "noisy" situations; has tact |
| Perspective | Critical points of view | critique, compare, question assumptions, see the big picture | sees from multiple vantage points; exposes questionable assumptions |
| Empathy | Another's feelings/worldview | role-play, relate, walk in another's shoes | finds what is plausible/meaningful in alien-seeming ideas; has a change of heart |
| Self-Knowledge | Awareness of one's own biases/limits | self-assess, reflect, know one's ignorance | knows what they don't understand; knows how their habits shape their thinking |

## When to Use

- "What facet does this target" / "build a rubric for understanding"
- "Which facets should this assessment tap" / "is my rubric measuring understanding or just correctness"
- You are `hax-ubd-stage2` building the rubric blueprint (step 4)

**When NOT:** cognitive level of a task → `grad-blooms` (the facets are performance *criteria*; Bloom's is the cognitive *level* — they compose, see below). Full evidence scrapbook → `hax-ubd-stage2`.

## Methodology

1. **For each targeted understanding**, ask: which facets, if performed well, would most strongly reveal this understanding? (Not all six apply to every understanding — e.g., Self-Knowledge/Empathy rarely apply to pure math concepts.)
2. **Draft rubric criteria** per chosen facet using the facet's verbs. Each criterion should describe observable performance, not an internal state.
3. **Include ≥1 impact criterion** (effect on audience / real-world consequence), not just correctness + craft. Over-weighted craft criteria are the classic validity failure (self-test Q4).
4. **Render** the rubric as an `editable-table` (analytic-trait scoring) — one criterion row per facet tapped, columns = performance levels with anchor descriptions.
5. **Bridge to `grad-blooms`**: confirm the cognitive level implied by each facet criterion matches the intended objective level (e.g., an Application facet criterion implies Apply or higher).

## Facet → evidence component map

| Facet | HAX evidence components |
|---|---|
| Explanation | `simple-fields` (show work/justify) + `editable-table` (criteria) |
| Interpretation | `stop-note` (make meaning) + `simple-fields` |
| Application | GRASPS page + authentic-context media + `simple-fields` |
| Perspective | `image-compare-slider` / `a11y-compare-image` + `simple-fields` |
| Empathy | `stop-note` (role-play) + `simple-fields` + media context |
| Self-Knowledge | `self-check` (self-assess) + `stop-note` (metacognition) |

## Output Format

```
# Six-Facet Rubric: {understanding}
## Facets tapped
{which facets, why — e.g., "Application + Explanation: this understanding is about using the concept and justifying the use"}
## Rubric (editable-table)
| Criterion (facet) | Level 4 (exemplar) | Level 3 | Level 2 | Level 1 |
| {criterion} (Application) | ... | ... | ... | ... |
| {criterion} (Explanation) | ... | ... | ... | ... |
| {impact criterion} | ... | ... | ... | ... |
## Bloom's bridge
{each criterion → implied cognitive level via grad-blooms; confirm match to objective}
## HAX handoff
{editable-table for rubric; per-facet evidence components}
```

## Acceptance criteria

- Each rubric criterion maps to a named facet.
- ≥1 criterion is an **impact** criterion (audience/real-world effect), not just correctness/craft.
- Criteria describe **observable performance** (verbs from the facet), not internal states.
- Understanding/craft criteria are **separate and weighted** so a lovely product with shallow understanding cannot score high (guards self-test Q4).
- Bloom's bridge: each criterion's implied cognitive level matches the intended objective (no Analyze-level criterion on a Remember-level objective, and vice versa).

## Gotchas

- **Not all six facets apply everywhere.** Self-Knowledge and Empathy rarely apply to pure math/conceptual understandings. Don't force a quota — pick the facets that genuinely reveal *this* understanding.
- **Facets are criteria, not styles.** A student need not "have" a facet as a trait; the rubric looks for the performance regardless of preference (like an essay must be persuasive whether or not the writer is persuasive).
- **Don't confuse Explanation (Facet 1) with "Understand" (Bloom L2).** Explanation is a *performance criterion* (justify/show work); Bloom's Understand is a *cognitive level*. The bridge step reconciles them.
- **Beware craft-over-understanding.** A polished product with shallow thinking is the #1 validity trap. Keep understanding criteria distinct and dominant.

## Dependencies

- **Reads:** the targeted understanding (from `manifest.stage1`)
- **Bridges to:** `grad-blooms` (cognitive level ↔ facet)
- **Used by:** `hax-ubd-stage2`

## References

- `../hax-ubd-backward-design/references/ubd-element-map.md` — facet → evidence component map
- `../hax-ubd-backward-design/references/validity-self-test.md` — Q4 (lovely product) is the facet-rubric failure to guard against
- Source: Wiggins & McTighe (2005), *UbD* Ch. 4, Fig 7.8–7.9.
