---
name: hax-site-structure
description: >
  Read and navigate an existing HAXcms site from its portable files. Use when an agent lands on a
  HAXsite or HAXcms deployment and needs to understand site.json (JSON Outline Schema), resolve
  page content in pages/, find assets in files/, locate the theme, compute basePath, or discover
  the web component registry (wc-registry.json) and the magic-script autoloader. Load this FIRST
  when working with any HAX site so the rest of the ecosystem makes sense.
version: 1.0.0
license: Apache-2.0
metadata:
  author: haxtheweb
  tags: [hax, haxcms, site, json-outline-schema, jos, structure, discovery]
---

# HAX Site Structure

Read and navigate an existing HAXcms site from its portable, file-based structure. This is the
ground-truth skill for understanding a site you did not build.

## When to Use

- An agent discovers a HAXsite (single-site deploy) or a HAXcms deployment (multi-site backend)
  and needs to understand what is there.
- Resolving a page's content, metadata, or location from `site.json`.
- Locating static assets, theme files, or the web component registry.
- Computing the correct base path for a site.
- Figuring out which files are safe to read vs. which are generated and should be rebuilt.

## Canonical structure

A HAXsite is a directory with this shape (the HAXcms boilerplate):

```
<site>/
├── site.json                 # canonical manifest + navigation tree (JSON Outline Schema)
├── pages/<item-id>/index.html  # authored page content (one dir per page)
├── files/                    # static assets (images, pdfs, uploads)
├── theme/                    # theme.css + theme assets (custom theme layer)
├── custom/                   # custom build (custom.es6.js) when present
├── assets/                   # icon/browser assets
├── build/ build.js build-haxcms.js  # autoloader wiring
├── wc-registry.json          # web component registry (built artifact, see below)
├── llms.txt                  # LLM-oriented site guide (generated)
├── robots.txt sitemap.xml    # crawler maps (generated)
├── lunrSearchIndex.json      # search corpus (generated)
├── manifest.json service-worker.js  # PWA (generated)
└── .well-known/              # security.txt, api-catalog, agent-skills/
```

## site.json — JSON Outline Schema

`site.json` is the single source of truth for structure and navigation. Shape:

```
{
  "id", "title", "author", "description", "license",
  "metadata": {
    "site": { "name", "logo", "settings": { "lang", "publishPagesOn", "pathauto", "private", "canonical", ... }, "domain", "git", "version", "tags" },
    "theme": { "element", "path", "variables", "regions" },
    "build": { "version", "structure", "type" },
    "node": { "fields": {} }
  },
  "items": [
    { "id", "indent", "location", "slug", "order", "parent", "title", "description", "metadata": { "created", "updated", "published", "locked", "videos", "images", "status" } }
  ]
}
```

Key rules for reading items:
- `parent` is an item id or `null` (top level). `order` is the sibling order. `indent` is depth.
- `location` is the page content path relative to the site root (e.g. `pages/<item-id>/index.html`).
- `slug` is the URL path segment. A nested slug is the full path (e.g. `welcome/community/pillars`).
- Traverse with `orderTree(items)` semantics: group by parent, sort children by `order`, recurse.
- `metadata.site.name` establishes the basePath in some deployment scenarios and MUST align with
  the site folder name. Do not change it to anything else.

## Page content

- Authored HTML lives at the `location` path, conventionally `pages/<item-id>/index.html`.
- A markdown alternate may exist alongside it (`getPageAlternateLocation(location, 'md')`). When
  present, prefer it for LLM reading; it is the clean content without editor chrome.
- Page content is authored with HAX-capable web components (see hax-content-authoring). Read it as
  HTML; the component tags autoload via the registry.

## wc-registry.json and the magic-script autoloader

`wc-registry.json` is a built artifact (produced by the ubiquity build, not hand-edited) that maps
every valid web component tag name to its CDN/module path. The autoloader (`build.js` /
`wc-autoload`) hydrates the page by: detecting a tag name that is `undefined` in the DOM, looking
it up in the registry, and dynamically importing the module at that key. This is how a page full of
custom elements works without explicit imports. To know which tags are valid on a page, read
`wc-registry.json`. To know which are authorable in the HAX editor, a tag must have `haxProperties`
(see hax-content-authoring).

## basePath

`basePath` is `<basePath prefix>/<metadata.site.name>/`. For a single-site deploy on its own
domain it is `/`; for a multi-site HAXcms backend it is `/sites/<site.name>/`. The managed files
(llms.txt, robots.txt, sitemap) are generated with absolute URLs rooted at the site's `domain` +
basePath, so prefer reading those over reconstructing paths by hand.

## Reserved routes

The `x/` route prefix is reserved for internal HAXcms paths (`x/search`, `x/tags`,
`x/manifest`). Do not author pages or routes under `x/`.

## Generated vs authored (short version)

- Authored (safe to read/edit directly): `site.json` (via CLI, not hand-edit), `pages/*/index.html`
  (content), `files/`, `theme/`, `custom/`.
- Generated (rebuild, do not hand-edit): `llms.txt`, `robots.txt`, `sitemap*.xml`, `rss.xml`,
  `atom.xml`, `lunrSearchIndex.json`, `manifest.json`, `service-worker.js`, `push-manifest.json`,
  `wc-registry.json`, `.well-known/*`, `build*.js`, `index.html`/`404.html`/`ghpages.html`
  (static fallbacks). See hax-managed-files for the full list and the rebuild rules.

## Entry points for an agent landing on a published site

1. `/.well-known/agent-skills/index.json` — this skill set (discovery).
2. `/llms.txt` — site overview + links to machine-readable resources.
3. `/site.json` — the structure tree.
4. `/lunrSearchIndex.json` — full-text search corpus.
5. `/sitemap.xml` — URL-level discovery map.

## References

- Related skills: `hax-content-authoring`, `hax-managed-files`, `hax-design-system`,
  `hax-a11y-audit`, `hax-site-building` (for creating new sites, not reading existing ones).
- JSON Outline Schema: https://github.com/haxtheweb/json-outline-schema
