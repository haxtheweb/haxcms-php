'use strict'

// Shared E2E runtime harness for haxcms-php (DDEV-based).
//
// Replaces the haxcms-nodejs in-process server bootstrap. Instead of booting
// an isolated server in a temp dir, this harness drives the real DDEV project
// (.ddev/config.yaml => https://haxcms.ddev.site, seeded with admin/admin by
// the post-start hook scripts/haxtheweb.sh). It:
//   1. ensures DDEV is running (ddev start is idempotent),
//   2. resolves the base URL (ddev describe -j, env override, or default),
//   3. waits for the system API to be healthy,
//   4. smoke-logs in via POST /system/api/v1/session/login,
//   5. best-effort fetches connection-settings for userToken/userTokenHeader,
//   6. removes any leftover HAXSITEAUTOMATEDTESTING site (clean slate),
//   7. returns a runtime handle matching the nodejs harness contract:
//      { baseUrl, jwt, userToken, userTokenHeader, credentials, runtimeRoot }.
//
// runtimeRoot is the haxcms-php repo root. DDEV bind-mounts the repo into the
// container at /var/www/html, so host-side filesystem assertions (path.join
// runtimeRoot, '_sites', name) resolve to the same files the PHP backend
// operates on. teardown is a no-op on DDEV (the project is left running for
// the user); the browser is closed by the tests themselves.
//
// Constraints: CommonJS (.cjs), require(), globalThis (not window), NO optional
// chaining (explicit && guards), node:test compatible, single quotes.

const fs = require('fs-extra')
const path = require('path')
const axios = require('axios')
const https = require('https')
const { execFileSync } = require('child_process')
const vm = require('node:vm')

const REPO_ROOT = path.resolve(__dirname, '..', '..', '..')
const E2E_USER_NAME = process.env.HAXCMS_E2E_USERNAME || 'admin'
const E2E_USER_PASSWORD = process.env.HAXCMS_E2E_PASSWORD || 'admin'
const DEFAULT_BASE_URL = 'http://haxcms.ddev.site'
const FIXED_SITE_NAME_LOWER = 'haxsiteautomatedtesting'
const SITES_DIR = '_sites'
const ARCHIVED_DIR = '_archived'
const PUBLISHED_DIR = '_published'

// axios instance that ignores DDEV's self-signed TLS certificate (the dashboard
// is served over https://haxcms.ddev.site with a router-managed cert).
const insecureAxios = axios.create({
  httpsAgent: new https.Agent({ rejectUnauthorized: false }),
  validateStatus: () => true,
})

function resolveBaseUrl() {
  if (process.env.HAXCMS_E2E_BASE_URL) {
    return String(process.env.HAXCMS_E2E_BASE_URL).replace(/\/$/, '')
  }
  try {
    const out = execFileSync('ddev', ['describe', '-j'], {
      cwd: REPO_ROOT,
      stdio: ['ignore', 'pipe', 'ignore'],
      encoding: 'utf8',
    })
    const parsed = JSON.parse(String(out || '{}'))
    const primary =
      (parsed && typeof parsed.primary_url === 'string' && parsed.primary_url) ||
      (parsed && parsed.raw && typeof parsed.raw.primary_url === 'string' && parsed.raw.primary_url) ||
      null
    if (primary && primary.indexOf('http') === 0) {
      // Prefer HTTP: DDEV serves both http and https. HTTP avoids the cert
      // path entirely (no HTTPS = no cert verifier = no
      // net::ERR_CERT_VERIFIER_CHANGED from puppeteer's CDP connection).
      // The dev refresh cookie is non-Secure (isProductionRuntime() is false
      // without NODE_ENV=production), so it works over HTTP.
      return primary.replace(/^https:\/\//, 'http://').replace(/\/$/, '')
    }
  } catch (e) {
    // fall through to default
  }
  return DEFAULT_BASE_URL
}

function ensureDdevRunning() {
  try {
    execFileSync('ddev', ['start'], {
      cwd: REPO_ROOT,
      stdio: ['ignore', 'pipe', 'pipe'],
      encoding: 'utf8',
      timeout: 180000,
    })
  } catch (e) {
    const msg = e && e.stderr ? String(e.stderr) : (e && e.message ? e.message : String(e))
    throw new Error(
      'ddev start failed (is Docker running?): ' + String(msg).slice(0, 300),
    )
  }
}

async function waitForHealthy(baseUrl, timeoutMs) {
  const timeout = timeoutMs || 60000
  const start = Date.now()
  let lastErr = null
  while (Date.now() - start < timeout) {
    try {
      const resp = await insecureAxios({
        method: 'GET',
        url: baseUrl + '/system/api/v1/system/version',
        headers: { accept: 'application/json' },
        responseType: 'text',
        transformResponse: [(d) => d],
        timeout: 8000,
      })
      // 200 or 401 both mean the API is up (401 = enforcing auth).
      if (resp.status === 200 || resp.status === 401) {
        return
      }
      lastErr = new Error('unexpected status ' + resp.status)
    } catch (e) {
      lastErr = e
    }
    await new Promise((r) => setTimeout(r, 1500))
  }
  throw new Error(
    'DDEV system API did not become healthy within ' +
      timeout +
      'ms. Last error: ' +
      (lastErr && lastErr.message ? lastErr.message : String(lastErr)),
  )
}

async function loginForJwt(baseUrl, username, password) {
  const resp = await insecureAxios({
    method: 'POST',
    url: baseUrl + '/system/api/v1/session/login',
    headers: {
      accept: 'application/json',
      'content-type': 'application/json',
    },
    data: { username: username, password: password },
    responseType: 'text',
    transformResponse: [(d) => d],
    timeout: 15000,
  })
  if (resp.status !== 200) {
    throw new Error(
      'E2E login failed: status ' +
        resp.status +
        ', body: ' +
        String(resp.data || '').slice(0, 200),
    )
  }
  let body = null
  try {
    body = JSON.parse(String(resp.data || ''))
  } catch (e) {
    throw new Error('E2E login returned non-JSON body: ' + resp.data)
  }
  if (!body || typeof body.jwt !== 'string' || body.jwt === '') {
    throw new Error('E2E login response missing jwt: ' + resp.data)
  }
  return body.jwt
}

// connection-settings returns a `window.appSettings = {...}` JS string (same
// shape as nodejs), so we eval it in a sandbox to extract appSettings.
function parseConnectionSettingsScript(scriptSource) {
  const sandbox = { window: {} }
  vm.runInNewContext(String(scriptSource || ''), sandbox, { timeout: 1000 })
  if (
    !sandbox.window ||
    !sandbox.window.appSettings ||
    typeof sandbox.window.appSettings !== 'object'
  ) {
    throw new Error('Unable to parse appSettings from connection-settings response')
  }
  return sandbox.window.appSettings
}

async function requestConnectionSettings(baseUrl, jwt) {
  const headers = { accept: 'application/javascript' }
  if (jwt) {
    headers.Authorization = 'Bearer ' + jwt
  }
  const resp = await insecureAxios({
    method: 'GET',
    url: baseUrl + '/system/api/v1/session/connection-settings',
    headers: headers,
    responseType: 'text',
    transformResponse: [(d) => d],
    timeout: 15000,
  })
  if (resp.status !== 200) {
    throw new Error('connection-settings failed: status ' + resp.status)
  }
  return parseConnectionSettingsScript(resp.data)
}

// Best-effort removal of any leftover HAXSITEAUTOMATEDTESTING site so each
// test run starts from a clean slate. DDEV bind-mounts the repo, so host-side
// fs removal is reflected in the container. Never throws.
function cleanupFixedSite() {
  const candidates = [
    path.join(REPO_ROOT, SITES_DIR, FIXED_SITE_NAME_LOWER),
    path.join(REPO_ROOT, ARCHIVED_DIR, FIXED_SITE_NAME_LOWER),
    path.join(REPO_ROOT, PUBLISHED_DIR, FIXED_SITE_NAME_LOWER + '.zip'),
  ]
  for (let i = 0; i < candidates.length; i++) {
    try {
      fs.removeSync(candidates[i])
    } catch (e) {
      // ignore — best effort
    }
  }
}

async function setupE2ERuntime() {
  // eslint-disable-next-line no-console
  console.warn('[e2e] ensuring DDEV is running ...')
  ensureDdevRunning()

  const baseUrl = resolveBaseUrl()
  // eslint-disable-next-line no-console
  console.warn('[e2e] base URL: ' + baseUrl)

  await waitForHealthy(baseUrl, 60000)

  // Clean slate: remove any leftover fixed-site from a prior run.
  cleanupFixedSite()

  const jwt = await loginForJwt(baseUrl, E2E_USER_NAME, E2E_USER_PASSWORD)
  // eslint-disable-next-line no-console
  console.warn('[e2e] login OK; jwt length=' + jwt.length)

  // Best-effort connection-settings for the userToken/userTokenHeader used by
  // direct axios calls in some tests (e.g. create-site's list cross-check).
  let userToken = ''
  let userTokenHeader = 'X-HAXCMS-User-Token'
  try {
    const settings = await requestConnectionSettings(baseUrl, jwt)
    if (settings && typeof settings.userToken === 'string') {
      userToken = settings.userToken
    }
    if (
      settings &&
      typeof settings.userTokenHeader === 'string' &&
      settings.userTokenHeader !== ''
    ) {
      userTokenHeader = settings.userTokenHeader
    }
  } catch (e) {
    // eslint-disable-next-line no-console
    console.warn(
      '[e2e] connection-settings unavailable (non-fatal): ' +
        (e && e.message ? e.message : String(e)),
    )
  }

  return {
    baseUrl: baseUrl,
    jwt: jwt,
    userToken: userToken,
    userTokenHeader: userTokenHeader,
    credentials: { username: E2E_USER_NAME, password: E2E_USER_PASSWORD },
    runtimeRoot: REPO_ROOT,
  }
}

async function teardownE2ERuntime(runtime) {
  // No-op on DDEV: leave the project running for the user. The browser is
  // closed by the tests themselves. Clean up the fixed site so a subsequent
  // manual dashboard inspection isn't cluttered.
  if (!runtime) {
    return
  }
  cleanupFixedSite()
}

module.exports = {
  setupE2ERuntime,
  teardownE2ERuntime,
  cleanupFixedSite,
  E2E_USER_NAME,
  E2E_USER_PASSWORD,
}
