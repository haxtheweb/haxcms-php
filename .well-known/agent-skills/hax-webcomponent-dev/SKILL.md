---
name: hax-webcomponent-dev
description: >
  Develop HAX-capable web components using LitElement, DDD design system, and HAXSchema.
  Use when scaffolding new components, adding HAX editor support, auditing accessibility,
  or applying DDD tokens to elements in the webcomponents monorepo.
version: 1.0.0
license: Apache-2.0
metadata:
  author: haxtheweb
  tags: [hax, webcomponents, lit, ddd, haxschema, accessibility]
---

# HAX Web Component Development

Develop HAX-capable web components using LitElement, DDD design system, and HAXSchema.

## When to Use

- Scaffolding a new component in the webcomponents monorepo
- Adding or updating `haxProperties` / `demoSchema`
- Auditing a component for DDD compliance or accessibility
- Refactoring legacy SimpleColors usage to DDD tokens
- Fixing build issues with HAXCMSLitElement themes
- Reviewing component JavaScript for Polymer parser compatibility

## How It Works

1. **Scaffold**: Always use `hax webcomponent my-element --y` instead of manual creation. Do not create directories or files manually in the monorepo.
2. **Import DDD**: Use `import '@haxtheweb/d-d-d/d-d-d.js'` and extend `DDD` directly (never `DDD(LitElement)`). With mixins, DDD must be the base class: `class MyEl extends SomeMixin(DDD) {}`.
3. **Implement HAXSchema**: Add `haxProperties()` with `demoSchema` for editor examples. Use demoSchema and HAX helper methods to create valid demos with appropriate tag names, properties, and slotted content.
4. **Style with DDD**: Use `ddd-` prefixed CSS custom properties for spacing, colors, typography, and icon sizes. Only use SimpleColors where explicitly needed as fallback.
5. **Audit**: Check DDD token usage, ARIA attributes, keyboard navigation, semantic HTML, and dark mode compliance.
6. **Build**: Run `yarn run build` after changes to HAXCMSLitElement themes. Do not manually edit `custom-elements.json` — it is auto-generated.

## JavaScript Standards

- Use `globalThis` instead of `window` for global references
- Do not use optional chaining (`?.`) — the Polymer parser has issues with this syntax
- Use single quotes, avoid semicolons where possible, prefer functional patterns
- Use Prettier for consistent formatting
- No TypeScript — pure JavaScript with LitElement
- When using third-party libraries, import the pre-compiled JavaScript distribution

## Component Audits

When working on any component, perform these checks:

1. **DDD Design System**: Verify proper usage of design tokens
2. **Accessibility**: Check ARIA attributes, keyboard navigation, semantic markup
3. **HAX Schema**: Ensure complete and accurate haxProperties implementation
4. **Performance**: Review bundle size, lazy loading opportunities
5. **Browser Compatibility**: Test across supported browser versions

## References

- For DDD token reference: `references/ddd-tokens.md`
- For HAXSchema examples: `references/haxschema-examples.md`
