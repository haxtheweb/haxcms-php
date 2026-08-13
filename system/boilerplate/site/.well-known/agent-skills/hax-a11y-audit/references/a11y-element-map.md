# HAX Accessibility Element Map (Verified)

The single source of truth for HAX components recommended by `hax-a11y-audit`. Every tag below
has been verified to exist under `webcomponents/elements/`. Never recommend a tag that is not in
this document. Every property documented below has been confirmed in the element source file.

## How to use this map

For each WCAG failure, the remediation is one of two kinds:
- **Component swap** — replace the failing authored markup with a real HAX component that bakes
  the accessibility in. Use `hax-claudehax` or `hax-site-building` to apply.
- **Plain-HTML fix** — edit the markup directly in `pages/<slug>.html`. No component needed;
  the fix is semantic HTML that screen readers already understand.

Many WCAG failures are plain-HTML fixes (alt text, headings, landmarks, link text, table
markup). Do not reach for a component when a semantic HTML tag is the correct fix.

---

## Component swap (real HAX elements that bake in accessibility)

### Unlabelled form fields → `simple-fields`

**WCAG criterion:** 3.3.2 Labels or Instructions, 4.1.2 Name, Role, Value
**Element:** `simple-fields` — `webcomponents/elements/simple-fields/`
**Why:** `simple-fields` is the HAX-preferred input ecosystem. It bakes in the label/field
association, field type, and ARIA wiring automatically. Authoring a bare `<input>` with a
detached `<label>` is the common failure; swapping to `simple-fields` removes the failure mode
entirely. Per PRAW rules, use `simple-fields` for all input elements to ensure design
consistency and accessibility.

### Progressive disclosure / wall-of-links → `a11y-collapse` (with `heading-button`)

**WCAG criterion:** 2.4.3 Focus Order, 4.1.2 Name, Role, Value
**Element:** `a11y-collapse` — `webcomponents/elements/a11y-collapse/`
**Critical property:** `heading-button` (attribute `heading-button`, source line 201/204).
**Why:** `a11y-collapse` provides an accessible collapsible section. ALWAYS set `heading-button`
so the entire heading is clickable to expand/collapse, not just the small toggle icon — it is
much easier for end users (and keyboard users) to click the whole bar. Without it, the click
target is tiny and the heading is not a button.
```html
<a11y-collapse heading-button>
  <span slot="heading">Section title</span>
  <p>Collapsed content...</p>
</a11y-collapse>
```

### Categorized / tabbed content → `a11y-tabs`

**WCAG criterion:** 2.4.3 Focus Order, 4.1.2 Name, Role, Value
**Element:** `a11y-tabs` — `webcomponents/elements/a11y-tabs/`
**Why:** `a11y-tabs` provides an accessible tabbed interface with correct ARIA tab/tabpanel
roles and keyboard arrow navigation. Use it instead of hand-rolled `<div role="tab">` markup
that usually gets the ARIA wrong.

### Data tables → `editable-table` / `editable-table-display`

**WCAG criterion:** 1.3.1 Info and Relationships, 4.1.2 Name, Role, Value
**Elements:**
- `editable-table` — `webcomponents/elements/editable-table/` (authoring surface)
- `editable-table-display` — `webcomponents/elements/editable-table/lib/editable-table-display.js`
  (read-only display; the tag is `<editable-table-display>`)
**Why:** `editable-table-display` renders an accessible data table with correct `scope`,
`<th>`, and caption semantics. Per PRAW rules, use `editable-table-display` for consistent
presentation in admin panels and data displays. Use `editable-table` when the author needs to
edit the table in HAX. Do not hand-roll `<table>` markup with missing `<caption>` or `scope`.

### Audio playback → `media-playlist` + `audio-player`

**WCAG criterion:** 1.2.1 Audio-only and Video-only (Alternatives), 1.2.2 Captions
**Elements:**
- `media-playlist` — `webcomponents/elements/media-playlist/`
- `audio-player` — `webcomponents/elements/audio-player/` (extends `video-player`, source line 12)
**Why:** Per PRAW rules, audio is presented via `media-playlist` + `audio-player`, not inline
`<audio>`. `audio-player` inherits `track` and `tracks` from `video-player` for VTT transcript
support, and renders an interactive transcript panel when a `track` (WebVTT) is supplied.
```html
<media-playlist>
  <audio-player
    source="files/audio/lecture.mp3"
    track="files/audio/lecture.vtt"
    media-title="Lecture 1">
  </audio-player>
</media-playlist>
```

### Video captions / transcript → `video-player` with `track`

**WCAG criterion:** 1.2.2 Captions, 1.2.3 Audio Description or Media Alternative, 1.2.5 Audio Description
**Element:** `video-player` — `webcomponents/elements/video-player/`
**Verified properties:**
- `track` (String, source line 571) — URL for a single subtitle/caption VTT file.
- `tracks` (Array, source line 579) — Array of track objects for multiple languages:
  `[{ "src": "path/to/track.vtt", "label": "English", "srclang": "en", "kind": "subtitles" }]`.
- `audioDescriptionSource` (String, attribute `audio-description-source`, source line 630) —
  URL of an audio-description audio file. When set and `audioDescriptionEnabled` is true, the
  player renders an audio-description toggle.
**Why:** `video-player` is the HAX-capable video element. Supplying a `track` (VTT captions)
satisfies 1.2.2. Supplying `audio-description-source` satisfies 1.2.5. This audit flags
**presence** (is a `track` attribute set at all?); caption quality/timing/accuracy and
audio-description authoring/coverage are `hax-media-a11y`'s depth scope — hand off for depth.
```html
<video-player
  source="files/video/lecture.mp4"
  track="files/video/lecture-captions.vtt"
  audio-description-source="files/video/lecture-audio-description.mp3">
</video-player>
```

### Accessible media player with transcript → `a11y-media-player`

**WCAG criterion:** 1.2.2 Captions, 1.2.5 Audio Description, 2.1.1 Keyboard
**Element:** `a11y-media-player` — `webcomponents/elements/a11y-media-player/`
**Why:** `a11y-media-player` provides an accessible media player with transcript panel,
caption support, and keyboard controls. It is an alternative to `video-player` when a
built-in transcript panel is needed. Use it when the page needs the transcript rendered
alongside the player rather than as a separate page.

### Accessible figures (image + caption) → `a11y-figure`

**WCAG criterion:** 1.1.1 Non-text Content, 1.3.1 Info and Relationships
**Element:** `a11y-figure` — `webcomponents/elements/a11y-figure/`
**Verified properties (haxProperties, source lines 154-156):** `source` (image URL), `alt`
(alt text), `caption` (figcaption content).
**Why:** `a11y-figure` wraps an image in an accessible `<figure>` with a `<figcaption>`. It
enforces the alt-text requirement and associates the caption programmatically. Use it instead
of a bare `<img>` followed by an unrelated `<p>` "caption" that screen readers cannot
associate.
```html
<a11y-figure
  source="files/diagram.png"
  alt="Flowchart showing the three-step approval process"
  caption="Figure 1: The approval process from request to sign-off.">
</a11y-figure>
```

### Images → `media-image` (with `alt`)

**WCAG criterion:** 1.1.1 Non-text Content
**Element:** `media-image` — `webcomponents/elements/media-image/`
**Verified properties:** `source` (String, source line 298), `alt` (String, source line 323),
`caption` (String, source line 317).
**Why:** `media-image` is the HAX-capable image element. Its `alt` property is the text
alternative. Set `alt=""` only when the image is genuinely decorative; set descriptive alt for
informative images. Do not omit the `alt` property — a missing alt attribute is a failure.
```html
<media-image
  source="files/enrollment-chart.png"
  alt="Bar chart: enrollment doubled from 2019 to 2024">
</media-image>
```

### Presentational icons → `simple-icon-lite` with `aria-hidden`

**WCAG criterion:** 1.1.1 Non-text Content, 4.1.2 Name, Role, Value
**Element:** `simple-icon-lite` — `webcomponents/elements/simple-icon/lib/simple-icon-lite.js`
**Why:** Per PRAW rules, use `simple-icon-lite` (not `simple-icon`) because it can be colorized
via light-DOM CSS `color`. It renders an SVG icon with `focusable="false"` on the internal SVG
(source line 74), preventing IE/Edge focus issues. For presentational icons (icons that
decorate adjacent text and convey no additional information), set `aria-hidden="true"` on the
`simple-icon-lite` host element so screen readers skip it. `aria-hidden` is a native global
attribute that works on any element, including custom elements.
```html
<simple-icon-lite icon="icons:arrow-forward" aria-hidden="true"></simple-icon-lite>
<span>Next step</span>
```
**Do NOT use `simple-icon-button-lite` for purely decorative icons** — that element is a
button (interactive); use it only when the icon is the sole content of a clickable button, and
always provide an `aria-label` on it.

### Interactive icon buttons → `simple-icon-button-lite` with `aria-label`

**WCAG criterion:** 4.1.2 Name, Role, Value
**Element:** `simple-icon-button-lite` — `webcomponents/elements/simple-icon/lib/simple-icon-button-lite.js`
**Why:** Per PRAW rules, prefer `simple-icon-button-lite` for buttons. When the button's only
visible content is an icon, it MUST have an `aria-label` so screen readers announce its
purpose. An icon button without `aria-label` is a nameless control — a Level A failure.
```html
<simple-icon-button-lite icon="icons:search" aria-label="Search the site"></simple-icon-button-lite>
```

### Context / objectives / reflection → `stop-note`

**WCAG criterion:** 1.3.1 Info and Relationships (supplementary, not a WCAG failure fix)
**Element:** `stop-note` — `webcomponents/elements/stop-note/`
**Why:** `stop-note` is the HAX reflection/stop component. While not a direct WCAG remediation,
it provides an accessible, labeled callout for context, objectives, or reflection prompts. Use
it to give screen-reader users a labeled region for supplementary content. It is also useful
for providing a long description of a complex image (satisfying 1.1.1 for complex diagrams
where short alt is insufficient).

### OER Schema metadata → `oer-schema`

**WCAG criterion:** Supplementary (semantic structure, not a direct WCAG criterion)
**Element:** `oer-schema` — `webcomponents/elements/oer-schema/`
**Verified properties:** `oerProperty` (attribute `oer-property`), `typeof` (schema.org type),
`relatedResource` (attribute `related-resource`).
**Why:** Per PRAW rules, educational elements should get `oer-schema` metadata for semantic
structure and interoperability. While not a WCAG criterion itself, semantic metadata improves
machine readability and supports accessible content discovery. Apply it to educational
elements for consistent semantic structure.

### Math notation → `lrn-math`

**WCAG criterion:** 1.1.1 Non-text Content (math as image), 1.3.1 Info and Relationships
**Element:** `lrn-math` — `webcomponents/elements/lrn-math/`
**Why:** Math rendered as an image has no accessible text alternative. `lrn-math` renders math
notation accessibly (MathML/LaTeX) so screen readers can read it. Flag any math presented as
an image (`<img>` of an equation) and swap to `lrn-math`.

---

## Plain-HTML fix (semantic HTML edits in `pages/<slug>.html`)

Many WCAG failures are fixed by editing the HTML directly — no component swap needed. Apply
these via `hax-site-building` (edit `pages/<slug>.html`) or `hax-claudehax`.

### Missing or junk alt text → add descriptive `alt`

**WCAG 1.1.1.** Edit the `alt` attribute directly:
```html
<!-- Fail: missing alt -->
<img src="chart.png">
<!-- Fail: junk alt -->
<img src="chart.png" alt="image">
<!-- Pass: descriptive alt -->
<img src="chart.png" alt="Bar chart: enrollment doubled from 2019 to 2024">
<!-- Pass: decorative -->
<img src="divider.png" alt="">
```

### Heading hierarchy → one `h1`, no skipped levels

**WCAG 1.3.1.** Ensure exactly one `h1` per page (the page title), then `h2`, `h3`, `h4` in
order with no skips. Edit the heading tags directly in `pages/<slug>.html`.
```html
<!-- Fail: h2 then h4, no h1 or h3 -->
<h2>Introduction</h2>
<h4>Course goals</h4>
<!-- Pass: h1, h2, h3 in order -->
<h1>Introduction</h1>
<h2>Course goals</h2>
<h3>Learning outcomes</h3>
```

### Landmark structure → semantic HTML5 landmarks

**WCAG 1.3.1, 2.4.1 Bypass Blocks.** Use semantic HTML5 landmark elements instead of
div-soup. A page should have at least a `main` landmark.
```html
<!-- Fail: div-soup, no main landmark -->
<div class="header">...</div>
<div class="content">...</div>
<div class="footer">...</div>
<!-- Pass: semantic landmarks -->
<header>...</header>
<nav>...</nav>
<main>
  <article>...</article>
  <aside>...</aside>
</main>
<footer>...</footer>
```

### Link text → descriptive, no "click here"

**WCAG 2.4.4, 2.4.9.** Replace ambiguous link text with text that describes the destination.
```html
<!-- Fail -->
<a href="/syllabus">Click here</a>
<a href="/syllabus">Read more</a>
<a href="https://example.com">https://example.com</a>
<!-- Pass -->
<a href="/syllabus">Read the syllabus</a>
<a href="/course-schedule">View the course schedule</a>
```

### Form label association → `for` / `id`

**WCAG 3.3.2, 4.1.2.** Associate every `<label>` with its field via `for` / `id`, or use
`aria-label` / `aria-labelledby` when a visible label is not possible.
```html
<!-- Fail: label not associated -->
<label>Search</label>
<input type="text" name="q">
<!-- Pass: associated -->
<label for="q">Search</label>
<input type="text" id="q" name="q">
<!-- Pass: aria-label when no visible label -->
<input type="search" aria-label="Search the site">
```

### Data-table markup → `<caption>`, `scope`, `headers`

**WCAG 1.3.1.** Add a `<caption>`, use `scope="col"` / `scope="row"` on `<th>`, and use
`headers` / `id` for complex tables.
```html
<!-- Fail: no caption, no scope -->
<table>
  <tr><th>Name</th><th>Score</th></tr>
  <tr><td>Alice</td><td>95</td></tr>
</table>
<!-- Pass: caption + scope -->
<table>
  <caption>Student scores by assignment</caption>
  <tr><th scope="col">Name</th><th scope="col">Score</th></tr>
  <tr><th scope="row">Alice</th><td>95</td></tr>
</table>
```

### Focus order → remove positive `tabindex`

**WCAG 2.4.3.** Positive `tabindex` values override the natural tab order and are an
anti-pattern. Remove them; let the DOM order define focus order. If a custom element needs to
be focusable, use `tabindex="0"` (in DOM order) not a positive value.
```html
<!-- Fail: positive tabindex forces an unnatural order -->
<button tabindex="3">Submit</button>
<button tabindex="1">Edit</button>
<!-- Pass: DOM order, no positive tabindex -->
<button>Edit</button>
<button>Submit</button>
```

### Redundant ARIA → remove roles that duplicate native semantics

**WCAG 4.1.2.** Do not add `role` to elements that already have the role natively.
```html
<!-- Fail: redundant role -->
<button role="button">Submit</button>
<nav role="navigation">...</nav>
<!-- Pass: native semantics, no redundant role -->
<button>Submit</button>
<nav>...</nav>
```

---

## Verified tags (quick reference)

Every tag below has a confirmed directory or source file under
`/home/bto108a/Documents/git/haxtheweb/webcomponents/elements/`.

| Tag | Location | Key a11y property |
|---|---|---|
| `simple-fields` | `simple-fields/` | bakes in label/field association |
| `a11y-collapse` | `a11y-collapse/` | `heading-button` (line 201/204) |
| `a11y-tabs` | `a11y-tabs/` | ARIA tab/tabpanel + keyboard arrows |
| `editable-table` | `editable-table/` | authoring surface |
| `editable-table-display` | `editable-table/lib/editable-table-display.js` | accessible table display |
| `media-playlist` | `media-playlist/` | player + sidebar layout |
| `audio-player` | `audio-player/` | extends `video-player` (line 12); `track`, `tracks` |
| `video-player` | `video-player/` | `track` (line 571), `tracks` (line 579), `audio-description-source` (line 630) |
| `a11y-media-player` | `a11y-media-player/` | transcript panel + caption support |
| `a11y-figure` | `a11y-figure/` | `source`, `alt`, `caption` (lines 154-156) |
| `media-image` | `media-image/` | `source` (298), `alt` (323), `caption` (317) |
| `simple-icon-lite` | `simple-icon/lib/simple-icon-lite.js` | native `aria-hidden`; SVG `focusable="false"` (line 74) |
| `simple-icon-button-lite` | `simple-icon/lib/simple-icon-button-lite.js` | requires `aria-label` when icon-only |
| `stop-note` | `stop-note/` | accessible labeled callout |
| `oer-schema` | `oer-schema/` | `oer-property`, `typeof`, `related-resource` |
| `lrn-math` | `lrn-math/` | accessible math notation |

## Excluded legacy / third-party tags (NEVER recommend)

These tags appear in older HAX courses but are NOT in the current monorepo. Use the
alternatives documented above instead.

| Excluded tag | Use instead |
|---|---|
| `instruction-card` | `stop-note` / `accent-card` (for callout content) |
| `lrndesign-sidenote` | `a11y-collapse` (with `heading-button`) / `stop-note` |
| `link-preview` | plain `<a>` with descriptive link text |
| `model-viewer` | `media-image` / `a11y-figure` (for 3D model screenshots) |

## Overlap note for `hax-media-a11y` (symmetric partner)

This map covers media **presence** (is a `track` / `audio-description-source` set at all?).
Media **depth** — caption quality/timing/accuracy, transcript fidelity, audio-description
authoring/coverage, and asset production — is `hax-media-a11y`'s scope. When this audit flags
a media presence gap, hand the depth work to `hax-media-a11y`. When `hax-media-a11y` finds that
no track file exists at all (a presence gap), it should hand back to this skill to file the
WCAG 1.2 finding. The rule is symmetric.
