# E2E UI Tests for haxcms-php (DDEV-based)

Automated end-to-end tests that drive the real app-hax dashboard served by the
DDEV project (`http://haxcms.ddev.site`) through a headless browser
(puppeteer-core + system Chrome), validating that common user tasks still
succeed after code changes. This is the PHP-backend counterpart of the
`haxcms-nodejs` `test:e2e` suite — the browser, a11y, visual, and selector
helpers are shared verbatim; only the runtime harness differs (DDEV instead of
an in-process temp-dir server). See [PHP-specific configuration](#php-specific-configuration--base-path-differences)
for the key differences from the NodeJS suite.

Tracks: haxtheweb/issues#2939

## Prerequisites

- **DDEV** installed and Docker running. The harness runs `ddev start`
  (idempotent) so the project just needs to be startable. The post-start hook
  (`scripts/haxtheweb.sh admin admin`) seeds the `admin` / `admin` credentials
  the tests log in with.
- **Chrome/Chromium** installed on the host. The harness auto-detects
  `/usr/bin/google-chrome` (and common alternatives). Override with
  `PUPPETEER_EXECUTABLE_PATH=/path/to/chrome` for non-standard installs.
- **Node >= 18.20.3**.
- devDependencies (`puppeteer-core`, `sharp`, `axe-core`, `pixelmatch`, `pngjs`,
  `fs-extra`, `axios`) — run `npm install` (or `yarn`) at the repo root.

The tests run on the **host** (puppeteer-core drives the host's Chrome against
the DDEV-served URL); the DDEV container only needs to be running and seeded.

## Running the tests

```bash
# from the haxcms-php repo root
npm run test:e2e
```

This runs all `test/e2e/*.e2e.test.cjs` files via Node's built-in test runner,
serialized (`--test-concurrency=1`) because the tests share a single DDEV
instance and a fixed site name (`HAXSITEAUTOMATEDTESTING`).

### Individual test files

```bash
node --test test/e2e/login.e2e.test.cjs
node --test test/e2e/create-site.e2e.test.cjs
node --test test/e2e/archive-site.e2e.test.cjs
node --test test/e2e/edit-content.e2e.test.cjs
node --test test/e2e/export-site.e2e.test.cjs
node --test test/e2e/page-management.e2e.test.cjs
```

### Updating visual baselines

Visual diffs **warn but do not fail** the test run. When a diff is intentional,
regenerate baselines:

```bash
npm run test:e2e:update
```

Baselines are stored under `test/e2e/__screenshots__/` as `*.png` files.
Runtime artifacts (`*.current.png`, `*.diff.png`) are written alongside for
manual inspection and are gitignored.

## What the tests cover

| Test file | Task | Key assertions |
|---|---|---|
| `login.e2e.test.cjs` | Login via two-step modal | POST `/system/api/v1/session/login` -> 200 + jwt + refresh cookie; dashboard renders; a11y scan of login form; visual baselines |
| `invalid-login.e2e.test.cjs` | Bad credentials rejected | (skipped — see in-file note on two-step username validation); negative path documented |
| `create-site.e2e.test.cjs` | Create `HAXSITEAUTOMATEDTESTING` | POST `/system/api/v1/sites` -> 200 + `data.metadata.site.name` + `link`; site exists on disk + in list API; a11y + visual baselines |
| `archive-site.e2e.test.cjs` | Archive `HAXSITEAUTOMATEDTESTING` | POST `/sites/:siteName/archive` -> 200 + `data.name` + `detail === 'Site archived'`; site card removed; site dir moved to `_archived/`; a11y + visual baselines |
| `edit-content.e2e.test.cjs` | Edit & save content in HAX editor | PATCH `/x/api/v1/content/:idOrSlug` -> 200; typed content present in the page HTML file on disk; a11y + visual baseline |
| `export-site.e2e.test.cjs` | Export/download site as zip | POST `/sites/:siteName/download` -> 200 + `data.link` ends `.zip` + `data.name`; zip exists at `_published/haxsiteautomatedtesting.zip` with PK magic bytes; a11y + visual baselines |
| `page-management.e2e.test.cjs` | Add + delete a page | POST `/x/api/v1/items` -> 200 + `data.id` + `data.title`; DELETE `/x/api/v1/items/:id` -> 200; page removed from site.json manifest + items list; a11y + visual baselines |

All site operations target the fixed name `HAXSITEAUTOMATEDTESTING`. The
harness removes any leftover site (`_sites/`, `_archived/`, `_published/*.zip`)
at the start of each run so the suite is idempotent.

## PHP-specific configuration & base path differences

The app-hax dashboard/editor is served locally through the system's magic
script (wc-registry.json dynamic hydration), not a remote CDN — CDN serving is
opt-in and not in use here. Because the frontend is backend-agnostic, most of
the NodeJS e2e helpers are reused verbatim. The differences below are
PHP-backend-specific and were the root cause of the two site-editor-flow
failures (edit-content, page-management) during initial porting.

### `/sites/` vs `/_sites/` — the editor base path

This is the most important difference and the one most likely to bite when
porting tests between backends.

- **PHP** dynamically selects `sitesDirectory` at runtime: if the `/sites`
  symlink exists it picks `'sites'` (`system/backend/php/lib/HAXCMS.php:166-167`),
  otherwise `'_sites'`. The editor page's `<base href>` is therefore
  `/sites/<name>/` (emitted by `HAXCMSSite::getBaseTag()` → `getPWABaseTagPath()`).
- **NodeJS** hardcodes `sitesDirectory = '_sites'` (`haxcms-nodejs/src/lib/HAXCMS.js:3043`),
  so its editor base tag is `/_sites/<name>/`.

The site editor's client-side router resolves paths against the `<base href>`.
If a test navigates to `/_sites/<name>/` on the PHP backend, the URL
**mismatches the base tag** and the router fails with `AbortError: Transition
was skipped` — the editor shell renders but the active page never hydrates
(`#editbutton` stays disabled) and global event listeners never wire up.

The ported tests therefore use **two distinct directory names**:
- `SITE_URL_DIR = 'sites'` for browser/API URLs (`/{baseUrl}/sites/{name}/`,
  `/{baseUrl}/sites/{name}/x/api/v1/items`) — matches the PHP base tag.
- `SITES_DIR = '_sites'` for filesystem paths (`path.join(runtimeRoot, '_sites', name)`)
  — matches the on-disk directory DDEV bind-mounts.

If the `/sites` symlink is absent (non-DDEV deploys where PHP falls back to
`_sites`), set `SITE_URL_DIR = '_sites'` in `edit-content.e2e.test.cjs` and
`page-management.e2e.test.cjs`, or remove the symlink so PHP and the tests agree.

### Base URL is HTTP, not HTTPS

The harness coerces DDEV's HTTPS primary URL to `http://haxcms.ddev.site`
(`helpers/harness.cjs` `resolveBaseUrl`). Two reasons:
1. The dev refresh cookie is non-Secure — `setRefreshTokenCookie` drives the
   `Secure` flag from `isProductionRuntime()`, which is false without
   `NODE_ENV=production` (`HAXCMS.php:2263-2273`). So the `haxcms_refresh_token`
   cookie assertion works over HTTP.
2. HTTP avoids the cert path entirely. Even with DDEV's mkcert root CA
   installed in the system + Chrome trust store, Chrome's HTTP/HTTPS
   connection coalescing on the same DDEV host intermittently triggers
   `net::ERR_CERT_VERIFIER_CHANGED` on opportunistic HTTPS subresource loads
   under SPA load, blocking the dashboard.

No cert flags are passed to Chrome (`--ignore-certificate-errors` and
`ignoreHTTPSErrors: true` were both tried and both *cause*
`ERR_CERT_VERIFIER_CHANGED` by swapping Chrome's cert verifier mid-session).
`--disable-dev-shm-usage` is included to avoid `/dev/shm` pressure when
running 7 heavy SPA tests serially.

### Site token for mutation endpoints

Site-scoped mutation endpoints (`DELETE`/`PATCH /x/api/v1/items/*`,
`PATCH /x/api/v1/content/*`) require an `X-HAXCMS-Site-Token` header, validated
per-site via `HAXCMS::validateSiteToken` → `getSiteTokenForSiteName`, which is
`getRequestToken(user:sitename)` — a per-user, per-site hash that cannot be
computed client-side.

`page-management.e2e.test.cjs` captures this token by listening to the page's
outgoing requests: the editor's own `POST /x/api/v1/items` (triggered by the
`haxcms-create-node` global event) carries the header, so the test intercepts
`page.on('request')` for `/x/api/` and reuses the captured token for the direct
axios `DELETE`.

### Editor global-event caveat (page delete)

The site editor listens for `haxcms-create-node` / `haxcms-delete-node` global
events and translates them into site API calls. On the PHP backend:
- `haxcms-create-node` works on the initial editor load (the listener is
  wired and the editor's API client mints the site token).
- `haxcms-delete-node` does **not** reliably fire the `DELETE` call — the
  handler needs the item present in the editor's local outline state, which
  isn't refreshed after the event-based create, and the listener does not
  re-wire after a `page.reload()` (the editor router doesn't fully
  re-initialize on PHP).

`page-management` therefore uses a direct axios `DELETE` (with the captured
site token) instead of the global event. The `haxcms-create-node` event still
verifies editor→API integration for the create path.

## How it works

1. **DDEV harness** (`helpers/harness.cjs`) — runs `ddev start`, resolves the
   base URL from `ddev describe -j` (coerced to HTTP; default
   `http://haxcms.ddev.site`), waits for the system API, smoke-logins
   `admin`/`admin` for a ready JWT, and best-effort fetches `connection-settings`
   for the `userToken`/`userTokenHeader`. `runtime.runtimeRoot` is the
   haxcms-php repo root (DDEV bind-mounts it, so host-side filesystem
   assertions resolve to the same files the PHP backend operates on). Teardown
   leaves DDEV running for the user.
2. **Browser driving** (`helpers/browser.cjs`) — launches headless Chrome via
   `puppeteer-core` + the system Chrome binary. No cert flags (see
   [PHP-specific configuration](#php-specific-configuration--base-path-differences));
   `--disable-dev-shm-usage` for shm pressure under serial SPA load. Fixed
   1280x800 viewport. Response collector captures every XHR/fetch to
   `/system/api/v1/*` and `/x/api/`. A `requestfailed` logger is always on;
   set `HAXCMS_E2E_DEBUG_HTTP=1` to log every 4xx/5xx response URL.
3. **Accessibility** (`helpers/axe.cjs`) — injects `axe-core` and runs WCAG
   2.1 AA rules scoped to the task-relevant UI region.
4. **Visual regression** (`helpers/visual.cjs`) — captures screenshots and
   diffs against committed baselines with `pixelmatch`/`sharp`. Diffs **warn
   but never fail**.
5. **Selectors** (`helpers/selectors.cjs`) — centralised app-hax selector map
   (shadow-DOM chains) + `deepQuery`/`deepQueryAll`.

## Known limitations

- `invalid-login.e2e.test.cjs` is largely skipped — the two-step login
  validates the username locally before showing the password step, so the
  login API is never called with bad credentials. The positive login path is
  fully covered in `login.e2e.test.cjs`.
- Full-suite (`npm run test:e2e`) runs can be flaky under Chrome resource
  pressure (later tests' SPA loads slow down). Individual test files are
  reliable. Run a single file with `node --test test/e2e/<file>.cjs` for
  deterministic verification.

## Environment overrides

- `HAXCMS_E2E_BASE_URL` — override the base URL (default `http://haxcms.ddev.site`; the harness also coerces DDEV's HTTPS primary URL to HTTP).
- `HAXCMS_E2E_USERNAME` / `HAXCMS_E2E_PASSWORD` — override the login creds
  (default `admin` / `admin`).
- `PUPPETEER_EXECUTABLE_PATH` — override the Chrome binary path.
- `HAXCMS_E2E_UPDATE_SCREENSHOTS=1` — regenerate visual baselines.
- `HAXCMS_E2E_DEBUG_HTTP=1` — log every 4xx/5xx response URL seen by the browser.

## Debugging

### Headed mode (watch the browser)

Set `headless: false` in the `launchBrowser()` call, or pass it from the test:

```js
const browser = await launchBrowser({ headless: false })
```

### Console output

The tests emit `[e2e]`, `[visual]`, `[a11y]`, and `[diag]` prefixed warnings.
Pipe through `grep` to filter:

```bash
node --test test/e2e/create-site.e2e.test.cjs 2>&1 | grep '\[e2e\]'
```

## File layout

```
test/e2e/
  helpers/
    harness.cjs       # DDEV bootstrap (ddev start, HTTP base URL, smoke-login, cleanup)
    browser.cjs       # puppeteer launch + response collector (no cert flags; requestfailed + opt-in 4xx logger)
    axe.cjs           # axe-core inject + runA11y
    visual.cjs        # screenshot capture + baseline diff (WARN not fail)
    selectors.cjs     # centralised app-hax selector map (shadow-DOM chains)
    index.cjs         # re-exports all helpers
  login.e2e.test.cjs
  invalid-login.e2e.test.cjs
  create-site.e2e.test.cjs
  archive-site.e2e.test.cjs
  edit-content.e2e.test.cjs
  export-site.e2e.test.cjs
  page-management.e2e.test.cjs
  __screenshots__/    # committed baselines (*.png) + gitignored runtime artifacts
  README.md           # this file
```
