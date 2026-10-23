# Security Audit Playbook — haxcms-php

Entry point for recurring security audits of this repo. One of five HAX repos audited on a regular cycle (`create`, `haxcms-nodejs`, `webcomponents`, `haxcms-php`, `HAXiam`) — see the project-specific rule in the PRAW rule registry (`haxtheweb/praw` `RULES.md`).

## Read order (do this first)

1. `AGENTS.md` — layout, test harness, PR gates
2. `security_best_practices_report.md` — canonical current state (rolling: the original audit plus dated post-remediation re-audit sections; `[C#]/[H#]/[M#]/[L#]/[N#]` finding IDs carry verification status)
3. `security_jwt_refresh_review.md` — refresh-token flow review
4. `SECURITY.md` — disclosure policy (private advisory at `haxtheweb/issues`, never public issues)

## Scope

- `index.php`, `haxcms-jwt.php`, `install.php`, `system/api.php`, `system/backend/php/lib/**`, and every `.htaccess` (must use the dual-`IfModule` Apache 2.4/2.2 deny pattern)
- Exclude `_sites/` user content, except when auditing import/clone paths
- Core machinery: `SsrfGuard.php` (A/AAAA + private-range checks, redirects disabled), `SanitizeContent.php` (DOM sanitizer, `LIBXML_NONET`), `HAXCMSFile.php` (upload validation, `isPathWithinRoot`), `JWT.php` (`hash_equals`, alg allowlist, `exp`/`nbf`/`iat` enforcement), `HAXCMS.php` (`generateMachineName`, `resolveClientIP` + trustedProxies, `setRefreshTokenCookie`)
- Recurring invariants: path containment via null-byte reject + `..`-segment reject + `realpath` check (never single-pass `str_replace('../','')`); CSPRNG via `random_bytes` (never `mt_rand`); argv-array `proc_open` (no shell strings); zero `unserialize`/`eval`/`assert` in `system/backend/php/lib`
- v0 `system/backend/php/lib/routes/` is legacy: do not modify; findings there are informational
- The security-best-practices skill has no PHP reference doc — measure against OWASP Top 10 / CWE

## Commands

```bash
php system/backend/php/tests/run.php             # full harness (per-round baselines recorded in the report)
cd system/backend/php && composer install && ./vendor/bin/phpunit
npm run test:e2e                                 # UI workflow baseline
php -l <changed file>                            # quick lint
```

## Regression guards (must stay green)

- `php system/backend/php/tests/run.php` — every fix round has kept the full harness passing (251/251 baseline)
- Security Layer suite (within PHPUnit) for auth/JWT/path changes
- Inline verification: `../`, `....//`, and null-byte payloads must stay blocked at read AND write locations
- `FallbackRendererSecurityTest` (N6/N7): the single-site fallback (`system/boilerplate/site/config.php` + `index.php`) must encode every manifest-derived value for its output context and keep `getPageContent` contained to the site directory

## Reporting convention

- `security_best_practices_report.md` is rolling — append dated "Post-Remediation Re-Audit" sections; findings keep their `[C#]/[H#]/[M#]/[L#]/[N#]` IDs and verification status
- Cite `file:line` everywhere
- **Parity:** data-model / system-spec / shared security behavior must be mirrored in `haxcms-nodejs` (AGENTS.md requires API changes to be mirrored; PHP-parity comments mark the pairs)
- Report vulnerabilities via `SECURITY.md` (private advisory), never public issues
