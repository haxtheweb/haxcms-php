---
name: hax-claudehax
description: >
  Operate HAX sites from Claude Code using the ClaudeHAX plugin, HAX CLI, and HAX web components.
  Use when the user wants to add pages, create courses, update content, add assessments, add quizzes,
  add videos, use HAX web components, analyze existing HAX sites, or suggest improvements using
  the ClaudeHAX /hax slash command or HAX CLI directly.
version: 1.0.0
license: Apache-2.0
metadata:
  author: haxtheweb
  tags: [hax, claudehax, claude-code, plugin, site-operations, content, assessments]
---

# HAX ClaudeHAX Plugin

Operate HAX sites from Claude Code using the ClaudeHAX plugin, HAX CLI, and HAX web components.

## When to Use

- The user has the ClaudeHAX plugin installed (`/plugin install hax-site-ops@haxtheweb`)
- Adding pages, courses, or lessons to a HAX site
- Updating page content, videos, or media
- Adding assessments, quizzes, flash cards, or interactive content
- Analyzing a HAX site structure and suggesting improvements
- Inspecting available HAX web components and recommending usage
- Creating educational content with HAX components
- Using the `/hax` slash command from within Claude Code

## Prerequisites

Before using ClaudeHAX, verify the environment:

1. **Claude Code** installed: `claude --version`
2. **Node.js 22+**: `node -v`
3. **npm** installed: `npm -v`
4. **HAX CLI** installed globally: `npm install -g @haxtheweb/create`, verify with `hax --help`

**Important**: Do not use `npx hax` — that resolves to a different npm package. Use `npx @haxtheweb/create` or the globally installed `hax` command.

## Plugin Installation

Inside Claude Code:

1. Add the marketplace:
   ```
   /plugin marketplace add haxtheweb/praw
   ```
2. Install the plugin:
   ```
   /plugin install hax-site-ops@haxtheweb
   ```
3. Verify:
   ```
   /plugin list
   ```
Should show `hax-site-ops` in the list.

To update the plugin when a new version is released:
```
/plugin update hax-site-ops
```

## Common /hax Commands

Use the `/hax` slash command in Claude Code to operate on HAX sites:

- **Add pages**:
  ```
  /hax add 3 pages about iguanas
  /hax create a mini lesson page about reptile habitats and include at least two HAX web components
  ```
- **Analyze a site**:
  ```
  /hax analyze this site and suggest 20 new pages
  /hax inspect the available HAX web components and recommend 10 components that would improve this site
  ```
- **Add assessments**:
  ```
  /hax add a multiple choice quiz to this page based on the page content
  /hax add an interactive check-for-understanding section to the iguana overview page using HAX web components, not plain HTML
  /hax add a quiz to the Penn State University page
  ```
- **Add media**:
  ```
  /hax find a YouTube video and add it to the Ohio State University page
  /hax find a page that would benefit from flash cards and add 5 flash cards using the best HAX web component
  ```

## HAX CLI Reference

When operating outside the `/hax` slash command, use the HAX CLI directly:

- `hax --help` — List all commands
- `hax site --help` — Site-specific commands
- `hax start` — Interactive CLI with ASCII art
- `hax serve` — Development server at http://localhost
- `hax webcomponent <name> --y` — Scaffold a new component
- `hax site <name> --y` — Create a new HAXsite
- `hax audit` — DDD compliance audit
- `hax update` — Update HAX CLI

**Automation flags** (always use when scripting to prevent interruptions):
- `--y` or `--auto` — Auto-accept all prompts
- `--no-i` — Prevent interactive sub-processes
- `--skip` — Skip animations
- `--quiet` — Suppress console logging

## HAX Component Preference

When the user asks for interactive, educational, assessment, media, layout, or engagement-related content, prefer HAX web components over plain HTML.

Common mappings:

- Quizzes / multiple choice → `multiple-choice`
- Fill-in-the-blank activities → `fill-in-the-blanks`
- Flash cards → `flash-card`
- Videos → `video-player`
- Tabs → `a11y-tabs`
- Accordions → `a11y-collapse`
- Galleries → gallery-related components
- Timelines → timeline-related components
- Cards → card-related components
- Callouts → appropriate HAX UI components

If exact syntax is uncertain, inspect component documentation, examples, demos, or source files. Prefer HAX components over custom HTML whenever a suitable component exists.

## Project Discovery

Before changing anything:

1. Determine the current working directory.
2. Determine whether this is a HAX project.
3. Inspect project structure.
4. Identify available site configuration.
5. Identify available content.

Look for files such as:
- `site.json`
- `package.json`
- `haxcms.json`
- `manifest.json`
- site manifest files
- HAX-related configuration

If a site root is provided, use it. Otherwise default to `--root .`.

## Safety Guidelines

Before destructive operations (deleting pages, overwriting large amounts of content, restructuring a site, publishing changes), explain the planned changes and request confirmation.

For non-destructive operations (adding pages, creating content, adding components, analyzing sites, exporting content), proceed without requiring additional confirmation.

## Response Format

After completing work, provide:
1. Summary of the request.
2. Actions taken.
3. Commands used.
4. Pages or content affected.
5. Verification results.
6. Suggested next steps.

Keep responses concise and practical.

## Troubleshooting

### Plugin installed but `/hax` does not exist
Verify with `/plugin list`. If `hax` is missing, run `/plugin install hax-site-ops@haxtheweb`.

### Marketplace installation fails
Verify internet access and confirm `/plugin marketplace add haxtheweb/praw` is spelled correctly.

### `hax` command not found
Install the HAX CLI: `npm install -g @haxtheweb/create`, then verify with `hax --help`.

### Node.js version too old
Check `node -v`. Upgrade to Node.js 22+ if needed.

### Plugin behavior seems outdated
Update the plugin: `/plugin update hax-site-ops`. If the plugin references outdated HAX commands or components, the maintainer must run `npm run refresh:docs` in the praw repo and push.

## OpenStax Import Workflow

If the user is starting from an OpenStax book, use the `hax-openstax2hax` skill first to prepare the conversion. openstax2hax writes `conversion/hax-handoff-prompt.md`, which claudehax then consumes to build the HAX site. Do not attempt to build directly from raw OpenStax content without going through openstax2hax first.

## References

- ClaudeHAX plugin marketplace (PRAW): `https://github.com/haxtheweb/praw`
- HAX CLI package: `https://www.npmjs.com/package/@haxtheweb/create`
- HAX Documentation: `https://haxtheweb.org/`
- HAX web components: `https://github.com/haxtheweb/webcomponents/tree/master/elements`
