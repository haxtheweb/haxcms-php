---
name: hax-ubd-stage1
description: >
  UbD Stage 1 — gain clarity on desired results: unpack standards into big ideas,
  enduring understandings (full-sentence propositions), overarching + topical
  essential questions, knowledge/skills, and predictable misunderstandings.
  Use when the user says "what are the big ideas in X", "write enduring
  understandings", "craft essential questions", "unpack this standard", or
  "what misconceptions should I anticipate". Encodes UbD Ch. 3–6.
version: 1.0.0
license: MIT
metadata:
  author: PRAW
  tags: [instructional-design, understanding-by-design, ubd, stage1, big-ideas, essential-questions, misconceptions, hax]
  requirements: "A standard, topic, or existing content to unpack. Writes manifest.stage1 and OER schema + page-top stop-note/self-check blocks."
---

# HAX UbD Stage 1 — Gaining Clarity on Goals

Unpack standards/topics into the desired results that anchor a backward-designed
unit: **big ideas**, **enduring understandings**, **essential questions**,
**knowledge/skills**, and **predictable misunderstandings**. This is Stage 1 of
UbD — everything downstream (evidence, then learning plan) is derived from here.

## When to Use

**Trigger conditions:**
- "What are the big ideas in X" / "write enduring understandings" / "craft essential questions"
- "Unpack this standard" / "turn this topic into goals" / "what misconceptions should I anticipate"
- You are `hax-ubd-backward-design` and have reached step 3 of its methodology

**When NOT to use (with redirect):**
- Picking the cognitive verb/level of an objective → `grad-blooms` (this skill frames the *goal*; Bloom's picks the *level* — they compose)
- Designing assessments → `hax-ubd-stage2`
- Sequencing activities → `hax-ubd-stage3`
- Page-level readability → `hax-content-chunking-audit`

## Scope

**Mutating.** Writes `manifest.stage1` plus OER schema fields and page-top
`stop-note`/`self-check` blocks. Does NOT design assessments (Stage 2) or
sequence learning (Stage 3).

## Inputs

- `source`: a standard text, topic phrase, or existing content
- optional `courseContext.programBigIdeas`: program-level big ideas this unit nests under

## Methodology

1. **Unpack standards** → classify each item into the three-ring priority filter (UbD Fig 3.3):
   - *Worth being familiar with* (outer ring — background, not the focus)
   - *Important to know & do* (middle ring — prerequisites, skills)
   - *Worthy of enduring understanding* (inner ring — the focus of this unit)
   Only the inner ring becomes understandings. The middle ring feeds K/S. The outer ring is context, not a target.

2. **Name big ideas** — linchpin concepts that are transferable beyond this unit (e.g., "form follows function," "rule of law," "supply and demand"). Test each: *does it transcend this specific topic?* If it only matters inside this unit, it is not (yet) a big idea.

3. **Write enduring understandings** as full-sentence propositions using the stem "Students will understand that…". Test each against the examples/non-examples (UbD Fig 6.1):
   - ✅ Proposition, not a topic ("Civil war tests whether founding ideals survive internal fracture" — not "The Civil War")
   - ✅ Requires inference, not just recall
   - ✅ Transferable to new situations
   - ❌ A fact dressed as an understanding ("Magna Carta was signed in 1215")
   - ❌ A topic noun phrase ("photosynthesis")

4. **Derive essential questions** — **overarching** (recur across the course/program) + **topical** (unit-specific). Test each: open (no single right answer), provocative, points at a big idea, arguable by thoughtful people. Add student-friendly **entry questions** that open lessons and point toward the EQs. (For the full starter bank, see the `hax-ubd-essential-questions` companion.)

5. **List Knowledge / Skills (K, S)** — distinguish *prerequisite* K/S (needed to perform the culminating task) from *resultant* K/S (the goal of the unit). Use `grad-blooms` to pin the cognitive level/verb of each skill.

6. **Name predictable misunderstandings** — the heart of the method. For each misconception the topic is prone to, specify:
   - `preAssess`: a HAX component + placement to *catch* it before/during instruction
   - `confront`: a HAX component + placement to *evict* it where the idea is taught
   UbD's central claim: teaching for understanding must *anticipate, evoke, and overcome* the most likely misconceptions.

## HAX Component Map (Stage 1)

| Output | Component | Placement |
|---|---|---|
| Goals / learning outcomes (G) | `oer-schema` (`learningOutcome`) | page metadata |
| Big ideas | heading structure + `oer-schema` | page framing |
| Enduring understandings (U) | "Students will understand that…" block + `oer-schema` | page body, visible |
| Essential / entry questions (Q) | `stop-note` (keep open, not collapsed) | page top |
| Knowledge / Skills (K, S) | `editable-table` | page body |
| Misconception pre-assessment | `self-check`, `multiple-choice` | page top (before instruction) |
| Misconception confrontation | `stop-note` + `self-check` | inline where the idea is taught |

Full map (all stages): `../hax-ubd-backward-design/references/ubd-element-map.md`.

## Output Format

```
# Stage 1 — Desired Results: {unit}
## Priority filter
{each standard → outer / middle / inner ring}
## Big ideas
- {linchpin, transferable}
## Enduring understandings (propositions)
- Students will understand that ...
## Essential questions
- Overarching: {recur across course}
- Topical: {unit-specific}
- Entry: {student-friendly openers}
## Knowledge / Skills
- K (prerequisite): ... | K (resultant): ...
- S (prerequisite): ... | S (resultant): ... [cognitive level via grad-blooms]
## Predictable misunderstandings
- {misconception} → pre-assess: {component + placement}; confront: {component + placement}
## HAX handoff
- OER schema fields: {learningOutcome = U; educationalAlignment = G}
- Page-top blocks: {stop-note carrying Q + entry Q}
- Pre-assessment: {self-check / multiple-choice targeting each misconception}
```

## Acceptance criteria

- Every U is a full sentence starting with "Students will understand that…" — not a topic or a fact.
- Every Q is genuinely open. If a Q has one right answer, reclassify it as a *leading* question and move it to K/S.
- Both overarching AND topical EQs are present (overarching recur beyond the unit; topical are unit-specific).
- ≥1 predictable misunderstanding with BOTH a `preAssess` and a `confront` component specified.
- Every big idea transfers beyond this unit (if `courseContext.programBigIdeas` is given, each big idea nests under one).

## Gotchas

- **"The Civil War" is a topic, not an understanding.** "Civil war tests whether a nation's founding ideals can survive internal fracture" is an understanding. If you wrote a noun phrase, rewrite it as a proposition.
- **Don't smuggle activities into K/S.** Skills describe what the *learner* can do, not what the teacher will do.
- **Overarching ≠ topical.** If an EQ only matters for this unit, it's topical. Overarching EQs must recur across multiple units — they are the program-level threads.
- **Misconceptions are not "common mistakes."** They are *predictable, conceptually coherent* wrong ideas (e.g., "evolution is purposeful"). Name the *idea*, not just the error.
- **Bloom's composes, it doesn't replace.** Use `grad-blooms` to choose the verb/level for each S — but Stage 1's job is the *goal framing* (big idea → understanding → EQ), which Bloom's doesn't do.

## Dependencies

- **Reads:** `grad-blooms` (cognitive level/verb for each S); `courseContext.programBigIdeas` if available
- **Hands off to:** `hax-ubd-stage2` (which reads `manifest.stage1`)
- **Companion:** `hax-ubd-essential-questions` (EQ starter bank)

## References

- `../hax-ubd-backward-design/references/manifest-schema.md` — `stage1` fields
- `../hax-ubd-backward-design/references/ubd-element-map.md` — Stage 1 component map
- `../hax-ubd-backward-design/references/design-standards.md` — standards 1–4
- PRAW RULES.md: `~/Documents/git/haxtheweb/praw/RULES.md`
- Source: Wiggins & McTighe (2005), *UbD* Ch. 3–6.
