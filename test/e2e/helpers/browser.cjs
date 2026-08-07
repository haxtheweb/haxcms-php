'use strict'

// Browser lifecycle + response collection for E2E tests.
// Uses puppeteer-core + the system Chrome binary (found via convertUtils
// findChromeExecutable). Never installs full puppeteer.

const puppeteer = require('puppeteer-core')
const fs = require('fs')
const path = require('path')

const REPO_ROOT = path.resolve(__dirname, '..', '..', '..')
const DEFAULT_VIEWPORT = { width: 1280, height: 800 }
// No cert flags: the DDEV dashboard is served over HTTP (http://haxcms.ddev.site)
// and the dev refresh cookie is non-Secure, so HTTPS/cert validation is never
// exercised. Do NOT add --ignore-certificate-errors: it swaps Chrome's cert
// verifier mid-session and causes net::ERR_CERT_VERIFIER_CHANGED on the
// opportunistic HTTPS subresource loads (Chrome reuses the same host's HTTPS
// socket for some /build/ imports). Do NOT add ignoreHTTPSErrors:true either.
// --disable-dev-shm-usage prevents Chrome from using /dev/shm (small in
// Docker/CI), which causes slowdowns/crashes when running 7 heavy SPA tests
// serially against a single DDEV instance.
const DEFAULT_LAUNCH_ARGS = ['--no-sandbox', '--disable-setuid-sandbox', '--disable-dev-shm-usage']

// Chrome detection. Mirrors src/lib/convertUtils.js findChromeExecutable()
// (lines 275-296) exactly — that function is the canonical detector used by
// htmlToPdfBuffer but is NOT exported from convertUtils, and we are not allowed
// to modify src/. So we replicate the identical logic here to stay consistent.
function findChromeExecutable() {
  const envPath = process.env.PUPPETEER_EXECUTABLE_PATH
  if (envPath && fs.existsSync(envPath)) {
    return envPath
  }
  const candidates = [
    '/usr/bin/google-chrome',
    '/usr/bin/google-chrome-stable',
    '/usr/bin/chromium',
    '/usr/bin/chromium-browser',
    '/snap/bin/chromium',
    '/usr/bin/chromium-browser',
    '/usr/bin/microsoft-edge',
    '/usr/bin/microsoft-edge-stable',
  ]
  for (let i = 0; i < candidates.length; i++) {
    if (fs.existsSync(candidates[i])) {
      return candidates[i]
    }
  }
  return null
}

// Launch headless Chrome using the system binary. Mirrors the launch pattern
// from convertUtils.js htmlToPdfBuffer (line ~322).
async function launchBrowser(opts) {
  opts = opts || {}
  const executablePath = findChromeExecutable()
  if (!executablePath) {
    throw new Error(
      'No Chrome/Chromium executable found. Install Chrome or set PUPPETEER_EXECUTABLE_PATH.',
    )
  }
  const browser = await puppeteer.launch({
    executablePath,
    headless: opts.headless !== false,
    args: opts.args || DEFAULT_LAUNCH_ARGS,
  })
  return browser
}

// Create a page with the fixed E2E viewport.
async function newPage(browser, opts) {
  opts = opts || {}
  const page = await browser.newPage()
  await page.setViewport(opts.viewport || DEFAULT_VIEWPORT)
  // Capture console + pageerrors so test failures surface useful context.
  page.on('console', (msg) => {
    if (msg.type() === 'error') {
      // eslint-disable-next-line no-console
      console.warn(`[browser console.error] ${msg.text()}`)
    }
  })
  page.on('pageerror', (err) => {
    // eslint-disable-next-line no-console
    console.warn(`[browser pageerror] ${err && err.message ? err.message : err}`)
  })
  page.on('requestfailed', (req) => {
    const failure = req.failure()
    // eslint-disable-next-line no-console
    console.warn(`[browser requestfailed] ${req.url()} - ${failure ? failure.errorText : 'unknown'}`)
  })
  // Opt-in 4xx/5xx response logger for diagnosing editor-flow issues.
  // Enable with HAXCMS_E2E_DEBUG_HTTP=1.
  if (process.env.HAXCMS_E2E_DEBUG_HTTP === '1') {
    page.on('response', (resp) => {
      const st = resp.status()
      if (st >= 400) {
        // eslint-disable-next-line no-console
        console.warn(`[browser ${st}] ${resp.url()}`)
      }
    })
  }
  return page
}

// ResponseCollector: attaches to page.on('response') and records API responses
// matching the system/x API path prefixes. Provides lookup + await helpers.
function createResponseCollector(page, opts) {
  opts = opts || {}
  const matchPatterns = opts.patterns || ['/system/api/v1/', '/x/api/']
  const responses = []
  const pendingResolvers = []

  async function recordResponse(response) {
    const url = response.url()
    let matched = false
    for (let i = 0; i < matchPatterns.length; i++) {
      if (url.indexOf(matchPatterns[i]) !== -1) {
        matched = true
        break
      }
    }
    if (!matched) {
      return
    }
    let bodyText = ''
    try {
      bodyText = await response.text()
    } catch (e) {
      bodyText = ''
    }
    const record = {
      url,
      status: response.status(),
      bodyText,
      timestamp: Date.now(),
    }
    responses.push(record)
    // Resolve any awaiters waiting for a matching url substring.
    for (let i = pendingResolvers.length - 1; i >= 0; i--) {
      const entry = pendingResolvers[i]
      if (url.indexOf(entry.substring) !== -1) {
        pendingResolvers.splice(i, 1)
        entry.resolve(record)
      }
    }
  }

  page.on('response', recordResponse)

  function getResponsesFor(urlSubstring) {
    const filtered = []
    for (let i = 0; i < responses.length; i++) {
      if (responses[i].url.indexOf(urlSubstring) !== -1) {
        filtered.push(responses[i])
      }
    }
    return filtered
  }

  function awaitCollectorFor(urlSubstring, timeoutMs) {
    const timeout = timeoutMs || 10000
    // If we already have a match, resolve immediately.
    const existing = getResponsesFor(urlSubstring)
    if (existing.length > 0) {
      return Promise.resolve(existing[existing.length - 1])
    }
    return new Promise((resolve, reject) => {
      const entry = { substring: urlSubstring, resolve }
      pendingResolvers.push(entry)
      setTimeout(() => {
        const idx = pendingResolvers.indexOf(entry)
        if (idx !== -1) {
          pendingResolvers.splice(idx, 1)
          reject(
            new Error(
              `ResponseCollector timed out after ${timeout}ms waiting for "${urlSubstring}"`,
            ),
          )
        }
      }, timeout)
    })
  }

  function detach() {
    page.off('response', recordResponse)
  }

  return {
    getResponsesFor,
    awaitCollectorFor,
    detach,
    getAll: () => responses.slice(),
  }
}

module.exports = {
  launchBrowser,
  newPage,
  createResponseCollector,
  DEFAULT_VIEWPORT,
}
