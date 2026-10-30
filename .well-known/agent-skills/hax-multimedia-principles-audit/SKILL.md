---
name: hax-multimedia-principles-audit
description: "READ-ONLY audit of how a HAX page combines words, images, audio and video against Mayer's multimedia principles, with HAX remediation. Use for \"is this media helping\", decorative images, long videos or text far from its diagram."
version: 1.0.0
license: Apache-2.0
metadata:
  author: PRAW
  tags: [instructional-design, multimedia-learning, mayer, cognitive-load, media, audit, hax, diagnostic]
  requirements: "A HAX page or site (site.json + pages/*.html, a JOS node, raw HTML or markdown). Emits a report; does NOT edit pages or site.json."
---

# HAX Multimedia Principles Audit (Read-Only Diagnostic)

People learn better from words and pictures together than from words alone, but only when the two
are combined in ways that respect limited working memory. Mayer's principles of multimedia learning
describe how. Too much vivid, decorative or badly placed media adds extraneous load, and novices
suffer most (ELEVATE-XR cites this as the reason to keep fidelity low early). This audit checks how
a HAX page combines its media and text.

It complements `hax-content-chunking-audit` (which measures length and density) and `hax-udl-audit`
(which checks that alternatives exist). This audit asks whether the media that is there helps.

## When to Use

**Trigger conditions:**
- "Is this media helping or just decoration?" / "too many images" / "this video is too long"
- Pages with stock photos, background audio, talking-head video, or diagrams explained elsewhere on the page
- Media-heavy imported content

**When NOT to use (with redirect):**
- Alt text, captions and transcripts → `hax-a11y-audit`
- Word counts and walls of text → `hax-content-chunking-audit`
- Multiple means of representation → `hax-udl-audit`

## Scope: this skill is READ-ONLY

Diagnoses and recommends only. Never edit pages or `site.json`. Hand fixes to `hax-site-building` / `hax-claudehax`.

## Principles and checks

Reducing extraneous processing:

| Principle | Flag when… | HAX remediation |
|---|---|---|
| Coherence | Decorative images, stock photos, background music or "seductive details" unrelated to the objective | Remove, or replace with an explanatory figure (`media-image` with a caption that teaches) |
| Signaling | Long explanations with no headings or cues to the key points; complex images with nothing pointing to what matters | Headings; `lrndesign-imagemap` hotspots (over an SVG with matching shape ids); bold key terms; `lrn-vocab` |
| Redundancy | A narrated video that also shows the full narration as on-screen text (captions are fine; they are an accessibility requirement) | Keep narration plus graphics without duplicating the narration as on-screen body text; captions stay available |
| Spatial contiguity | A diagram and the text that explains it are separated (different sections, or the explanation sits far below) | Put the explanation beside or under the figure: `media-image` caption, `lrndesign-imagemap` hotspot text, `a11y-figure` |
| Temporal contiguity | Narration and the matching visual appear at different times | Re-segment the video so they coincide |

Managing essential processing:

| Principle | Flag when… | HAX remediation |
|---|---|---|
| Segmenting | Continuous video or animation the learner cannot pace, with no breaks or checks (engagement drops sharply past about 6 minutes, per Guo et al., 2014) | `video-player` with `start-time` / `end-time` segments separated by a `self-check` or question |
| Pre-training | Complex process explained before learners know the names and parts | A short key-terms section first: `lrn-vocab`, `flash-card`, labeled `lrndesign-imagemap` |
| Modality | A complex animation or diagram explained by a long block of printed text that competes for the eyes | Narrated walkthrough (`video-player` / `audio-player`) beside the visual; keep a transcript |

Fostering generative processing:

| Principle | Flag when… | HAX remediation |
|---|---|---|
| Multimedia | Spatial, structural or process content explained in words only | Add a diagram, `image-compare-slider`, `lrndesign-timeline`, or annotated photo |
| Personalization | Stiff, impersonal third-person prose throughout | Rewrite in a conversational second person |
| Image | A talking head fills the frame without adding information | Prefer showing the task or diagram |

## Methodology

1. Read the page HTML (see `hax-site-structure`). Inventory every `media-image`, `img`, `video-player`,
   `audio-player`, `lrndesign-imagemap`, `image-compare-slider`, `h5p-element` and similar element.
2. For each item, find the text that explains it and how far away it is (same element, next block, another section).
3. Note video durations where known (from the source or metadata) and whether `start-time` / `end-time` segments it.
4. Apply each check. Record evidence for every flag.
5. If the learners' stage is known, weight findings: coherence, signaling, segmenting and
   pre-training matter most for novices; experts are hurt less and may find heavy signaling redundant
   (expertise reversal).
6. Rate the page: **Supportive** | **Mixed** | **Overloading**.

## Output Format

```
### 📊 Multimedia Diagnostics
* **Rating:** [Supportive / Mixed / Overloading]
* **Primary issue:** [one sentence]

### 🔍 Findings & HAX Remediation
* **{media item / section} — {Principle}**
  * **Evidence:** [what was measured or seen]
  * **Why it matters:** [the principle in one line]
  * **HAX remediation:** [real element and placement]

### 🛠 Implementation handoff
* [one line per fix → skill / CLI]
```

## Acceptance criteria

- Every finding names the media item and gives evidence.
- Never recommend removing captions or transcripts; accessibility requirements win over redundancy.
- Every remediation is a real HAX element.

## Gotchas

- **Redundancy is not about captions.** Mayer's redundancy effect concerns on-screen text duplicating narration for learners who can hear it; WCAG still requires captions. Recommend captions be available, not removed.
- **Decorative is not always bad.** A single banner image that sets context is fine; flag images that compete with explanatory content.
- **Principles are strongest for novices** and for complex, unfamiliar material.

## References

- Mayer, R. E. (2021). *Multimedia Learning* (3rd ed.). Cambridge University Press.
- Mayer, R. E., & Fiorella, L. (Eds.). (2022). *The Cambridge Handbook of Multimedia Learning* (3rd ed.). Cambridge University Press.
- Guo, P. J., Kim, J., & Rubin, R. (2014). How video production affects student engagement. *Proceedings of the First ACM Conference on Learning @ Scale*, 41–50.
- Sweller, J., van Merriënboer, J. J. G., & Paas, F. (2019). Cognitive architecture and instructional design: 20 years later. *Educational Psychology Review*, 31, 261–292.
- Stanney, Skinner & Hughes (2023), ELEVATE-XR (fidelity and extraneous load). https://doi.org/10.1080/10447318.2023.2188529
