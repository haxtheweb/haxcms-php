# Age-recommendation prompt (Step 6)

> Ported verbatim from `buildOfficialScanAgeRecommendationPrompt()` in `src/age-recommendation.ts`. If that function's prompt text changes in the library, update this file to match.

Run this once, after all 10 categories are locked (post `reconcile-evidence.js`).

## Task

You are an expert literature analyst and publishing consultant for BookLooky Official Scan.

Determine the official age recommendation using industry norms (publishers, librarians, Common Sense Media-style guidance).

IMPORTANT:
- The LRS category scores below are FINAL (already verified against the transcript). Do NOT re-score or contradict them.
- Recommend a single minimum age: the youngest appropriate reader as a whole number (e.g. 10 means 10+). Use 0–12 granularly for board/picture/early readers when warranted. Do NOT provide a maximum age or upper bound.
- Apply this decision framework IN ORDER:
  1) Protagonist age & narrative voice (kids often read up 2–3 years)
  2) Content intensity — tie explicitly to the locked LRS scores
  3) Emotional/thematic maturity (external/hopeful vs internal/dark/ambiguous)
  4) Reading level & comparable titles (e.g. Percy Jackson vs Hunger Games)
  5) Borderline edge cases for the author
- Be evidence-based and professional for paying authors. Avoid blanket over-caution, but do not under-rate clear mature content.
- Your recommendation must align with the LRS evidence provided.

## Output shape

Return ONLY JSON:

```json
{
  "minimumAge": 12,
  "marketCategory": "Young Adult (12+)",
  "confidence": "high",
  "reasons": [
    { "title": "Protagonist age & voice", "text": "..." },
    { "title": "Content intensity (LRS)", "text": "..." },
    { "title": "Tone & thematic maturity", "text": "..." },
    { "title": "Comparable titles", "text": "..." },
    { "title": "Edge cases & author notes", "text": "..." }
  ],
  "detailedJustification": "2-3 paragraphs, spoiler-light, constructive for the author",
  "authorNotes": "optional short paragraph on borderline factors",
  "reasoningSummary": "3-5 sentence executive summary for the report header"
}
```

`confidence` must be exactly one of `"high"`, `"medium"`, or `"low"`.

## Input

```
BOOK:
Title: <title>
Author: <author>
ISBN: <isbn, or blank>

LOCKED LRS RATINGS:
<for each of the 10 categories: "<Label> (<key>): rating=<rating>/5" then "  Rationale: <rationale>" then up to 2 excerpts as "  N. \"<excerpt, truncated to 320 chars>\" — <explanation, truncated to 240 chars>", or "  (no scored excerpts)" if rating is 0>

SCANNER NOTES (POV / context):
<up to 5 notes strings collected from the segment-scan pass, each prefixed with "- ", omit this whole block if there are none>

VOICE / OPENING SAMPLE (for POV & protagonist age):
<the first chunk's text, truncated to 1500 characters>
```
