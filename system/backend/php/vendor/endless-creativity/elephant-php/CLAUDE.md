# elephant-php

PHP port of mammoth.js for converting .docx into semantic HTML and Markdown.

## Audience: public server-side library
This repository ships a public library on Packagist used in
international server-side contexts. Anything committed lands in front
of external developers in any language, and once tagged it ends up on
Packagist with no clean way to take it back. So:

- **Everything committed must be in English**: code, comments, commit
  messages, CHANGELOG, ROADMAP, README, CONTRIBUTING, tag messages, PR
  descriptions, even this CLAUDE.md. Italian stays exclusively in the
  chat conversation. Before committing an edited file, scan for
  Italian residue and remove it.
- **No internal-management information in public files**:
  - phrasing like "in our deployments", "in this session", "we just
    shipped", "our team uses..." belongs to internal docs, not a
    public library;
  - numeric snapshots that age fast ("66 commits", "400 tests
    passing") create maintenance debt and add no value to the
    external consumer;
  - references to internal workflow, people, or process decisions do
    not belong in CHANGELOG / ROADMAP / README;
  - commit messages can be more discursive but still avoid "I", "we
    just", "this session" — describe the change in impersonal third
    person.
- **Public-library best practices** applied by default:
  - strict SemVer; even pre-1.0 a minor bump signals a feature, a
    patch signals a fix, and any change to output or public signature
    is announced in the CHANGELOG;
  - CHANGELOG in Keep a Changelog format, one entry per release with
    Added / Changed / Fixed / Removed / Deprecated / Security
    sections;
  - security-by-default: attackable input (XML, ZIP, URLs, paths) is
    treated as hostile and the choice is documented explicitly in
    release notes;
  - clear attribution for ported code (`// Ported from mammoth.js:
    lib/path/to/file.js` header) and a visible licence in the README;
  - public API documented in the README with runnable examples;
    internal namespaces stay marked as implementation detail.

When proposing changes to public files, flag any violation of these
rules in pre-existing content and suggest the cleanup.

## Stack
- PHP 8.2+
- Pest for tests
- PHPStan level 8
- Laravel Pint for style (PSR-12)

## Always-on rules
- `declare(strict_types=1);` in every PHP file
- Named arguments for calls with more than 2 parameters
- Readonly classes and properties wherever possible
- PHP 8.1+ enums instead of constants where appropriate
- Never write code without a test that motivates it first
- Every PHP file ported from mammoth must carry a header:
  `// Ported from mammoth.js: lib/path/to/file.js`

## Useful commands
- Tests: `./vendor/bin/pest`
- Static analysis: `./vendor/bin/phpstan analyse`
- Format: `./vendor/bin/pint`

## Reference
The original mammoth.js source lives in `./reference/mammoth.js/`.
Consult it for every edge-case decision rather than reinventing.

## Release flow
Mandatory order when publishing a new version:
1. Update `CHANGELOG.md` (new section `## [x.y.z] — YYYY-MM-DD`)
2. Commit the changelog
3. `git tag -a vX.Y.Z -m "..."`
4. `git push origin main && git push origin vX.Y.Z`

Never tag before writing the entry: the tag immediately reaches
Packagist and moving it after the fact is awkward.
