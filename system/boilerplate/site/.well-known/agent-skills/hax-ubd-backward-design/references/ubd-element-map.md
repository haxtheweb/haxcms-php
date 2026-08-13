# UbD → HAX Element Map

Single source of truth for mapping UbD constructs to real HAX web components.
Every component listed here has been verified to exist in
`webcomponents/elements/` and is HAX-authorable (has `haxProperties`).

**Iron rule:** never recommend a component that is not in this table. If a UbD
construct has no dedicated element, use the documented gap handling instead of
inventing a tag name.

## Stage 1 — Desired Results

| UbD construct | HAX element(s) | Placement | Gap handling |
|---|---|---|---|
| Established Goals (G) | `oer-schema`, `site.json` node metadata | page metadata | — |
| Big ideas (linchpin) | heading structure + `oer-schema` `learningOutcome` | page framing | no concept-map element → use heading hierarchy / `editable-table` |
| Enduring understandings (U) | "Students will understand that…" block + `oer-schema` | page body, visible | — |
| Essential questions (Q) — overarching + topical | `stop-note` (keep open, not collapsed) | page top | — |
| Entry questions | `stop-note` | page top, above EQs | — |
| Knowledge / Skills (K, S) | `editable-table` | page body | — |
| Misconception pre-assessment | `self-check`, `multiple-choice` | page top (before instruction) | — |
| Misconception confrontation | `stop-note` + `self-check` | inline where the idea is taught | — |

## Stage 2 — Acceptable Evidence

| UbD construct | HAX element(s) | Gap handling |
|---|---|---|
| GRASPS performance task (T) | authored page + `video-player` / `media-playlist` / `audio-player` / `image-compare-slider` (authentic context) + `simple-fields` (product) + `editable-table` (rubric/standards) | no essay element → `simple-fields` for constructed response |
| Academic prompts (OE) | `stop-note` / `self-check` + `simple-fields` (constructed response) | no essay/journal element → `simple-fields` + `stop-note` |
| Quiz & test items (OE) | `multiple-choice`, `fill-in-the-blanks`, `matching-question`, `sorting-question`, `tagging-question` | — |
| Informal checks for understanding (OE) | `self-check`, `flash-card`, `stop-note` | — |
| Six-facet rubric criteria | `editable-table` (analytic-trait scoring) | no rubric element → `editable-table` |
| Longitudinal evidence ("scrapbook not snapshot") | `progress-donut`, `simple-progress`, `promise-progress`, `grade-book` | — |
| Perspective evidence (compare viewpoints) | `image-compare-slider`, `a11y-compare-image` | — |
| Portfolio exhibit | `clean-portfolio-theme`, `glossy-portfolio-theme` | — |

## Stage 3 — WHERETO

| Letter | Meaning | HAX element(s) |
|---|---|---|
| W | Where/Why — show destination + criteria | `progress-donut` / `simple-progress` / `promise-progress` + page intro |
| H | Hook — provocative entry | `video-player`, `image-compare-slider`, `media-quote`, `stop-note` (provocative Q) |
| E | Explore/Equip — content + coaching | content + `flash-card`, `self-check`, `lrndesign-imagemap` |
| R | Rethink/Revise — feedback cycles | `self-check` + `stop-note` loops, `flash-card` (retrieval) |
| E | Exhibit/Evaluate — the performance | GRASPS page (ref Stage 2) + `clean-portfolio-theme` / `glossy-portfolio-theme` + `grade-book` + `editable-table` rubric |
| T | Tailor — differentiate / choice | `a11y-tabs`, `a11y-collapse` (set `heading-button`) |
| O | Organize — sequence + uncoverage | `site.json` ordering + `map-menu` + `a11y-collapse` (set `heading-button`) |

## Six facets → evidence components

| Facet | Verbs | HAX evidence components |
|---|---|---|
| Explanation | explain, justify, verify, prove, generalize | `simple-fields` (show work/justify) + `editable-table` (criteria) |
| Interpretation | interpret, make meaning, tell significance | `stop-note` (make meaning) + `simple-fields` |
| Application | apply, transfer, use in new context | GRASPS page + authentic-context media + `simple-fields` |
| Perspective | critique, compare viewpoints, question assumptions | `image-compare-slider` / `a11y-compare-image` + `simple-fields` |
| Empathy | role-play, relate, walk in another's shoes | `stop-note` (role-play) + `simple-fields` + media context |
| Self-Knowledge | self-assess, reflect, know one's biases | `self-check` (self-assess) + `stop-note` (metacognition) |

## Component index (all verified elements)

`a11y-collapse`, `a11y-compare-image`, `a11y-tabs`, `audio-player`, `clean-portfolio-theme`,
`editable-table`, `fill-in-the-blanks`, `flash-card`, `glossy-portfolio-theme`, `grade-book`,
`image-compare-slider`, `lrndesign-imagemap`, `lrndesign-timeline`, `map-menu`, `matching-question`,
`media-playlist`, `media-quote`, `multiple-choice`, `oer-schema`, `progress-donut`,
`promise-progress`, `self-check`, `simple-fields`, `simple-progress`, `sorting-question`,
`stop-note`, `tagging-question`, `video-player`.

## Rules carried from PRAW RULES.md / Warp rules

IDs shown resolve in `~/Documents/git/haxtheweb/praw/RULES.md`; items marked "(Warp rule)" are
personal ecosystem rules not duplicated in that file.

- `a11y-collapse` MUST set `heading-button` (rule `a11y-collapse-heading-button`).
- Audio assets: prefer `media-playlist` + `audio-player` over inline audio.
- Input elements: use the `simple-fields` ecosystem for constructed response (Warp rule).
- Admin/tabular presentation: prefer `editable-table-display` — the read-only display variant of
  `editable-table` (defined in `editable-table/lib/editable-table-display.js`) — for consistent
  table presentation; use `editable-table` where editing is required (Warp rule).
- Educational elements: apply OER Schema metadata (rule `c3XjsqFbCmoA3cxsooNyxG`).
- Only author HAX-capable components (those with `haxProperties`) (rule `eis0l9w9l2jG1COFySmvdT`).
- Use DDD design tokens for spacing, color, icon sizing on any rendered component (rule `MLhl56jNSqHvnRiAW5A2GR`).

## Tag note

`editable-table` exports two tags: `editable-table` (editable) and `editable-table-display`
(read-only presentation). Both are HAX-capable. For rubrics/criteria shown to students, prefer
`editable-table-display`; for teacher-authored rubrics, use `editable-table`.
