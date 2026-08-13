# The Looky Rating System (LRS)

> Copied verbatim from `docs/LRS-SPEC.md` in the `booklooky-official-lrs-rater` repository. If that file changes, re-sync this copy.

The Looky Rating System rates books across **10 categories** on a **0–5 scale**, split into two groups:

- **Content Intensity** (6 categories) — how strong or frequent sensitive content is
- **Story Themes** (4 categories) — what the book is about; prominence indicators, not warnings

Scores measure how present a theme is in the text — not a judgment of quality. Story theme scores describe plot centrality, not appropriateness.

## The scale

### Content Intensity (violence, romance, mental health, language, substance use, fear)

| Score | Meaning |
|---|---|
| 0 | None — the theme is not present |
| 1 | Very low — brief, mild references |
| 2 | Low — present but light |
| 3 | Medium — a recurring or notable element |
| 4 | Moderate–high — a significant, sustained element |
| 5 | High — intense or frequent throughout |

### Story Themes (fantasy, LGBTQ+ representation, sci-fi, disability)

| Score | Meaning |
|---|---|
| 0 | Not present |
| 1 | Brief mention |
| 2 | Background |
| 3 | Recurring theme |
| 4 | Major storyline |
| 5 | Central storyline |

A higher story-theme score means the theme is more central to the plot — **not** a warning. A score is never lowered because the book targets young readers; intensity and audience are assessed separately (see [Age recommendation](#age-recommendation)).

## Content Intensity categories

| Key | Label | Covers |
|---|---|---|
| `violence` | Violence | Physical conflict, fighting, injury, death, peril, graphic descriptions |
| `romance` | Love & Romance | Romantic feelings and relationships, kissing, sexual content — including flirtation, innuendo, implied sexual tension, double entendres, and euphemistic references |
| `mentalHealth` | Mental Health | Grief, anxiety, depression, trauma, self-harm, suicide, psychological struggle |
| `language` | Language | Profanity and curse words; slurs or hateful epithets; sexually explicit or crude wording. **Not** jargon, puns, wordplay, invented terms, or formal vocabulary |
| `substanceUse` | Substance Use | Alcohol, drugs, smoking — use, abuse, and depiction |
| `fear` | Fear / Horror | Suspense, horror, dread, frightening imagery |

## Story Theme categories

| Key | Label | Covers |
|---|---|---|
| `fantasy` | Fantasy / Supernatural | Magic, supernatural elements, secondary worlds, magical creatures — **not** science fiction |
| `lgbtq` | LGBTQ+ Representation | LGBTQ+ characters, relationships, or identity themes — presence indicator, **not** a warning. **Not** generic gender-role content without a queer through-line |
| `sciFi` | Sci-Fi / Futuristic | Futuristic technology, space travel, aliens, robots, dystopian tech — **not** magic-based fantasy |
| `disability` | Disability & Neurodiversity | Disability, chronic illness, or neurodiversity representation — prominence only, **not** a warning |

### Category boundary rules

- **Innuendo routes to `romance`, not `language`.** Implied sexual subtext belongs to `romance` even when no crude word appears. `language` only triggers on profanity, slurs, or explicit wording.
- **`lgbtq` requires an actual LGBTQ+ through-line.** Generic gender-role banter without queer elements scores 0.
- **`fantasy` vs `sciFi`.** Magic and supernatural → `fantasy`. Speculative science and futuristic tech → `sciFi`. A book may score in both if it genuinely blends both.
- **`disability` vs `mentalHealth`.** Score `disability` when disability/neurodiversity representation is present; do not conflate with general mental-health intensity alone.

## Evidence requirements (official transcript scan)

The official rater reads the full book text and enforces:

1. **Every non-zero rating must be backed by quoted transcript excerpts** (up to 5 per category), each with an explanation of why it matters. If no validated excerpt supports a positive rating, the rating is downgraded to 0.
2. **Zero ratings carry an explicit note** stating that no meaningful results were discovered.
3. **Excerpts are deduplicated** and kept short (1–4 sentences) with enough surrounding context to avoid false positives.

## Age recommendation

After all 10 category scores are locked, a single **minimum age** (a whole number, e.g. `10` meaning 10+; range 0–25) is determined using a five-part framework:

1. **Protagonist age & voice**
2. **Content intensity (LRS)** — tied explicitly to the locked Content Intensity scores
3. **Tone & thematic maturity**
4. **Comparable titles**
5. **Edge cases & author notes**

Each report includes all five reasons, a detailed justification, a confidence label (`high` / `medium` / `low`), and an executive summary.
