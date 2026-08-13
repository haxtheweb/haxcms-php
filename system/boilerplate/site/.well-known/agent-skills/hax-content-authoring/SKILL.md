---
name: hax-content-authoring
description: >
  Author HAX page content using the right web components and conventions. Use when writing or
  editing page HTML in pages/, adding HAX-capable elements, applying DDD heading/paragraph
  attributes, choosing input/icon/button/media components, creating pages via the hax CLI, or
  remixing a HAX site's content as OER. Covers which tags are valid (HAXSchema/haxProperties),
  the preferred component families, and the authoring rules that keep content portable and
  editable in the HAX editor.
version: 1.0.0
license: Apache-2.0
metadata:
  author: haxtheweb
  tags: [hax, content, authoring, haxschema, ddd, webcomponents, oer]
---

# HAX Content Authoring

Author and edit HAX page content (the HTML in `pages/<item-id>/index.html`) using the correct web
components and conventions so the content stays editable in the HAX editor and portable across the
ecosystem.

## When to Use

- Writing or editing the HTML content of a HAX page.
- Choosing which web component tags to put in a page.
- Applying DDD design-system attributes for headings and paragraphs.
- Creating a new page or restructuring page content via the `hax` CLI.
- Remixing an existing HAX site's content as OER.

## Only use HAX-capable components

A tag is authorable in the HAX editor only if it has a `haxProperties` method (the HAXSchema
standard). The `demoSchema` part of that standard provides the info to create example elements.
To know which tags are valid, read `wc-registry.json` (the built registry of every published
component) and treat a tag as authorable only when it exposes `haxProperties`. Never invent tag
names that are not in the registry. When a page needs something the registry lacks, defer to
`hax-webcomponent-dev` rather than guessing.

## Preferred component families

- **Buttons:** use `simple-icon-button-lite` (not `simple-icon-button`).
- **Icons:** use `simple-icon-lite` from `simple-icon/lib` — it can be colorized via light-DOM CSS
  `color`; `simple-icon` is strictly controlled and used less often. Size icons with DDD icon-sizing
  variables, not spacing variables.
- **Inputs / forms:** use the `simple-fields` ecosystem of elements for design consistency and
  accessibility.
- **Admin / data tables:** use `editable-table-display` for consistent presentation in admin panels.
- **Collapsible sections:** use `a11y-collapse` and always set the `heading-button` property so the
  whole heading is clickable.
- **Media — audio:** prefer `media-playlist` + `audio-player` to present media and enable direct
  playback. `inline-audio` is future use (pending mp3 clipping tools).
- **Engagement:** `self-check`, `multiple-choice`, `fill-in-the-blanks`, `stop-note`, `flash-card`,
  `video-player` for check-ins, reflection, retrieval, and segmented video.

## DDD design system for content

- Apply DDD attributes to headings and paragraphs for consistent offset/spacing. Use DDD tokens for
  spacing, color, font weight, icon sizes. The DDD design system lives at `elements/d-d-d`.
- Import DDD from `@haxtheweb/d-d-d/d-d-d.js` and extend `DDD` directly (never `DDD(LitElement)`,
  which is incorrect syntax). With mixins, DDD must be the base class:
  `class MyEl extends SomeMixin(DDD) {}`.
- Use DDD's icon-sizing variables for icon height/width, not spacing variables.
- Check dark-mode compliance when auditing DDD usage.
- SimpleColors is the older color system, still used to fill shade gaps DDD does not cover. Prefer
  DDD colors; use SimpleColors only as a fallback where a shade is missing.
- Avoid inline styles (margin, padding, background, border-radius) in `demo/index.html` and similar;
  use CSS classes or design-system variables instead.

## Create pages via the hax CLI

- Always create pages through the `hax` command, never by hand-editing `site.json`, to avoid
  production issues.
- Use the local/global `hax` command — not `npx hax` (resolves to a different package).
- When scripting/automating, pass the automation flags to avoid prompts or new windows:
  `--y --no-i` (add `--auto` / `--quiet` / `--skip` as needed).
- To add a single page:
  `hax site node:add --title "<title>" --slug "<slug>" --content <path-to-html-file> --format html --y --no-i`
- To bulk import:
  `hax site site:items-import --items-import <items.json> --y --no-i`
- Verify structure: `hax site site:items`

## Reserved routes

Do not author pages or routes under the `x/` prefix — it is reserved for internal HAXcms paths
(`x/search`, `x/tags`, `x/manifest`).

## OER Schema for educational content

When creating educational elements, apply OER Schema metadata parameters (Course/Unit/Module/Lesson,
LearningObjective, Assessment/Quiz/Activity/Project, Rubric, TableOfContents, ActionType verbs) for
consistent semantic structure and interoperability. See the `oerschema-audit` skill for the
vocabulary and `oerschema-integration-finder` for component-level wiring.

## JavaScript conventions (when content includes scripts)

- Use `globalThis` instead of `window` for global references.
- Do not use optional chaining (`?.`) — the Polymer parser has issues with this syntax.
- Use single quotes; avoid semicolons where possible; prefer functional patterns.

## haxProperties placement

Prefer an external `haxProperties.json` file for property schemas whenever possible, especially when
the schema is not dynamic.

## References

- Related skills: `hax-site-structure` (read a site first), `hax-design-system` (DDD tokens),
  `hax-a11y-audit` (WCAG for authored content), `hax-managed-files` (what to edit vs rebuild),
  `hax-webcomponent-dev` (when a needed component does not exist yet).
- HAX web components registry: `wc-registry.json` on the site, or `webcomponents/elements` in the
  monorepo.
- HAX CLI: `hax site --help`, `hax --help`.
