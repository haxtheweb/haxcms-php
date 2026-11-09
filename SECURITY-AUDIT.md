# Security Audit Playbook — haxcms-php

Entry point for recurring security audits of this repo. One of five HAX repos audited on a regular cycle (`create`, `haxcms-nodejs`, `webcomponents`, `haxcms-php`, `HAXiam`) — see the project-specific rule in the PRAW rule registry (`haxtheweb/praw` `RULES.md`).

## Read order (do this first)

1. `AGENTS.md` — layout, test harness, PR gates
2. "Security invariants" below: the rules this code keeps. They are the committed record; there is no checked-in findings report (the former `security_best_practices_report.md` / `security_jwt_refresh_review.md` are gone on purpose). Inline `// Security (...)` comments mark where a rule is enforced, and `system/backend/php/tests/Unit/SecurityAuditFixesTest.php` plus the suites under "Regression guards" keep them true. Start a new round by checking each invariant against the code, then grepping `Security (` under `system/backend/php/lib` for the enforcement points.
3. Refresh-token flow: `HAXCMS.php` (`getRefreshToken`, `validateRefreshToken`, `rotateRefreshTokenAndCookie`, `revokeRefreshSession`, `isSameOriginRequest` / `isSameOriginGetRequest`) with `tests/Unit/HAXCMSRefreshTokenTest.php` and `tests/Unit/HAXCMSSessionHardeningTest.php` as the executable review.
4. `SECURITY.md` — disclosure policy (private advisory at `haxtheweb/issues`, never public issues)

## Scope

- `index.php`, `haxcms-jwt.php`, `install.php`, `system/api.php`, `system/backend/php/lib/**`, and every `.htaccess` (must use the dual-`IfModule` Apache 2.4/2.2 deny pattern)
- Exclude `_sites/` user content, except when auditing import/clone paths
- Core machinery: `SsrfGuard.php` (A/AAAA + private-range checks, redirects disabled), `SanitizeContent.php` (DOM sanitizer, `LIBXML_NONET`), `HAXCMSFile.php` (upload validation, `isPathWithinRoot`), `JWT.php` (`hash_equals`, alg allowlist, `exp`/`nbf`/`iat` enforcement), `HAXCMS.php` (`generateMachineName`, `resolveClientIP` + trustedProxies, `setRefreshTokenCookie`)
- v0 `system/backend/php/lib/routes/` is legacy: do not modify; findings there are informational
- The security-best-practices skill has no PHP reference doc — measure against OWASP Top 10 / CWE

## Security invariants

The rules the code keeps. A fix that introduces a new class of rule adds a line here in the same PR; a change that weakens one needs a matching test change.

- **Egress:** every remote fetch (importers, `stageRemoteFile`, `LinkChecker`, siteFiles download, md/html/yaml URL conversion) goes through `SsrfGuard` (A/AAAA + private-range checks, `CURLOPT_RESOLVE` pinning, manual redirect walking, byte cap); no raw `curl`/`file_get_contents` on a user URL
- **Stored content:** every content write path (saveNode, createNode, saveOutline, siteSearch replace, revision restore, importers, export round-trip) runs `SanitizeContent` (DOM-based, `LIBXML_NONET`, script/style/svg/srcdoc/`on*` removed, scheme allowlist); the default iframe sandbox never grants `allow-same-origin` unless the author set it
- **Path containment:** null-byte reject + `..`-segment reject + `realpath` prefix check at read AND write (never single-pass `str_replace('../','')`); page `location` is server-derived
- **Auth:** JWT alg allowlist with `hash_equals`; refresh tokens rotate by family/jti with tombstones and revoke on reuse; `HttpOnly` cookie; request tokens are HMAC under a domain-separated key and validated against the request's own identity (`getSiteTokenUserName()` / `getRequestTokenUserName()`), never the process-global user
- **Route policy:** security requirements come from the OpenAPI spec and fail closed to `authenticated`; every mutation route declares bearer + user/site token; anonymous callers never get rendered binaries (pdf/docx/epub)
- **Install:** every entry point refuses to serve while placeholder secrets remain; the install token lives under `_config/`; passwords are `password_hash`ed and secrets are `random_bytes`, written 0600 (shell and PHP installers alike)
- **Randomness:** `random_bytes` only (never `mt_rand`); download/export artifacts get unguessable names and a TTL, and never sit at a predictable public path
- **Process exec:** argv-array `proc_open` with `isSafeArg` (no shell strings, no leading-dash values); zero `unserialize`/`eval`/`assert` under `system/backend/php/lib`
- **Uploads:** extension allowlist cross-checked against libmagic, executable-extension stripping, no SVG on any write path, `files/` and `custom/`/`theme/` markup served as attachments
- **Headers:** `X-Content-Type-Options: nosniff` everywhere; `frame-ancestors` on dashboard and site pages; `Referrer-Policy` on site pages; `X-Forwarded-*` only trusted via `resolveTrustedHost()`/`resolveTrustedProtocol()`
- **Rate limiting:** login and Basic auth throttle per IP::username and per username, fail closed when the cache is unavailable, and log when they cannot
- **Deployment:** `_config/`, `_archived/`, `system/backend/php` and `.git` denied at `.htaccess` and vhost/nginx level with the dual-`IfModule` pattern; Docker runs as `www-data` with `php.ini-production`
- **Parity:** every one of these holds identically in `haxcms-nodejs`, and both `system-spec.yaml` / `site-spec.yaml` stay byte-identical

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

- Findings and their severities live in the audit PR description or the private advisory, never in committed files; the committed record is the rule, not the finding
- A fix lands with an inline `// Security (<invariant>)` comment naming the rule it enforces (e.g. `SEC-SSRF`, `SEC-SANITIZE`, `SEC-SESSION`), a regression test in `tests/Unit` (extend `SecurityAuditFixesTest.php` or add a focused file), and, if it is a new class of rule, a line under "Security invariants" in the same PR
- Cite `file:line` everywhere
- **Parity:** data-model / system-spec / shared security behavior must be mirrored in `haxcms-nodejs` (AGENTS.md requires API changes to be mirrored; PHP-parity comments mark the pairs)
- Report vulnerabilities via `SECURITY.md` (private advisory), never public issues
