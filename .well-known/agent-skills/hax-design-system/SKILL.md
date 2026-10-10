---
name: hax-design-system
description: >
  Apply the DDD (Design, Develop, Destroy) design system and manage SimpleColors legacy usage.
  Use when styling components, auditing CSS for DDD compliance, migrating from SimpleColors,
  or creating new themes in the HAX ecosystem.
version: 1.0.0
license: Apache-2.0
metadata:
  author: haxtheweb
  tags: [hax, design-system, ddd, simplecolors, css, tokens, accessibility]
---

# HAX Design System

Apply the DDD (Design, Develop, Destroy) design system and manage SimpleColors legacy usage.

## When to Use

- Styling a new or existing web component
- Auditing CSS for DDD compliance
- Migrating SimpleColors usage to DDD tokens
- Creating or updating HAXcms themes
- Ensuring dark mode compliance across components

## How It Works

1. **Import DDD**: Always import `import '@haxtheweb/d-d-d/d-d-d.js'` and extend `DDD` directly (never `DDD(LitElement)`).
2. **Use DDD Tokens**: Apply DDD CSS custom properties for all styling:
   - `--ddd-font-primary`, `--ddd-font-secondary`, `--ddd-font-navigation` for typography
   - `--ddd-font-size-*` (6xs, 5xs, 4xs, 3xs, xxs, xs, s, ms, m, ml, l, xl, xxl, 3xl, 4xl; type1-s/m/l) for font sizes
   - `--ddd-font-weight-*` (light, regular, medium, bold, black) for weights
   - `--ddd-spacing-*` (0-30, 4px steps) for margins, padding, gaps
   - `--ddd-radius-*` (0, xs, sm, md, lg, xl, rounded, circle) for border radius
   - `--ddd-theme-default-*`, `--ddd-primary-*` (0-25) and `--ddd-accent-*` (0-14) for colors; components read `--ddd-theme-primary` / `--ddd-theme-accent`
   - `--ddd-border-*` (xs-lg) for borders, `--ddd-boxShadow-*` for elevation, `--ddd-icon-*` for icon sizes
   - `--ddd-breakpoint-*` for responsive breakpoints (write the px literally in `@media`)
3. **SimpleColors Fallback**: Use SimpleColors only when DDD does not provide the needed color variation. 19 hues with 12 shades each (1-12); `default-theme` variables flip in dark mode, `fixed-theme` variables do not.
4. **Audit**: Verify token usage, consistency across breakpoints, accessibility contrast, and performance (minimal custom CSS beyond tokens).
5. **Dark Mode**: Check dark mode compliance when auditing elements. Ensure color combinations maintain proper contrast ratios.

## Implementation Patterns

```css
:host {
  display: block;
  font-family: var(--ddd-font-primary);
  color: var(--ddd-theme-default-coalyGray);
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
- Both are generated from the webcomponents source by `scripts/generate-ddd-references.js`; re-run it after DDD or SimpleColors change rather than editing them by hand.
