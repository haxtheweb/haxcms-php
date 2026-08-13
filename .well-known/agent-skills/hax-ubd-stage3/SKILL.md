---
name: hax-ubd-stage3
description: >
  UbD Stage 3 — plan learning experiences via WHERETO, derived from Stages 1+2.
  Enforce uncoverage over coverage and build feedback/revision cycles. Use when
  the user says "sequence this unit", "plan the learning activities", "WHERETO
  my unit", "how do I teach for understanding", or "organize these pages".
  Encodes UbD Ch. 9–10. Stage 3 points AT Stage 2 evidence; it never invents new
  endpoints.
version: 1.0.0
license: MIT
metadata:
  author: PRAW
  tags: [instructional-design, understanding-by-design, ubd, stage3, whereto, uncoverage, learning-plan, hax]
  requirements: "manifest.stage1 AND manifest.stage2 MUST be populated (Stage 3 is derived from goals + evidence). Writes manifest.stage3 + site.json ordering + per-page component plan."
---

# HAX UbD Stage 3 — Planning Learning (WHERETO)

Derive and sequence learning experiences **from** Stages 1 and 2 via **WHERETO**,
enforcing **uncoverage over coverage** and building **feedback + revision
cycles**. This is Stage 3 of UbD — the learning plan. It comes *last* in backward
design because every activity must be justified by the goals (Stage 1) and the
targeted performances (Stage 2).

**The core move:** resist the comfortable-activity reflex. Test every proposed
activity against Stages 1+2 — does it equip learners for the targeted
performances? If not, drop it. "Teaching" (direct instruction) is only one
learning activity among many.

## When to Use

**Trigger conditions:**
- "Sequence this unit" / "plan the learning activities" / "WHERETO my unit"
- "How do I teach for understanding" / "organize these pages"
- You are `hax-ubd-backward-design` and have reached step 5 of its methodology

**When NOT to use (with redirect):**
- Page-level cognitive load / chunking → `hax-content-chunking-audit` (chunking = page scope; WHERETO = unit scope)
- Cognitive level of an objective → `grad-blooms`
- Goals → `hax-ubd-stage1`; evidence/assessments → `hax-ubd-stage2`

## Scope

**Mutating.** Writes `manifest.stage3` + `site.json` page ordering + a per-page
component plan. Does NOT invent new endpoints — Stage 3 points *at* Stage 2's
evidence (the Exhibit references a real `stage2.performanceTasks[*].id`).

## Inputs

- `manifest.stage1` + `manifest.stage2` — **both required**. Stage 3 is derived; do not sequence without goals + evidence.

## Methodology

1. **Resist the comfortable-activity reflex.** For every proposed activity, ask: does it equip learners for a Stage 2 performance, or serve a Stage 1 understanding? If neither, drop it. This is where the twin sin of activity-oriented design is avoided.

2. **Sequence via WHERETO** (UbD Fig 9.1), mapping each letter to HAX (table below). The two E's are distinct: Explore/Equip (build knowledge + coach skills) vs. Exhibit/Evaluate (the final performance).

3. **Uncoverage over coverage.** Use progressive disclosure (`a11y-collapse` with `heading-button`, `a11y-tabs`) for depth. Never page-march a textbook. Coverage = "teaching by mentioning it"; uncoverage = going into depth on the big ideas. (A purposeful *survey* is legitimate; coverage is not.)

4. **Feedback + revision cycles.** Insert `self-check` / `stop-note` loops (Rethink/Revise) between Explore and Exhibit. Understanding develops through inquiry + rethinking; build in perform→feedback→revise→perform cycles, as in apprenticeship/coaching.

5. **Exhibit points to Stage 2.** The Exhibit is the GRASPS task from `manifest.stage2.performanceTasks[*]` — reference its `id` in `whereto.exhibit.taskRef`. Do NOT create a new endpoint here.

6. **Organize** the `site.json` child page ordering to match the WHERETO sequence; use `map-menu` for the unit map. Run page content through `hax-content-chunking-audit` per-page and apply DDD tokens via `hax-design-system`.

## HAX Component Map (WHERETO)

| Letter | Meaning | Component(s) |
|---|---|---|
| W | Where/Why — show destination + criteria | `progress-donut` / `simple-progress` / `promise-progress` + page intro |
| H | Hook — provocative entry | `video-player`, `image-compare-slider`, `media-quote`, `stop-note` (provocative Q) |
| E | Explore/Equip — content + coaching | content + `flash-card`, `self-check`, `lrndesign-imagemap` |
| R | Rethink/Revise — feedback cycles | `self-check` + `stop-note` loops, `flash-card` (retrieval) |
| E | Exhibit/Evaluate — the performance | GRASPS page (ref Stage 2) + `clean-portfolio-theme` / `glossy-portfolio-theme` + `grade-book` + `editable-table` rubric |
| T | Tailor — differentiate / choice | `a11y-tabs`, `a11y-collapse` (set `heading-button`) |
| O | Organize — sequence + uncoverage | `site.json` ordering + `map-menu` + `a11y-collapse` (set `heading-button`) |

Full map (all stages): `../hax-ubd-backward-design/references/ubd-element-map.md`.

## Output Format

```
# Stage 3 — WHERETO: {unit}
## Sequence
1. {page slug} — W: {components} → shows destination
2. {page slug} — H: {components} → hooks into EQ
3. {page slug} — E (Equip): {components} → equips for: {task id}
4. {page slug} — R: {components} → feedback to: {task id}
5. {page slug} — E (Exhibit): {components} → exhibits: {task id}
6. {page slug} — T: {components} → choice options
## Uncoverage plan
{what goes deep vs. what is surveyed; where a11y-collapse/a11y-tabs disclose depth}
## Feedback cycles
{loop placements: self-check/stop-note between Explore and Exhibit}
## site.json ordering
{child node order = WHERETO order}
## HAX handoff
{per-page component list + DDD token note}
```

## Acceptance criteria

- Every `sequence[*].evidenceRef` resolves to a `stage2` item (or is explicitly an Explore/Equip with no evidence target).
- Exactly one Exhibit step; its `taskRef` resolves to a real `stage2.performanceTasks[*].id`.
- ≥1 Rethink/Revise loop between Explore and Exhibit.
- No activity in the sequence lacks a Stage 1/2 justification (no orphan activities — the anti-activity-oriented-sin invariant).
- `a11y-collapse` / `a11y-tabs` usages set `heading-button` (PRAW rule).

## Gotchas

- **"Teaching" is only one learning activity.** Don't default to direct instruction. The focus is planning *learnings*, not *teachings*.
- **Don't duplicate Stage 2's evidence as a new "Exhibit."** The exhibit *is* the GRASPS task. If you find yourself inventing a new culminating task, stop — you're in Stage 2 territory; go back.
- **Over-fragmenting hurts.** A coherent short argument split into tiny chunks can hurt coherence more than a readable block helps (same caveat as `hax-content-chunking-audit`). Use progressive disclosure for *depth*, not to mince cohesive content.
- **Uncoverage ≠ never surveying.** A purposeful, transparent overview is legitimate. Coverage (content march with no overarching purpose) is the sin.
- **WHERETO is a checklist, not a rigid order.** The letters ensure nothing is forgotten; the actual order serves the learners. But W (where we're going) almost always comes early, and Exhibit comes last.

## Dependencies

- **Reads:** `manifest.stage1` + `manifest.stage2` (both required)
- **Hands off to:** `hax-claudehax` / `hax-site-building` (materialization); `hax-content-chunking-audit` (per-page); `hax-design-system` (DDD tokens)

## References

- `../hax-ubd-backward-design/references/manifest-schema.md` — `stage3` fields
- `../hax-ubd-backward-design/references/ubd-element-map.md` — WHERETO component map
- `../hax-ubd-backward-design/references/design-standards.md` — standards 10–12
- PRAW RULES.md: `~/Documents/git/haxtheweb/praw/RULES.md`
- Source: Wiggins & McTighe (2005), *UbD* Ch. 9–10.
