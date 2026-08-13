---
name: hax-site-building
description: >
  Build and maintain HAXcms sites with proper structure, themes, and JSON Outline Schema.
  Use when creating a new HAXsite, editing site.json, managing page content, or modifying themes
  in the HAXcms ecosystem.
version: 1.0.0
license: Apache-2.0
metadata:
  author: haxtheweb
  tags: [hax, haxcms, site, json-outline-schema, theme, jos]
---

# HAX Site Building

Build and maintain HAXcms sites with proper structure, themes, and JSON Outline Schema.

## When to Use

- Creating a new HAXsite for testing or production
- Editing `site.json` structure or metadata
- Adding or modifying pages in the `pages/` directory
- Managing static assets in the `files/` directory
- Customizing HAXcms themes that inherit from HAXCMSLitElement
- Importing content from external sources (WordPress, Notion, HTML, etc.)

## How It Works

1. **Scaffold**: Always use `hax site mysite --y` instead of manual creation. Do not create site directories or files manually.
2. **Structure**: Keep all documentation in `docs/`, all files in `files/`, and all page HTML in `pages/`. Maintain `site.json` in JSON Outline Schema format.
3. **Metadata**: Ensure `metadata.site.name` aligns exactly with the folder name. Do not modify it to anything else.
4. **Routes**: Do not create pages or routes using the `x/` prefix — it is reserved for internal HAXcms paths (`x/search`, `x/tags`, `x/manifest`).
5. **Content**: Use HAX-capable web components for rich content. Apply DDD attributes for consistent spacing and typography. Include engaging visual elements (videos, tables, interactive components).
6. **Theme**: Use HAXCMSLitElement for custom theme components. Apply DDD design tokens consistently. Run `yarn run build` after theme changes.
7. **Import**: Use supported import methods (`pressbooksToSite`, `htmlToSite`, `notionToSite`, `docxToSite`, etc.) via `hax site --import-site <url> --import-structure <method>`.

## Educational Content Standards

When creating educational content:
- Apply OER Schema metadata parameters for semantic structure and interoperability
- Structure content to support pedagogical objectives
- Include proper learning resource identification
- Consider diverse learning styles in content presentation
- Maintain comprehensive ecosystem context in documentation

## Build Workflow

- Run `yarn run build` after theme modifications involving HAXCMSLitElement
- Do not manually edit `custom-elements.json` or generated manifest files
- Use HAX CLI tools for site operations rather than manual file edits
- Ensure `site.json` is valid JSON Outline Schema before committing

## References

- For site structure examples: `references/site-structure.md`
- For import methods: `references/import-methods.md`
