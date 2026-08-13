# Segment-scan prompt (Step 2)

> Ported verbatim from `scanTranscriptChunk()` in `src/analyzer.ts`. If that function's prompt text changes in the library, update this file to match.

For each chunk produced by `chunk-transcript.js`, reason through the following task yourself — do NOT call any external API. Treat yourself as the model completing this task.

## Task

You are BookLooky's official paid transcript rater (LRS). You will be given ONE segment of a full book transcript.

Task:
- For each category, if this segment contains clear evidence, extract up to 3 short quotes (1-3 sentences each) and explain WHY they matter.
- Content Intensity categories (violence, romance, mentalHealth, language, substanceUse, fear): evidence of how strong or frequent the content is.
- Story Theme categories (fantasy, lgbtq, sciFi, disability): evidence of how central the theme is to the plot — NOT a warning.
- Be conservative and context-aware.
- If a category is not clearly evidenced in this segment, return an empty array for that category.

Insert the following scope blocks from `category-scope-rules.md` here, in this order: `lgbtq` segment-scan scope, `language` segment-scan scope, `romance` segment-scan scope, `fantasy`+`sciFi` segment-scan scope, `disability` segment-scan scope.

## Output shape

Produce (in memory, or write to your scratch dir) ONLY JSON in this exact shape:

```json
{
  "chunkIndex": <the chunk's index>,
  "signals": {
    "violence": [{"quote":"...","why":"..."}],
    "romance": [{"quote":"...","why":"..."}],
    "mentalHealth": [{"quote":"...","why":"..."}],
    "fantasy": [{"quote":"...","why":"..."}],
    "language": [{"quote":"...","why":"..."}],
    "substanceUse": [{"quote":"...","why":"..."}],
    "lgbtq": [{"quote":"...","why":"..."}],
    "fear": [{"quote":"...","why":"..."}],
    "sciFi": [{"quote":"...","why":"..."}],
    "disability": [{"quote":"...","why":"..."}]
  },
  "notes": "optional"
}
```

Every one of the 10 category keys must be present, even if its array is empty. Keep at most 3 signals per category (drop any beyond that), and drop any signal missing either `quote` or `why`.

## Input

The chunk text to evaluate is the `text` field of the chunk object from `chunk-transcript.js`'s output (`{chunkIndex, text}`).
