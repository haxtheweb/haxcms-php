---
name: hax-managed-files
description: >
  Know which HAXcms files are generated (rebuild, do not hand-edit) vs authored, and the protective
  rules around builds. Use when an agent is about to edit a managed file, run a build, or pick
  which CLI command to use. Prevents the common footguns: hand-editing generated artifacts, running
  the ubiquity script, running a top-level monorepo build, or using npx instead of the local hax CLI.
  Protective skill — load before modifying files or running builds on a HAX site or deployment.
version: 1.0.0
license: Apache-2.0
metadata:
  author: haxtheweb
  tags: [hax, haxcms, managed-files, build, ubiquity, cli, safety]
---

# HAX Managed Files

HAXcms distinguishes **authored** files (human/tool-controlled, safe to edit) from **managed**
files (generated artifacts, rebuilt by tooling, never hand-edited). This skill lists both and the
protective build rules. It exists to stop an agent from corrupting a site by editing generated
output or running forbidden builds.

## When to Use

- Before editing any file in a HAX site or HAXcms deployment.
- When deciding whether to run a build command.
- When choosing between the local `hax` CLI and `npx`.
- When a generated file looks wrong and the instinct is to "just fix it in place."

## Authored files (safe to edit directly)

- `site.json` — structure and metadata. Edit via the `hax` CLI, not by hand, to avoid production
  issues. `metadata.site.name` must stay aligned with the site folder name.
- `pages/<item-id>/index.html` — page content. This is where authored HTML lives.
- `files/` — static assets (images, pdfs, uploads).
- `theme/` — custom theme (theme.css + assets). For theme classes inheriting from
  HAXCMSLitElement, run `yarn run build` after changes; do not manually edit `custom-elements.json`.
- `custom/` — custom build layer (`custom.es6.js`) when present.
- `docs/` — documentation (on the HAXcms docs site).

## Managed files (generated — rebuild, never hand-edit)

These are produced by `rebuildManagedFiles()` and the ubiquity build. Editing them in place is
futile (the next rebuild overwrites them) and can corrupt a site.

- SEO / discovery: `llms.txt`, `robots.txt`, `sitemap.xml`, `sitemap-index.xml`, `rss.xml`,
  `atom.xml`.
- Search: `lunrSearchIndex.json`.
- PWA: `manifest.json`, `service-worker.js`, `push-manifest.json`, `browserconfig.xml`,
  `offline.html`.
- Registry / autoloader: `wc-registry.json` (built by ubiquity), `build.js`, `build-haxcms.js`.
- Static fallbacks: `index.html`, `404.html`, `ghpages.html`, `assets/upgrade-browser.html`.
- well-known: `.well-known/security.txt`, `.well-known/api-catalog`,
  `.well-known/agent-skills/index.json` and the agent-skills skill files.
- Config / tooling: `package.json`, `web-dev-server.haxcms.config.cjs`, `.htaccess`,
  `files/.htaccess`.
- Backend (PHP/Node): `index.php`, `config.php` (PHP); SCORM `imsmanifest.xml` + the `*.xsd` files.

When a managed file is wrong, fix the **source** it is generated from, then rebuild:
- `llms.txt` / `robots.txt` / `sitemap` / `lunrSearchIndex` — regenerate via `rebuildManagedFiles`
  (triggered by save operations or the `hax` CLI).
- `wc-registry.json` / `build*.js` / minified `build/` assets — fix in the **webcomponents
  monorepo** source, verify locally, then the user runs the ubiquity build (see below).

## Protective build rules

- **Never run the ubiquity script.** It builds the CDN registry and distribution. The user runs it;
  an agent must not, under any circumstances.
- **Never run a build at the top of the webcomponents monorepo.**
- **Do not run traditional build commands in the monorepo** — they are not used.
- **Do not modify files in `build/` or `node_modules/`** — change the underlying source instead.
- When fixing minified build-directory issues in the PHP or NodeJS HAXcms backends, fix the problem
  in the webcomponents monorepo first. After local verification, the user runs the ubiquity build.
- When writing new HAXcms backend code (PHP/NodeJS), repurpose core data-model loading code rather
  than writing new abstractions. Prefer reusing existing methods over new file-path resolution
  abstractions.

## Use the local hax CLI, not npx

- Use the local/global `hax` command. Do not use `npx hax` — it resolves to a different package and
  may be stale. The local copy is always the latest (or experimental) because the source starts on
  this machine.
- When automating, pass `--y --no-i` (and `--auto` / `--quiet` / `--skip` as needed) so no prompts
  are asked and no new window/process launches. Without these, a command that displays the site can
  open a new window and hang.

## New elements

- All new elements in the monorepo must be created with the `hax webcomponent` CLI command (not
  manually) to ensure uniform demo/packaging/distribution files.

## References

- Related skills: `hax-site-structure` (what the files are), `hax-content-authoring` (editing page
  content), `hax-site-building` (creating sites).
- PRAW RULES.md: canonical ecosystem rules by Rule ID.
