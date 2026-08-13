---
name: hax-design-system
description: >
  Apply the DDD (Design, Develop, Deliver) design system and manage SimpleColors legacy usage.
  Use when styling components, auditing CSS for DDD compliance, migrating from SimpleColors,
  or creating new themes in the HAX ecosystem.
version: 1.0.0
license: Apache-2.0
metadata:
  author: haxtheweb
  tags: [hax, design-system, ddd, simplecolors, css, tokens, accessibility]
---

# HAX Design System

Apply the DDD (Design, Develop, Deliver) design system and manage SimpleColors legacy usage.

## When to Use

- Styling a new or existing web component
- Auditing CSS for DDD compliance
- Migrating SimpleColors usage to DDD tokens
- Creating or updating HAXcms themes
- Ensuring dark mode compliance across components

## How It Works

1. **Import DDD**: Always import `import '@haxtheweb/d-d-d/d-d-d.js'` and extend `DDD` directly (never `DDD(LitElement)`).
2. **Use DDD Tokens**: Apply DDD CSS custom properties for all styling:
   - `--ddd-font-primary`, `--ddd-font-secondary` for typography
   - `--ddd-font-size-*` (xs, s, ms, m, ml, l, xl, xxl) for font sizes
   - `--ddd-font-weight-*` (light, regular, medium, bold) for weights
   - `--ddd-spacing-*` (0-32) for margins, padding, gaps
   - `--ddd-radius-*` (xs, s, m, l, xl) for border radius
   - `--ddd-primary-*`, `--ddd-accent-*`, `--ddd-text-*`, `--ddd-border-*` for colors
   - `--ddd-breakpoint-*` for responsive breakpoints
3. **SimpleColors Fallback**: Use SimpleColors only when DDD does not provide the needed color variation. The 12 base colors with 25 shades each (0-24) are available for legacy support.
4. **Audit**: Verify token usage, consistency across breakpoints, accessibility contrast, and performance (minimal custom CSS beyond tokens).
5. **Dark Mode**: Check dark mode compliance when auditing elements. Ensure color combinations maintain proper contrast ratios.

## Implementation Patterns

```css
:host {
  display: block;
  font-family: var(--ddd-font-primary);
  color: var(--ddd-text-primary);
  margin: var(--ddd-spacing-4);
}

.component-header {
  font-size: var(--ddd-font-size-l);
  font-weight: var(--ddd-font-weight-medium);
  margin-bottom: var(--ddd-spacing-3);
}

@media (max-width: 768px) {
  :host {
    margin: var(--ddd-spacing-2);
  }
  .component-header {
    font-size: var(--ddd-font-size-m);
  }
}
```

## SimpleColors Migration

When encountering legacy SimpleColors usage:
1. Identify the SimpleColors variable being used
2. Check if DDD provides an equivalent token
3. Replace with DDD token if available
4. Document remaining SimpleColors dependencies
5. Plan gradual migration to DDD tokens

## References

- For complete DDD token reference: `references/ddd-tokens.md`
- For SimpleColors to DDD mapping: `references/simplecolors-migration.md`
