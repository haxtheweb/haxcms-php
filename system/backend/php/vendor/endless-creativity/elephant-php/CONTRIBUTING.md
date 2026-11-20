# Contributing

Thanks for considering a contribution. The project follows a few simple
conventions; please respect them and the review will be smooth.

## Quick start

```bash
git clone https://github.com/endless-creativity/elephant-php.git
cd elephant-php
composer install
composer test     # Pest
composer stan     # PHPStan level 8
composer format   # Laravel Pint
```

CI runs the same three checks on PHP 8.2 / 8.3 / 8.4 / 8.5. A PR that
fails any of them won't be merged.

## Conventions

- **Test-driven**: a behavioural change without a Pest test that fails
  before and passes after will be asked back. Use one of the existing
  fixtures under `tests/fixtures/` when possible, or add a new one.
- **`declare(strict_types=1)`** in every PHP file.
- **`final readonly class`** for value objects; named arguments for
  constructors with three+ parameters.
- **Mammoth fidelity first**. The library is a faithful port of
  [mammoth.js](https://github.com/mwilliamson/mammoth.js); when in
  doubt about an edge case, look at how mammoth handles it in
  `./reference/mammoth.js/` (gitignored — clone it locally) and
  replicate. Each ported file carries a `// Ported from mammoth.js: …`
  header comment for traceability. Deviations from mammoth must be
  intentional, justified in the commit message, and documented in
  `ROADMAP.md`.
- **Conventional commits** (`feat:`, `fix:`, `chore:`, `docs:`,
  `test:`, `refactor:`).

## Reporting bugs

Please include:

1. The smallest `.docx` that reproduces the issue (or the raw XML
   fragment if you'd rather not share the document).
2. The expected vs actual HTML / Markdown output.
3. The conversion warnings from `$result->messages`.

## Scope of v0.x

The project is pre-1.0. The public API (`Converter`, `Result`,
`Message`, `MessageType`, `StyleMap`, `ImageHandler`) will become
stable at 1.0; the internal namespaces (`Reader\*`, `Document\*`,
`Html\*`, `Style\*` non-API surface) are implementation details and
may move. See `ROADMAP.md` for what's still missing vs mammoth.
