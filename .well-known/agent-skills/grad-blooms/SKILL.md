---
name: grad-blooms
description: "Classify learning objectives and design assessments with Bloom's revised taxonomy (six cognitive levels). Use for writing objectives at a level, aligning assessment to goals, or checking a curriculum's cognitive balance."
metadata:
  tags: ["education", "blooms-taxonomy", "learning-objectives", "assessment", "hax"]
---

# Bloom's Revised Taxonomy

## Overview

Bloom's revised taxonomy (Anderson & Krathwohl, 2001) classifies cognitive processes into six hierarchical levels: Remember, Understand, Apply, Analyze, Evaluate, and Create. Combined with the knowledge dimension (factual, conceptual, procedural, metacognitive), it provides a two-dimensional framework for designing and assessing learning.

## When to Use

**Trigger conditions:**
- Writing learning objectives at specific cognitive levels
- Aligning assessment methods with intended learning outcomes
- Auditing curriculum for cognitive complexity balance

**When NOT to use (with redirect):**
- Deciding which level fits the learners and how much support to give → `hax-proficiency-scaffolding`
- Cognitive load on a page → `hax-content-chunking-audit` (length) or `hax-multimedia-principles-audit` (media)
- Writing feedback for the assessment items → `hax-feedback-design`
- Unit-level goals and evidence → `hax-ubd-stage1` / `hax-ubd-stage2`

## Assumptions

```
IRON LAW: Higher-Order Thinking REQUIRES a Foundation of Lower-Order Knowledge

You cannot analyze what you don't understand. You cannot evaluate
what you haven't analyzed. You cannot create without evaluation criteria.
The hierarchy is:
  Remember → Understand → Apply → Analyze → Evaluate → Create
Skipping levels produces superficial "higher-order" work built on
a weak knowledge foundation.
```

## Methodology

### Step 1: Identify Knowledge Type
Classify the target knowledge: factual (terminology, details), conceptual (categories, principles), procedural (how-to, techniques), or metacognitive (self-awareness, strategies).

### Step 2: Select Cognitive Level
Choose the appropriate cognitive process level. Use action verbs that are observable and measurable for each level.

### Step 3: Write Objectives
Combine: "Students will be able to [action verb] [knowledge content] [context/condition]." Ensure the verb matches the intended cognitive level.

### Step 4: Align Assessment
Match assessment methods to the cognitive level. Remember/Understand → objective tests. Apply/Analyze → case studies, problem sets. Evaluate/Create → projects, portfolios, essays.

### Step 5: Check the Level Against the Learners
The right level depends on how far along the learners are with this skill. ELEVATE-XR (Stanney, Skinner & Hughes, 2023) maps the Dreyfus skill stages onto Bloom's:

| Learner stage | Target level | Knowledge focus |
|---|---|---|
| Novice | Remember | Facts, concepts, steps of procedures |
| Advanced beginner | Understand | Organizing facts and steps into mental models; why steps come in order |
| Competent | Apply | Using procedures on new, realistic cases |
| Proficient | Analyze | Breaking situations into parts, relating them, generalizing |
| Expert | Evaluate / Create | Judging approaches, inventing new solutions, metacognition |

An objective two or more levels above the learners' stage is a red flag; so is a Remember objective for experts. For the full design consequences (learning loop, support, feedback), hand off to `hax-proficiency-scaffolding`.

## Output Format

```markdown
# Learning Objectives Analysis: {Course/Module}

## Taxonomy Mapping
| Objective | Cognitive Level | Knowledge Type | Action Verb | Assessment Method |
|-----------|----------------|---------------|-------------|-------------------|
| ... | Remember/Understand/Apply/Analyze/Evaluate/Create | Factual/Conceptual/Procedural/Metacognitive | ... | ... |

## Cognitive Level Distribution
- Lower-order (Remember, Understand, Apply): {count, %}
- Higher-order (Analyze, Evaluate, Create): {count, %}
- Balance assessment: {adequate or needs adjustment}

## Alignment Check
- Objectives ↔ Instruction: {aligned / gaps}
- Objectives ↔ Assessment: {aligned / gaps}

## Recommendations
{Specific suggestions for improving cognitive level balance and alignment}
```

## HAX Assessment Elements by Level

Use real, HAX-authorable elements (check `wc-registry.json`); never invent tags.

| Level | HAX elements |
|---|---|
| Remember | `multiple-choice`, `true-false-question`, `matching-question`, `flash-card`, `fill-in-the-blanks` |
| Understand | `tagging-question` / `sorting-question` (classify, order), `self-check` ("explain why", then reveal a model explanation to compare); `short-answer-question` only for short exact answers, since it matches the authored answer exactly |
| Apply | `multiple-choice` (or `short-answer-question` for an exact value) on a new case, `h5p-element` simulation, `worksheet-download` task |
| Analyze | `sorting-question` / `matching-question` on relationships or causes, `image-compare-slider` (compare two cases), case analysis with an `editable-table` rubric |
| Evaluate | Critique of a worked solution scored with an `editable-table` rubric; `self-check` revealing an expert critique |
| Create | GRASPS performance task (`hax-ubd-grasps`), presented with `clean-portfolio-theme` / `glossy-portfolio-theme` |

All question elements share feedback slots (`feedbackIncorrect`, `feedbackCorrect`, `hint`, `evidence`); write them with `hax-feedback-design`.

## Gotchas

- **Verbs are ambiguous**: "Understand" is not directly observable. Use specific verbs: "explain," "classify," "summarize." Multiple taxonomies map verbs to levels — they don't always agree.
- **Hierarchy is not rigid**: The revised taxonomy acknowledges that the order between Evaluate and Create can vary. Some creative tasks don't require prior evaluation, and some evaluation doesn't require creation.
- **Higher ≠ better**: Not all objectives should be at the Create level. Foundational courses legitimately emphasize Remember and Understand. The goal is APPROPRIATE level, not maximum level.
- **Culture of verb-matching**: Swapping in a "higher" verb without changing the actual cognitive demand is cosmetic. "Analyze the definition" is still Remember if students just recite a memorized analysis.
- **Affective and psychomotor domains**: Bloom's cognitive taxonomy is one of THREE domains. It doesn't address attitudes/values (affective) or physical skills (psychomotor).

## References

- For the complete verb list by level, see `references/verb-taxonomy.md`
- For alignment matrices and assessment design, see `references/alignment-matrix.md`
- Stage-to-level mapping: Stanney, K. M., Skinner, A., & Hughes, C. (2023). ELEVATE-XR. *International Journal of Human–Computer Interaction*, 39(11), 2177–2198. https://doi.org/10.1080/10447318.2023.2188529
