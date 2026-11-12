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
- **Rate limiting:** login and Basic auth throttle per IP::username and per username; one-time sign-in link redemption throttles per IP::link selector; fail closed when the cache is unavailable, and log when they cannot
- **Magic links:** one-time sign-in links (`session/magic-link`) are minted only by something with filesystem access to `_config` (the CLI or a server-side script), never over HTTP; a token is `selector.verifier` and only the verifier's SHA-256 is stored (`_config/settings/magicLinks.json`, 0600, every read-modify-write under an exclusive lock, files created as root handed to the settings directory's owner), compared constant-time, single use, short-lived (default 10 minutes, at most 24 hours), and a new link revokes the account's older ones; GET only renders a confirmation page and never consumes; POST passes `isSameOriginRequest`, is throttled per IP::link selector, and on success sets the refresh cookie through `setRefreshTokenCookie` exactly like password login; every failure is the same generic answer and never clears an existing session; on HAXiam deployments the feature is off unless the operator sets `HAXCMS_MAGIC_LINK_ENABLED` (tenant config can't turn it on or change its lifetime), `HAXCMS_MAGIC_LINK_DISABLED` turns it off everywhere, and when it's off the route answers 404 and minting refuses (`MagicLinkService.php`, `systemRoutes/v1/magicLink.php`, `scripts/magic-link.php`)
- **Deployment:** `_config/`, `_archived/`, `system/backend/php` and `.git` denied at `.htaccess` and vhost/nginx level with the dual-`IfModule` pattern; Docker runs as `www-data` with `php.ini-production`
- **Parity:** every one of these holds identically in `haxcms-nodejs`, and both `system-spec.yaml` / `site-spec.yaml` stay byte-identical

### Tag vocabulary

Inline comments name the invariant they enforce as `// Security (<TAG>): <rule>` (several tags: `Security (SEC-SSRF, SEC-PATH)`, in the order below). These are the only tags; `haxcms-nodejs` uses the same list so mirrored code carries mirrored tags.

| Tag | Covers | Invariant above |
| --- | --- | --- |
| `SEC-SESSION` | JWT, refresh tokens/families, request/user/site tokens, cookies, CSRF / same-origin checks, login, identity resolution | Auth |
| `SEC-ROUTE` | spec-driven route policy, object-level authz (`userCanAccessSite`), anonymous gates, fail-closed defaults | Route policy |
| `SEC-SANITIZE` | `SanitizeContent`, iframe sandbox normalisation, page metadata, output escaping in rendered HTML | Stored content |
| `SEC-SSRF` | any outbound fetch of a user/remote URL (`SsrfGuard`, DNS pinning, redirect checks, byte caps) | Egress |
| `SEC-PATH` | path containment, traversal, filename/slug/site-name validation, symlink checks | Path containment |
| `SEC-UPLOAD` | upload validation, extension/MIME checks, force-download of markup files | Uploads |
| `SEC-EXEC` | `proc_open`/Git argv, no shell strings, no `eval`/`unserialize`/`assert` on input | Process exec |
| `SEC-HEADERS` | CSP / `frame-ancestors`, CORS, `Referrer-Policy`, trusted Host / `X-Forwarded-*` / proxy handling | Headers |
| `SEC-RATELIMIT` | login / Basic-auth throttles, request and file-op limiters | Rate limiting |
| `SEC-INSTALL` | installer, secret/key generation, default credentials, install token, file modes on secrets | Install |
| `SEC-EXPORT` | download/export artifacts (random names, TTL sweep, `_published/`), CSPRNG identifiers | Randomness |
| `SEC-DOS` | size caps, ReDoS-safe regexes, decompression / YAML alias amplification limits | Egress, Uploads (caps) |
| `SEC-MAGICLINK` | one-time sign-in links: minting, storage, redemption, HAXiam gating | Magic links |
| `SEC-DEPLOY` | `.htaccess` / nginx denies, Docker image, deployment config, CLI-only scripts | Deployment |

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
- `FallbackRendererSecurityTest` (`SEC-SANITIZE`, `SEC-PATH`): the single-site fallback (`system/boilerplate/site/config.php` + `index.php`) must encode every manifest-derived value for its output context and keep `getPageContent` contained to the site directory

## Reporting convention

- Findings and their severities live in the audit PR description or the private advisory, never in committed files; the committed record is the rule, not the finding
- A fix lands with an inline `// Security (<TAG>)` comment naming the rule it enforces, using only the tags in "Tag vocabulary" above (never a finding ID, severity, audit round or PR number; test names describe the behaviour, not the finding), a regression test in `tests/Unit` (extend `SecurityAuditFixesTest.php` or add a focused file), and, if it is a new class of rule, a line under "Security invariants" in the same PR
- Cite `file:line` everywhere
- **Parity:** data-model / system-spec / shared security behavior must be mirrored in `haxcms-nodejs` (AGENTS.md requires API changes to be mirrored; PHP-parity comments mark the pairs)
- Report vulnerabilities via `SECURITY.md` (private advisory), never public issues
