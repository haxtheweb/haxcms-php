# UbD Unit Manifest Schema

The manifest is the connective tissue threading all three UbD stages. Every stage
skill reads/writes its own section; the orchestrator (`hax-ubd-backward-design`)
owns the whole object; `hax-ubd-unit-audit` reconstructs a partial manifest from a
live site when one is not already present.

**Storage location:** `files/ubd/<unit-slug>.manifest.json` inside the HAX site
(it is a site asset, not a page). The `files/` directory is the canonical asset
location per the HAXcms site organization rule.

**Why a manifest, not just pages:** UbD design is iterative and cross-stage
alignment is the most common failure mode. The manifest keeps Stage 1 goals,
Stage 2 evidence, and Stage 3 sequence in one queryable object so any skill can
verify that an activity in Stage 3 actually points at evidence in Stage 2 that
actually targets an understanding in Stage 1.

## Full schema

```json
{
  "unitId": "string — kebab-case, MUST match the site.json node slug",
  "title": "string",
  "source": {
    "type": "standard | topic | content | import",
    "ref": "string or URL — the verbatim standard, topic phrase, or source URL"
  },
  "courseContext": {
    "programBigIdeas": ["linchpin ideas this unit nests under"],
    "strand": "e.g. '3rd grade science' / 'US History' / 'intro physics'"
  },
  "stage1": {
    "goals": ["verbatim established goals / standards addressed"],
    "bigIdeas": ["linchpin, transferable concepts"],
    "understandings": ["Students will understand that ... (full-sentence propositions)"],
    "essentialQuestions": {
      "overarching": ["recur across the course/program"],
      "topical": ["unit-specific"]
    },
    "entryQuestions": ["student-friendly, provocative lesson openers"],
    "knowledge": ["key facts/concepts — prerequisite + resultant"],
    "skills": ["what students will be able to do"],
    "misunderstandings": [
      {
        "misconception": "the predictable wrong/näive idea",
        "preAssess": "hax component + placement to catch it",
        "confront": "hax component + placement to evict it"
      }
    ]
  },
  "stage2": {
    "performanceTasks": [
      {
        "id": "t1",
        "grasps": {
          "goal": "...",
          "role": "...",
          "audience": "...",
          "situation": "...",
          "product": "...",
          "standards": "..."
        },
        "facets": ["explanation", "application"],
        "isProblemNotExercise": true,
        "haxPage": "pages/<slug>.html",
        "authenticContextMedia": ["video-player"],
        "targetsUnderstanding": "Students will understand that ..."
      }
    ],
    "academicPrompts": [
      { "id": "oe-1", "prompt": "...", "facet": "interpretation", "haxComponent": "stop-note + simple-fields" }
    ],
    "quizTestItems": [
      { "id": "oe-2", "items": "...", "haxComponent": "multiple-choice" }
    ],
    "informalChecks": [
      { "id": "oe-3", "check": "...", "haxComponent": "self-check" }
    ],
    "rubric": {
      "facets": ["explanation", "application"],
      "criteria": ["..."],
      "levels": 4,
      "anchors": ["exemplar descriptions per level"]
    },
    "validitySelfTest": {
      "q1_guessing": "very-unlikely",
      "q2_parroting": "very-unlikely",
      "q3_hardwork": "very-unlikely",
      "q4_lovely_product": "very-unlikely",
      "q5_articulate": "very-unlikely",
      "q6_poor_despite_understanding": "very-unlikely",
      "q7_arbitrary_criteria": "very-unlikely",
      "defects": []
    },
    "longitudinalEvidence": ["progress-donut", "grade-book"]
  },
  "stage3": {
    "whereto": {
      "where": { "components": ["progress-donut"], "page": "pages/<slug>.html" },
      "hook": { "components": ["video-player"], "page": "pages/<slug>.html" },
      "explore": { "components": ["flash-card", "self-check"], "page": "pages/<slug>.html" },
      "rethink": { "components": ["self-check", "stop-note"], "page": "pages/<slug>.html" },
      "exhibit": { "taskRef": "t1", "page": "pages/<slug>.html" },
      "tailor": { "components": ["a11y-tabs"], "page": "pages/<slug>.html" },
      "organize": { "components": ["map-menu", "a11y-collapse"], "note": "site.json ordering" }
    },
    "sequence": [
      {
        "order": 1,
        "page": "pages/<slug>.html",
        "wheretoLetter": "W",
        "components": ["progress-donut"],
        "equipsFor": null,
        "evidenceRef": null
      },
      {
        "order": 4,
        "page": "pages/<slug>.html",
        "wheretoLetter": "E (Exhibit)",
        "components": ["editable-table"],
        "equipsFor": null,
        "evidenceRef": "t1"
      }
    ]
  },
  "siteJsonPatch": {
    "node": "unit slug",
    "children": ["child page slugs in WHERETO order"]
  },
  "metadata": {
    "oerSchema": {
      "learningOutcome": ["the enduring understandings"],
      "educationalAlignment": "standard/course ref"
    }
  },
  "validation": {
    "lastCheckedAt": "ISO-8601",
    "designStandardsPass": true,
    "validitySelfTestPass": true,
    "alignmentTriangulated": true
  }
}
```

## Field rules

- `unitId` MUST equal the `site.json` node slug for that unit. This is the join key
  between the manifest and the live site.
- `stage1.understandings[*]` MUST be full-sentence propositions beginning with
  "Students will understand that…". A bare topic noun phrase ("The Civil War") is
  invalid here — it belongs nowhere or in `bigIdeas` if it is genuinely a linchpin.
- `stage2.performanceTasks[*].isProblemNotExercise` MUST be `true` for at least one
  task. An all-exercise unit cannot produce evidence of understanding (see
  `references/validity-self-test.md`).
- `stage2.performanceTasks[*].targetsUnderstanding` MUST reference a string that
  exists verbatim in `stage1.understandings`. This is the Stage 1 ↔ Stage 2
  alignment invariant.
- `stage3.whereto.exhibit.taskRef` MUST reference a real
  `stage2.performanceTasks[*].id`. Stage 3 points *at* Stage 2 evidence; it never
  invents a new endpoint.
- `stage3.sequence[*].evidenceRef`, when non-null, MUST resolve to a
  `stage2` item id (`t1`, `oe-1`, …).
- `validation.*` is recomputed by `hax-ubd-unit-audit` on each audit run.

## Minimal valid manifest

A manifest is considered present-but-draft until all three `stage*` blocks are
populated and `validation.alignmentTriangulated` is true. The orchestrator may
write a partial manifest between stages; the audit skill treats a partial manifest
as a finding ("Stage 2 empty — unit is goal-focused but has no evidence").
