# UDL → HAX Element Map

Single source of truth for mapping UDL 3.0 principles and guidelines to real HAX web
components. Mirrors `../hax-ubd-backward-design/references/ubd-element-map.md`.

Every component listed here has been verified to exist in `webcomponents/elements/` and is
HAX-authorable (registered via `customElements.define` and shipped on the CDN).

**Iron rule:** never recommend a component that is not in this table. If a UDL guideline has no
dedicated element, use the documented gap handling instead of inventing a tag name or recommending
a legacy/third-party tag.

UDL 3.0 (CAST, 2024) organizes around three principles — Engagement (the WHY), Representation
(the WHAT), and Action & Expression (the HOW) — each with three guidelines (G1–G9). The goal of
UDL is learner agency that is purposeful & reflective, resourceful & authentic, strategic &
action-oriented. UDL is a set of design prompts, not a checklist — report what is present, what
is absent, and recommend only real components for the gaps.

## Principle 1 — Multiple Means of Engagement (the WHY)

Guidelines 7–9.

| Guideline | UDL consideration | HAX element(s) | Gap handling |
|---|---|---|---|
| G7 Welcoming Interests & Identities | 7.2 relevance/value/authenticity | `simple-cta` (authentic call-to-action), `person-testimonial` (identity/voice) | — |
| G7 | 7.3 joy and play | `rpg-character` (gamified joy) — use sparingly, not as a headline fix | prefer `stop-note` prompts when in doubt |
| G8 Sustaining Effort & Persistence | 8.1 meaning/purpose of goals | `oer-schema` (`learningOutcome`) + heading structure + `stop-note` (carry the goal/EQ visibly) | legacy `instruction-card` is NOT in the current monorepo — use `oer-schema` + headings + `stop-note` |
| G8 | 8.2 optimize challenge and support | `a11y-collapse` (set `heading-button`), `a11y-tabs` (progressive disclosure / leveled options) | — |
| G8 | 8.4 belonging and community | `person-testimonial`, `stop-note` (collaborative prompts) | — |
| G8 | 8.5 action-oriented feedback | `self-check`, `stop-note` (immediate feedback loops) | — |
| G9 Emotional Capacity | 9.3 individual and collective reflection | `stop-note`, `self-check` (reflection/metacognition) | — |

## Principle 2 — Multiple Means of Representation (the WHAT)

Guidelines 1–3.

| Guideline | UDL consideration | HAX element(s) | Gap handling |
|---|---|---|---|
| G1 Perception | 1.1 customize display | `grid-plate` (layout/spacing control), DDD tokens (spacing/typography via `hax-design-system`) | — |
| G1 | 1.2 multiple ways to perceive | `video-player`, `media-image`, `image-compare-slider`, `a11y-gif-player`, `lrndesign-imagemap` | third-party `<model-viewer>` is NOT a HAX-published element — use `media-image` / `image-compare-slider` |
| G2 Language & Symbols | 2.1 clarify vocabulary/symbols | `stop-note` (inline clarification), `a11y-collapse` (set `heading-button`) for glossary folds | legacy `lrndesign-sidenote` is NOT in the current monorepo — use `stop-note` / `a11y-collapse` |
| G2 | 2.2 decode notation (math/symbols) | `lrn-math` (math notation), `code-sample` (code notation) | — |
| G2 | 2.5 illustrate through multiple media | `video-player`, `media-image`, `image-compare-slider`, `lrn-math`, `lrndesign-imagemap` | — |
| G3 Building Knowledge | 3.1 connect prior knowledge | `wikipedia-query` (prior-knowledge bridge) | legacy `link-preview` is NOT in the current monorepo — use `wikipedia-query` / `stop-note` |
| G3 | 3.2 patterns / big ideas / relationships | `lrndesign-timeline` (sequence/relationships), `editable-table` (structured comparison), `grid-plate` (visual organization) | — |
| G3 | 3.4 maximize transfer and generalization | `stop-note` (transfer prompts), `oer-schema` (learning outcomes) | — |

## Principle 3 — Multiple Means of Action & Expression (the HOW)

Guidelines 4–6. **This is the empirically-confirmed gap** — interactive assessment/expression
blocks are nearly absent across the 36-course / 4,870-page training corpus (`multiple-choice` 2,
`self-check` 3, `flash-card` 2). Bias audits toward flagging this principle.

| Guideline | UDL consideration | HAX element(s) | Gap handling |
|---|---|---|---|
| G4 Interaction | 4.1 vary and honor response methods | `multiple-choice`, `true-false-question`, `short-answer-question`, `fill-in-the-blanks` | — |
| G5 Expression & Communication | 5.2 multiple tools for construction/composition | `fill-in-the-blanks`, `mark-the-words`, `matching-question`, `sorting-question`, `tagging-question`, `short-answer-question`, `simple-fields` (constructed response), `code-sample` / `rich-text-editor-highlight` (composition) | no essay/journal element → `simple-fields` + `stop-note` |
| G5 | 5.3 graduated practice with support | `flash-card` (retrieval practice), `self-check` (scaffolded checks) | — |
| G6 Strategy Development | 6.3 organize information and resources | `a11y-collapse` (set `heading-button`), `a11y-tabs`, `grid-plate`, `editable-table`, `worksheet-download` | — |
| G6 | 6.4 monitor progress | `self-check`, `progress-donut`, `simple-progress`, `promise-progress`, `grade-book` | — |
| G6 | 6.1 set meaningful goals | `oer-schema` (`learningOutcome`), `stop-note` (goal-setting prompts) | — |

## Overlap with the UbD family (do not double-report)

UDL Action & Expression (G4–G6) overlaps UbD Stage 2 assessment evidence. When auditing a unit
that has already been through `hax-ubd-unit-audit`, do NOT re-report Stage 2 alignment as a UDL
finding — defer alignment to the UbD skill and limit the UDL report to the access/expression
*variety* lens (are there multiple response methods? graduated practice? progress monitoring?).
Conversely, the UbD audit owns goal/evidence/plan alignment; UDL owns reach-and-access for all
learners.

## Component index (all verified elements in this map)

`a11y-collapse`, `a11y-gif-player`, `a11y-tabs`, `code-sample`, `editable-table`, `fill-in-the-blanks`,
`flash-card`, `grade-book`, `grid-plate`, `image-compare-slider`, `learning-component`, `lrn-math`,
`lrndesign-imagemap`, `lrndesign-timeline`, `mark-the-words`, `matching-question`, `media-image`,
`media-playlist`, `media-quote`, `multiple-choice`, `oer-schema`, `person-testimonial`,
`progress-donut`, `promise-progress`, `rich-text-editor-highlight`, `rpg-character`, `self-check`,
`short-answer-question`, `simple-cta`, `simple-fields`, `simple-progress`, `sorting-question`,
`stop-note`, `tagging-question`, `true-false-question`, `video-player`, `wikipedia-query`,
`worksheet-download`.

Components intentionally excluded (legacy/third-party, not in the current monorepo — do NOT
recommend): `instruction-card`, `lrndesign-sidenote`, `link-preview`, `model-viewer`.

## Rules carried from PRAW RULES.md / Warp rules

IDs shown resolve in `~/Documents/git/haxtheweb/praw/RULES.md`; items marked "(Warp rule)" are
personal ecosystem rules not duplicated in that file.

- `a11y-collapse` MUST set `heading-button` (rule `a11y-collapse-heading-button`).
- Audio assets: prefer `media-playlist` + `audio-player` over inline audio.
- Input elements: use the `simple-fields` ecosystem for constructed response (Warp rule).
- Admin/tabular presentation: prefer `editable-table-display` — the read-only display variant of
  `editable-table` — for consistent table presentation; use `editable-table` where editing is
  required (Warp rule).
- Educational elements: apply OER Schema metadata (rule `c3XjsqFbCmoA3cxsooNyxG`).
- Only author HAX-capable components (those with `haxProperties`) (rule `eis0l9w9l2jG1COFySmvdT`).
- Use DDD design tokens for spacing, color, icon sizing on any rendered component (rule `MLhl56jNSqHvnRiAW5A2GR`).
- `lrndesign-*` components are legacy; note current-vs-legacy where relevant but do not refactor them.

## Sources

- CAST (2024). *Universal Design for Learning Guidelines version 3.0*. Lynnfield, MA: CAST.
  Graphic organizer and guideline text: https://udlguidelines.cast.org/
- Wiggins, G. & McTighe, J. (2005). *Understanding by Design* (Expanded 2nd ed.). ASCD.
- Companion map: `../hax-ubd-backward-design/references/ubd-element-map.md`
