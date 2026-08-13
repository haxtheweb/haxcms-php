# Category scope rules

> Ported verbatim from the scope-block constants in `src/analyzer.ts` (`GENDER_*`, `LANGUAGE_*`, `ROMANCE_*`, `FANTASY_*`, `SCI_FI_*`, `DISABILITY_*`). If those constants change in the library, update this file to match.

These rules exist to keep specific categories from bleeding into each other (e.g. innuendo landing in "language" instead of "romance", or generic gender banter landing in "lgbtq"). Apply the segment-scan block during Step 2 of `SKILL.md`, and the matching focus-scope block during Step 4.

## lgbtq

### Segment-scan scope (use during per-chunk signal extraction)
Category "lgbtq" (LGBTQ+ representation) — LGBTQ+ ONLY:
- Use "lgbtq" ONLY when the passage clearly involves LGBTQ+ themes: sexual orientation or gender identity (including questioning), same-sex or explicitly queer relationships, homophobia, transphobia, anti-LGBTQ slurs or harassment, coming out, transition, or unmistakable queer representation tied to those themes.
- Do NOT use "lgbtq" for: general men-vs-women banter; "strong woman" / "weak man" tropes; generic sexism or feminism with no LGBTQ+ angle; straight dating or marriage talk with no queer subtext; jokes about respecting women, pink ribbons, or competence unless the passage ALSO clearly ties to LGBTQ+ identity or anti-LGBTQ bias.
- Negative examples (use an empty "lgbtq" array here): colleagues quipping that women are "men who didn't grow enough"; a character insisting he "respects women" with an awareness-ribbon joke; arguing men vs women strength with no queer element. (Still: two women holding hands is NOT LGBTQ+ unless context shows romance or identity—not just friendship.)

### Category-focus scope (use during the category-focus pass)
SCOPE for "lgbtq": This category is LGBTQ+ representation ONLY. Assign rating 0 unless the evidence clearly concerns LGBTQ+ identity, same-sex/queer relationships, or anti-LGBTQ prejudice. If the text is only general gender roles, men/women dynamics, or "strong woman" material with no LGBTQ+ through-line, return rating 0 and excerpts: [].

## language

### Segment-scan scope
Category "language" (objectionable wording ONLY):
- Use "language" ONLY for: clear profanity / curse words; slurs or hateful epithets aimed at people or groups; or sexually explicit or crude sexual *words* and graphic sexual phrasing (this aligns with sexual-content concerns—here judge the vulgarity or explicitness of the wording itself, not romantic plot).
- Do NOT use "language" for: technical jargon or trade terminology; puns, riddles, or harmless wordplay; invented fantasy/sci-fi terms; formal or literary vocabulary; mild oaths unless they are unambiguous strong profanity in context; extended metaphors with no actual slur or swear word on the page; passages that merely discuss sex or romance without crude/explicit diction.
- Negative examples (use an empty "language" array): in-world slang with no real-world slur; a pun or homophone joke; characters speaking clinically or politely about intimacy; worldbuilding terms that sound odd but are not obscene.

### Category-focus scope
SCOPE for "language": Rate ONLY profanity, slurs/hateful language, and explicit sexual wording. Assign rating 0 if the evidence is only jargon, puns, wordplay, invented terms, or non-profane vocabulary—even if the topic is mature.

## romance

### Segment-scan scope
Category "romance" (romantic and sexual content, including implied):
- Use "romance" for: romantic feelings or relationships; kissing; sexual content; flirtation; sexual innuendo; implied sexual tension; double entendres; euphemistic sexual references; mild sexual humor—even when wording is non-graphic.
- Do NOT send sexual innuendo or implied sexual subtext to "language" unless the issue is profanity, slurs, or explicitly crude sexual wording (see language scope above).
- If this segment clearly contains sexual innuendo or flirtation with sexual subtext, include at least one "romance" signal (do not rely on another category alone).

### Category-focus scope
SCOPE for "romance": Rate romantic and sexual themes including flirtation, innuendo, implied sexual tension, double entendres, and euphemistic sexual references. If the transcript evidence shows any such innuendo or implied sexual subtext, rating MUST be at least 1—never 0 when that subtext is present. Reserve 0 only when there is no romantic or sexual content of any kind in the excerpts you evaluated.

> Deterministic backstop: if the rationale text discusses innuendo/double-entendre/euphemism/sexual-tension/flirtation language but the rating still landed at 0, `reconcile-evidence.js` force-bumps it to 1 (see "romanceRatingWithInnuendoRule" in `src/scoring-rules.ts`).

## Story themes generally (fantasy, lgbtq, sciFi, disability)

### Category-focus scope (applies to all four story-theme categories)
SCOPE for story theme categories (fantasy, lgbtq, sciFi, disability): Rate how CENTRAL this theme is to the plot (0 = not present, 5 = central storyline). This is a presence indicator, NOT content intensity and NOT a warning. A higher score means the theme is more central — not that the book is less appropriate.

## fantasy

### Category-focus scope
SCOPE for "fantasy": Magic, supernatural elements, witches/wizards, magical creatures, or alternate fantasy worlds. Do NOT score science fiction here (space travel, advanced technology without magic) — use sciFi instead.

## sciFi

### Category-focus scope
SCOPE for "sciFi": Science fiction, futuristic or speculative technology, space travel, aliens, robots, dystopian futures, and time travel grounded in science (not magic). Do NOT score pure fantasy/magic here — use fantasy instead. A book may score in both only if it genuinely blends both.

### Segment-scan scope (fantasy + sciFi combined, as used in the per-chunk prompt)
Categories "fantasy" and "sciFi" (story themes — prominence only):
- "fantasy": magic, supernatural, witches/wizards, magical creatures, enchanted worlds.
- "sciFi": futuristic technology, space travel, aliens, robots, dystopian tech, speculative science — NOT magic-based.
- A book may have signals in both if it genuinely blends science fantasy.

## disability

### Category-focus scope
SCOPE for "disability": Physical disability, chronic illness, neurodiversity (e.g. autism, ADHD), Deaf/HoH, blind/visually impaired representation. Score prominence only — not a warning. Do NOT conflate with mental health intensity alone unless disability/neurodiversity representation is clearly present.

### Segment-scan scope
Category "disability" (story theme — prominence only):
- Use "disability" for clear disability, chronic illness, or neurodiversity representation (physical disability, autism, ADHD, Deaf/HoH, blind/VI, etc.).
- Score based on how central the representation is — not as a warning.
- Do NOT use for generic mental health struggles alone unless disability/neurodiversity is clearly present.

## Categories with no extra scope block

`violence`, `mentalHealth`, `substanceUse`, and `fear` have no additional scope block beyond the base task description in `segment-scan-prompt.md` / `category-focus-prompt.md` — use the LRS-SPEC.md category table for their definitions.
