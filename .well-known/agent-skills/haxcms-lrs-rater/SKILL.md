---
name: haxcms-lrs-rater
description: Run BookLooky's Looky Rating System (LRS) content rating and minimum-age recommendation on a HAXcms site's aggregated content — either a local site checkout or a live published URL like btopro.com — entirely through the invoking agent's own reasoning, no API key or network call to an LLM required. Use this whenever the user asks to "rate this HAXcms site", "run an LRS scan on my site/domain", "what age is btopro.com appropriate for", "check this site for violence/romance/language/etc. content", "score this site the way booklooky does", or gives a HAX site URL/path and asks for a content rating or age recommendation. Distinct from booklooky-lrs-rater, which rates a single provided transcript/manuscript — this skill's job is reading a HAXcms site's llms.txt manifest to assemble that transcript first.
---

# HAXcms LRS Rater

This skill runs BookLooky's official LRS content-rating rubric against a whole HAXcms site by reading its `llms.txt` manifest (which lists every page's `.md` URL in one fetch), stitching all page content into one aggregate "transcript," and then reasoning through the same rubric `booklooky-lrs-rater` uses for books — 10 categories (0-5), evidence-backed excerpts, and a minimum-age recommendation. No LLM API key or network call is needed for the actual rating: you (the invoking agent) are the reasoning engine, exactly as in `booklooky-lrs-rater`.

This skill only adds the site-specific extraction step (Step 0 below). Steps 1-7 are the same LRS methodology, scripts, and prompts as `booklooky-lrs-rater` — bundled here as a self-contained copy so this skill works standalone.

## Why this matters (read before starting)

A HAXcms site's content is scattered across many small pages, not one linear manuscript. Rating individual pages in isolation would miss context (e.g. a "themes" page might only make sense next to the "characters" page) and would produce 10 disconnected mini-reports instead of one coherent site-level rating. So Step 0 first assembles a single ordered "transcript" from the site's outline — mirroring how a reader would actually encounter the content — and everything downstream treats that exactly like a book transcript.

Just as with `booklooky-lrs-rater`, every non-zero rating MUST be backed by a real quoted excerpt from the aggregated site content. `reconcile-evidence.js` will downgrade any unsupported positive rating to 0. Locate and quote actual page text — don't estimate.

## Step 0 — Extract the site content

Two source types, both handled by the same script:

**Live URL** (a published HAXcms site, e.g. `https://btopro.com`):
```bash
node scripts/extract-site-content.js https://btopro.com <scratchDir>
```

**Local HAXcms site checkout** (a directory containing `llms.txt`, or `site.json` + `pages/` as fallback):
```bash
node scripts/extract-site-content.js /path/to/site-root <scratchDir>
```

Either way this writes `<scratchDir>/site-transcript.txt` (all pages' text, each prefixed with `=== PAGE N: <title> ===`) and `<scratchDir>/page-manifest.json` (index/id/title/slug/location per page, for citing which page an excerpt came from later).

How it works:
- **llms.txt (preferred)**: The script fetches `<url>/llms.txt` (or reads `<root>/llms.txt` locally). HAXcms generates this file automatically — it lists every page's title and `.md` URL in its `## Pages` section. One fetch gives the full page list, no `site.json` tree-walking or per-page HTML parsing required. The script then fetches each page's `.md` for clean markdown text.
- **`.html` fallback**: Some static hosts (e.g. GitHub Pages) don't serve `.md` files. When an `.md` fetch 404s, the script automatically falls back to the `.html` version and strips tags to plain text. This is the case for btopro.com — the script prints a note when this happens.
- **`site.json` fallback (local only)**: If no `llms.txt` exists in a local checkout, the script falls back to reading `site.json`, walking the JOS outline depth-first, and reading `.md` (or `.html`) sidecars from disk.
- If a page can't be fetched at all, the script reports which page failed and continues with the rest rather than aborting the whole rating.

For very large sites, treat `site-transcript.txt` like a long book transcript — the chunking in Step 1 handles that the same way.

## Step 1 — Chunk the transcript
```bash
node scripts/chunk-transcript.js <scratchDir>/site-transcript.txt <scratchDir>
```
Writes `<scratchDir>/chunks.json` (~100k characters per chunk, 4k overlap). Small sites will produce a single chunk — proceed the same way regardless.

## Step 2 — Segment scan (per chunk, you reason directly)
For each chunk, read `references/segment-scan-prompt.md` and `references/category-scope-rules.md`, then reason through that chunk yourself to produce its `signals` JSON (all 10 category keys present, each an array of `{quote, why}`, capped at 3 per category). Write each result to `<scratchDir>/scans/<chunkIndex>.json`.

Do this for every chunk before moving on. If resuming across turns, skip chunks that already have a scan file.

## Step 3 — Rank candidate chunks per category
```bash
node scripts/rank-candidate-chunks.js <scratchDir>/scans
```
Save the output as `<scratchDir>/candidates.json`.

## Step 4 — Category focus (per category, you reason directly)
For each of the 10 categories, using its candidate chunk indexes from `candidates.json`, read `references/category-focus-prompt.md` plus that category's scope block(s) from `references/category-scope-rules.md`, then reason directly over those chunks' text to produce `{category, rating, rationale, excerpts}`. Write each result to `<scratchDir>/focus/<category>.json`. When quoting, prefer noting which `=== PAGE N: <title> ===` block an excerpt came from (cross-reference `page-manifest.json`) as the `locationHint` instead of a chapter name.

## Step 5 — Reconcile evidence (per category)
```bash
node scripts/reconcile-evidence.js <scratchDir>/focus/<category>.json <scratchDir>/scans
```
Applies the innuendo backstop and requires validated excerpts for positive ratings. Write each result to `<scratchDir>/reconciled/<category>.json`.

## Step 6 — Age recommendation (you reason directly)
Once all 10 categories are reconciled, read `references/age-recommendation-prompt.md`. Assemble the "LOCKED LRS RATINGS" block from the 10 reconciled results, gather `notes` from the segment scans, and use the first chunk's text (truncated to 1500 characters) as the "voice sample". For a site, treat `minimumAge` as the minimum age of the site's *intended audience* rather than a book reader. Save the result as `<scratchDir>/age-recommendation.json`.

## Step 7 — Build the final report
```bash
node scripts/build-report.js <scratchDir>/reconciled <scratchDir>/age-recommendation.json <scratchDir>/report.json
```
Produces the final report in the same `OfficialScanReport` + `ContentAnalysis` shape as `booklooky-lrs-rater`.

## Presenting results

First, output the BookLooky-style emoji summary (the same compact format used on booklooky.com book pages):
```bash
node scripts/format-emoji-summary.js <scratchDir>/report.json "<site title>" "" "<site URL>"
```
For a site, pass an empty string for the author argument. This produces:
```
BookLooky Rating for:

📚 "bto-pro"

Looky Rating System (LRS)
•💥Violence: 0/5
•❤️Love & Romance: 1/5
•🧠Mental Health: 2/5
•🧙Fantasy: 0/5
•💬Language: 2/5
•🍷Substance Use: 1/5
•🏳️‍🌈Representation: 0/5
•😨Fear / Horror: 1/5
•sciFi: 0/5
•disability: 1/5

https://btopro.com
```

Then lead with the minimum age and confidence, followed by the 6 Content Intensity and 4 Story Theme ratings with each rating's strongest excerpt — and cite which page (title/slug from `page-manifest.json`) it came from, since that's usually more useful for a site than for a single book. Mention this is a locally-generated LRS-style scan of the site's authored content, not an official BookLooky-certified rating.

## Maintenance note

`scripts/chunk-transcript.js`, `scripts/rank-candidate-chunks.js`, `scripts/reconcile-evidence.js`, `scripts/build-report.js`, and all of `references/*.md` are copied verbatim from the `booklooky-lrs-rater` skill (itself ported from the `booklooky-official-lrs-rater` npm library). Re-sync both copies together if the rubric, prompts, or scoring rules change upstream. `scripts/extract-site-content.js` and `scripts/format-emoji-summary.js` are specific to this skill — update `extract-site-content.js` if HAXcms's `llms.txt` format changes, and update `format-emoji-summary.js` if the BookLooky emoji summary template changes.
