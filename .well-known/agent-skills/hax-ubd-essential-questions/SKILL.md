---
name: hax-ubd-essential-questions
description: >
  Author UbD essential questions and entry questions from the six-facet question
  starter bank. Use when the user says "write essential questions", "good
  questions for X", "craft an essential question", or "what entry question opens
  this unit". Distinguishes overarching vs. topical and essential vs. leading.
  Companion to hax-ubd-stage1; sibling to grad-blooms.
version: 1.0.0
license: MIT
metadata:
  author: PRAW
  tags: [instructional-design, understanding-by-design, ubd, essential-questions, inquiry, hax]
  requirements: "A topic, big idea, or enduring understanding to build questions for. Emits stop-note blocks for page placement."
---

# HAX UbD Essential Questions

Author **essential questions** (overarching + topical) and **entry questions**
using the UbD six-facet question-starter bank (UbD Ch. 5, Fig 5.3). This is the
focused companion to `hax-ubd-stage1` (which owns the full Stage 1 workflow) —
use it when the user specifically needs questions authored or sharpened.

## The core distinction

An **essential question** lies at the heart of a subject, promotes inquiry and
*uncoverage*, and yields different plausible responses about which thoughtful
people may disagree. A **leading question** has a correct, straightforward
answer — useful for teaching and checking knowledge, but NOT an EQ. If your
question has one right answer, it is leading; reclassify it and move it to K/S.

- **Overarching EQs** recur across the course/program (e.g., "Is history a story
  or a set of competing accounts?"). They are the program-level threads.
- **Topical EQs** are unit-specific (e.g., "Why did the pioneers leave their
  homes to head west?"). They frame a single unit.
- **Entry questions** are student-friendly, provocative lesson openers that
  point toward the EQs. Maximal simplicity, provocation value, student-friendly
  language.

## When to Use

- "Write essential questions for X" / "good questions for X" / "craft an EQ"
- "What entry question opens this unit" / "sharpen this question"
- You are `hax-ubd-stage1` and have reached the EQ step (step 4)

**When NOT:** the full Stage 1 (big ideas + understandings + K/S + misconceptions)
→ `hax-ubd-stage1`. Cognitive level of a question → `grad-blooms`.

## Methodology

1. **Start from the big idea / enduring understanding.** An EQ must point at a
   named big idea — it is not a free-floating provocation.
2. **Pick a facet lens** to seed the question (six-facet starter bank below).
3. **Draft the question.** Test it against the four EQ criteria (open, provocative,
   points at a big idea, arguable by thoughtful people).
4. **Classify** as overarching or topical. Add a student-friendly entry question
   that opens toward it.
5. **Render** as `stop-note` blocks (keep EQs open/visible, not collapsed) at the
   top of the relevant page(s).

## Six-facet question starter bank (after UbD Fig 5.3)

| Facet | Question stems |
|---|---|
| Explanation | Why is that so? What explains such events? How can we prove it? To what is this connected? How does this work? What is implied? |
| Interpretation | What does it mean? Why does it matter? What of it? How does it relate to me? What makes sense? |
| Application | How and where can we use this knowledge/skill? How should my thinking change to meet this situation? |
| Perspective | From whose point of view? What is assumed that needs to be made explicit? Is there adequate evidence? What are the limits? So what? |
| Empathy | What do they see that I don't? What would it feel like to be…? What was the writer/artist feeling, thinking, seeing? |
| Self-Knowledge | How does who I am shape my views? What are the limits of my understanding? What are my blind spots? |

## Output Format

```
# Essential Questions: {unit / topic}
## Big idea targeted
{the big idea the EQs point at}
## Overarching EQs (recur across course)
- {question} [facet: ...]
## Topical EQs (unit-specific)
- {question} [facet: ...]
## Entry questions (student-friendly openers)
- {question}
## HAX handoff
- stop-note (open) per EQ at page top
```

## Acceptance criteria

- Every EQ is genuinely open (no single right answer) — if it has one, reclassify as leading → K/S.
- Every EQ points at a named big idea.
- Both overarching AND topical EQs present (unless the user explicitly scoped to one).
- ≥1 entry question per unit, in student-friendly language.

## Gotchas

- **Leading ≠ essential.** "What are the three branches of government?" is leading (one right answer). "How should power be divided among branches to prevent tyranny?" is essential.
- **Provocation is not enough.** A catchy question that doesn't point at a big idea is a hook, not an EQ.
- **Overarching EQs must recur.** If an EQ only matters for this unit, it's topical — don't inflate it to overarching.

## Dependencies

- **Reads:** the big idea / understanding (from `hax-ubd-stage1`)
- **Sibling:** `grad-blooms` (cognitive level — EQs are open/arguable, not single-answer)
- **Used by:** `hax-ubd-stage1`

## References

- `../hax-ubd-backward-design/references/ubd-element-map.md` — Stage 1 component map
- Source: Wiggins & McTighe (2005), *UbD* Ch. 5, Fig 5.2–5.3.
