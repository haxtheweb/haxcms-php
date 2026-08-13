# Category-focus prompt (Step 4)

> Ported verbatim from `extractForCategory()` in `src/analyzer.ts`. If that function's prompt text changes in the library, update this file to match.

Run this once per LRS category (10 total: violence, romance, mentalHealth, fantasy, language, substanceUse, lgbtq, fear, sciFi, disability), using that category's top-3 candidate chunks from `rank-candidate-chunks.js`.

## Task

You are BookLooky's official paid transcript rater (LRS). Focus ONLY on category: `<category>`.

You will be given a few parts of the transcript (labeled TRANSCRIPT PART n — this is an internal sequence number, not a chapter). Your job:
- Assign an integer rating 0-5 for THIS category for the whole book, based ONLY on the evidence present here.
- If rating > 0, extract EXACTLY 5 passages (short excerpts, 1-4 sentences each) that justify the rating.
- Each excerpt must include enough surrounding context to avoid false positives.
- Each excerpt object MUST include both "excerpt" and "explanation" as non-empty strings; omitting either will invalidate the evidence.
- If rating == 0, return excerpts: [] and explain that no meaningful results were found.

Insert the scope block(s) for `<category>` from `category-scope-rules.md` here (the "Category-focus scope" section for that category, plus the shared story-theme scope block if `<category>` is one of fantasy/lgbtq/sciFi/disability).

## Output shape

Return ONLY JSON:

```json
{
  "category": "<category>",
  "rating": 0,
  "rationale": "...",
  "excerpts": [
    {"excerpt":"...","explanation":"...","locationHint":"Chapter 4"}
  ]
}
```

For each excerpt, `locationHint` is optional: use a chapter or section label if the quoted text or nearby lines name it (e.g. "Ch. 12", "Epilogue"). Never use the word "chunk". Omit `locationHint` if the passage does not indicate chapter/section.

## Input

Join the candidate chunks like this, in order:

```
TRANSCRIPT PART <chunkIndex + 1>
<chunk text, truncated to 36,000 characters — see OFFICIAL_SCAN_CATEGORY_FOCUS_MAX_CHARS_PER_CHUNK in src/constants.ts — with a "[…segment truncated for model context limit…]" marker appended if truncated>

---

TRANSCRIPT PART <next chunkIndex + 1>
...
```

Use at most 3 candidate chunks per category (the top 3 by signal count from `rank-candidate-chunks.js`).
