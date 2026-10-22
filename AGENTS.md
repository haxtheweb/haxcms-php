# AGENTS.md

This file provides comprehensive instructions for AI coding agents working within the HAX ecosystem. HAX (Headless Authoring eXperience) is a comprehensive web development ecosystem that enables rapid creation of accessible, performant web components and static sites. Follow these guidelines to set up, develop, test, and contribute effectively to HAX projects.

## Ecosystem context (maintained in PRAW)

Shared HAX ecosystem guidance (repo map, pillars, rules, scoped rules, skills) lives in one place,
https://github.com/haxtheweb/praw, and is read from there instead of being copied into each repo:

- Always-on rules and ecosystem index: <https://github.com/haxtheweb/praw/blob/main/WARP.md>
- Rule registry, by Rule ID: <https://github.com/haxtheweb/praw/blob/main/RULES.md>
- Scoped rules: [webcomponents](https://github.com/haxtheweb/praw/blob/main/webcomponents/WARP.md), [HAXcms backends](https://github.com/haxtheweb/praw/blob/main/haxcms/WARP.md), [DDD design system](https://github.com/haxtheweb/praw/blob/main/design-system/WARP.md)
- What HAX is, pillars, and the repo map: <https://github.com/haxtheweb/praw/blob/main/what-is-hax.md>
- Workflow skills: <https://github.com/haxtheweb/praw/tree/main/skills>; interface skills: <https://github.com/haxtheweb/create/tree/main/src/skills>
- Machine-readable index: <https://raw.githubusercontent.com/haxtheweb/praw/main/llms.txt>
- Work queue: <https://github.com/haxtheweb/issues> (`gh issue list --repo haxtheweb/issues`)
- CLI options: `hax help`, `hax <command> --help`, or `man hax`

## Development Environment Setup

### Issue Management
Before starting any work, check the unified issue queue:
- All HAX ecosystem issues are tracked at `haxtheweb/issues`
- Use GitHub CLI to check issues: `gh issue list`
- Reference existing issues when making contributions

## Setup Commands

- **Install HAX CLI globally**:
  ```bash
  npm install @haxtheweb/create --global
  ```
- **Install project dependencies** (run from the project root):
  ```bash
  npm install
  ```
- **Start interactive CLI** (for web components or HAXsites):
  ```bash
  hax start
  ```
  - Launches an interactive CLI with ASCII art (via Clack).
- **Start development server** (for HAXsites):
  ```bash
  hax serve
  ```
  - Launches the site in development mode at `http://localhost`.
- **Create a new web component**:
  ```bash
  hax webcomponent my-element --y
  ```
  - Creates a LitElement-based web component with DDD design system and i18n support.
  - In a monorepo, places the component in the correct location and inherits settings.
- **Create a new HAXsite**:
  ```bash
  hax site mysite --y
  ```
  - Generates a HAXcms-based static site with templated files.
- **Update HAX CLI**:
  ```bash
  hax update
  ```
- **Alternative usage** (one-time execution without global install):
  ```bash
  npx @haxtheweb/create
  ```
  or
  ```bash
  npm init @haxtheweb
  ```
- **Windows-specific setup**:
  - If issues occur, set a custom npm cache path:
    ```bash
    npm config set cache C:\tmp\nodejs\npm-cache --global
    ```
  - For PowerShell, check execution policy to allow scripts:
    ```bash
    Get-ExecutionPolicy
    ```
    Adjust if needed using `Set-ExecutionPolicy` (see [Microsoft documentation](https://learn.microsoft.com/en-us/powershell/module/microsoft.powershell.security/set-executionpolicy?view=powershell-7.4)).

## Build System & Testing

The PHP backend lives in `system/backend/php`. The web component build tooling described elsewhere in the ecosystem does not apply to this repo.

### Testing Instructions

- **PHPUnit** (unit and route tests in `system/backend/php/tests`):
  ```bash
  cd system/backend/php && composer install && ./vendor/bin/phpunit
  ```
  - `yarn test` runs the PHPUnit step once dependencies are installed.
  - CI runs the same on PHP 8.3 (`.github/workflows/tests.yml`).
- **E2E UI workflows** (headless browser):
  ```bash
  npm run test:e2e
  ```
  - `npm run test:e2e:update` regenerates screenshot baselines after an intentional UI change. Details: `test/e2e/README.md`.
- API changes must be mirrored in `haxcms-nodejs`. See the `hax-backend-dev` and `hax-testing` skills: <https://github.com/haxtheweb/praw/tree/main/skills>.

## Pull Request Instructions

- **PR Title Format**: `[<project_name>] <Descriptive Title>`
  - Example: `[my-element] Add dynamic property binding`
- **Pre-commit checks**:
  - Run PHPUnit from `system/backend/php` (`composer install`, then `yarn test`) after your last edit and fix every failure. Add or extend a test in `tests/Unit` for any behavior you change.
  - This repo has no `precheck` script. If the change is mirrored in `haxcms-nodejs`, run `npm run precheck` there too: its Prettier check also covers new test files, which ESLint skips.
  - In the PR description, say which checks you ran.
  - Run `npm test` to ensure all tests pass.
  - Run `npm run lint` to verify code style.
  - Run `hax audit` for web components to confirm DDD compliance.
- **Commit Guidelines**:
  - Use clear, descriptive commit messages (e.g., "Add i18n support to my-element").
  - Include relevant changes to tests or documentation.
- **HAXsite PRs**:
  - Test locally with `hax serve` to verify page rendering.
  - Ensure new pages or content updates align with `site.json` and JSON Outline Schema.

## Security Considerations

- **Dependencies**: Keep dependencies updated before running `npm install`
- **Sensitive Data**: Avoid committing API keys or sensitive data to `package.json`, `site.json`, or public files
- **Site Imports**: Validate source URLs when using `--import-site` to prevent malicious content
- **File Permissions**: Ensure scripts have correct execution permissions (`chmod +x script.sh`)
- **Component Security**: Sanitize user inputs in custom components
- **Schema Validation**: Validate against JSON schemas before processing content
- **Library Security**: Only import from trusted, well-maintained JavaScript distributions

## Troubleshooting Common Issues

### Build Failures
- **Missing custom-elements.json**: Run `yarn run build` from component root
- **DDD Audit Failures**: Check `.dddignore` and fix CSS token usage
- **Lerna Issues**: Run `lerna clean && lerna bootstrap`

### Development Environment
- **CLI Not Found**: Ensure `@haxtheweb/create` is globally installed
- **Permission Errors**: Check file permissions and ownership

### Component Issues
- **Theme Not Loading**: Verify HAXCMSLitElement inheritance and build process
- **DDD Tokens Missing**: Import DDD properly and use CSS custom properties
- **HAX Integration**: Ensure `haxProperties` are defined correctly
- **Library Import Errors**: Verify you're importing JavaScript distributions, not TypeScript sources

### Performance Issues
- **Slow Loading**: Check for unnecessary dependencies or large imports
- **Bundle Size**: HAX's unbundled approach should keep individual files small
- **Memory Usage**: Monitor component lifecycle and cleanup

---

*This AGENTS.md file is maintained across all HAX ecosystem repositories to ensure consistent development experience regardless of entry point.*

## Community & Support

- **HAX Community**: Run `hax party` for involvement opportunities
- **Discord**: https://discord.gg/aCGxmRHEJP
- **Issue Reporting**: Use `haxtheweb/issues` or GitHub interface
- **Merlin Integration**: Use "Issue" command in HAX spaces for automated reporting
- **Documentation**: Run `man hax` (Linux/macOS) for comprehensive CLI docs
- **Educational Resources**: Complete documentation at https://haxtheweb.org/
