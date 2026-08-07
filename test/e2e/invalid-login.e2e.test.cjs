'use strict'

// E2E test: app-hax dashboard INVALID login flow.
//
// Reproduces the recorded negative path (invalid-user-login.js, captured with
// Chrome's Record tab): navigate to the dashboard, enter a bogus username
// ("cool") + Next, enter a bogus password ("cool") + Login, and assert that the
// login is REJECTED and the UI remains in a logged-out, accessible state.
//
// This supersedes the skipped `negative:` subtest in login.e2e.test.cjs: that
// skip note assumed a bogus username never reaches the password step, but the
// recording proves the two-step form advances to #password for "cool" and
// submits, so the login API IS exercised with bad credentials.
//
// Covers:
//  - navigate to the dashboard, login modal renders
//  - two-step bogus login: "cool" -> Next -> "cool" -> Login
//  - login API rejection: POST /system/api/v1/session/login -> 4xx (403
//    expected), no jwt in body
//  - no haxcms_refresh_token cookie set
//  - no authenticated dashboard: GET /system/api/v1/sites never returns 200
//  - login modal still present after the failed attempt (still logged out;
//    handles both inline-error and reload-on-error behaviours)
//  - a11y scan of the login form in its post-error state
//  - visual baseline of the post-error state (invalid-login-error)
//
// Constraints honored: CommonJS (.cjs), require(), globalThis (not window), NO
// optional chaining (explicit && guards throughout), node:test +
// node:assert/strict, visual diffs WARN but never throw, no edits to
// src/build/node_modules/helpers. Bad credentials ("cool"/"cool") are not the
// harness user (E2E_USER_NAME/PASSWORD), and a single attempt does not trip the
// rate limiter (maxAttempts=5) so a 4xx — not 429 — is expected.
//
// SELECTOR NOTE: app-hax-site-login is a LIGHT-DOM (slotted) child of
// simple-modal, NOT inside simple-modal's shadowRoot, so deepQuery cannot reach
// it. This file uses the same light-then-shadow login helpers as
// login.e2e.test.cjs / create-site.e2e.test.cjs (intentionally inlined per
// file, matching the existing convention).

const test = require('node:test')
const assert = require('node:assert/strict')

const {
  setupE2ERuntime,
  teardownE2ERuntime,
  launchBrowser,
  newPage,
  createResponseCollector,
  runA11y,
  captureScreenshot,
  compareBaseline,
  selectors,
} = require('./helpers')

// Bad credentials captured in the recording (invalid-user-login.js). These are
// intentionally NOT the harness user (E2E_USER_NAME / E2E_USER_PASSWORD) so the
// login is rejected server-side.
const BAD_USERNAME = 'cool'
const BAD_PASSWORD = 'cool'

// Shared state populated by test.before; consumed by subtests + test.after.
const state = {
  runtime: null,
  browser: null,
  page: null,
  collector: null,
  statusWatcher: null,
}

// --- login-specific helpers (light DOM -> shadow DOM) ---
// Copied from login.e2e.test.cjs / create-site.e2e.test.cjs. Login helpers are
// intentionally inlined per file rather than shared (no over-modularisation),
// matching the existing convention.

// Resolve the app-hax-site-login element: document > simple-modal (light) >
// app-hax-site-login (light slotted child). Returns an element handle or null.
async function getLoginElement(page) {
  const handle = await page.evaluateHandle(() => {
    const modal = document.querySelector('simple-modal')
    if (!modal) {
      return null
    }
    return modal.querySelector('app-hax-site-login')
  })
  const el = handle.asElement()
  if (!el) {
    await handle.dispose()
    return null
  }
  return el
}

// Wait for the login modal to render: simple-modal present, opened, and its
// slotted app-hax-site-login child has a shadowRoot. Returns the login handle.
async function waitForLoginModal(page, timeoutMs) {
  const timeout = timeoutMs || 30000
  await page.waitForFunction(
    () => {
      const modal = document.querySelector('simple-modal')
      if (!modal || modal.opened !== true) {
        return false
      }
      const loginEl = modal.querySelector('app-hax-site-login')
      return !!(loginEl && loginEl.shadowRoot)
    },
    { timeout },
  )
  return getLoginElement(page)
}

// Wait for the password input to appear inside app-hax-site-login shadowRoot
// (only present after clicking "Next").
async function waitForPasswordInput(page, timeoutMs) {
  const timeout = timeoutMs || 15000
  await page.waitForFunction(
    () => {
      const modal = document.querySelector('simple-modal')
      if (!modal) {
        return false
      }
      const loginEl = modal.querySelector('app-hax-site-login')
      if (!loginEl || !loginEl.shadowRoot) {
        return false
      }
      return !!loginEl.shadowRoot.querySelector('#password')
    },
    { timeout },
  )
}

// Wait for a login input (#username / #password) to exist in the login
// element's shadowRoot, then set its value and dispatch input/change.
async function loginSetInput(p, inputId, text) {
  await p.waitForFunction(
    (id) => {
      const modal = document.querySelector('simple-modal')
      const login = modal && modal.querySelector('app-hax-site-login')
      return !!(login && login.shadowRoot && login.shadowRoot.querySelector('#' + id))
    },
    { timeout: 15000 },
    inputId,
  )
  const set = await p.evaluate((id, val) => {
    const modal = document.querySelector('simple-modal')
    const login = modal && modal.querySelector('app-hax-site-login')
    const input = login && login.shadowRoot && login.shadowRoot.querySelector('#' + id)
    if (!input) return false
    input.value = val
    input.dispatchEvent(new Event('input', { bubbles: true }))
    input.dispatchEvent(new Event('change', { bubbles: true }))
    return true
  }, inputId, text)
  if (!set) throw new Error('login input not found: #' + inputId)
}

// Click the first button whose visible text contains `text`, searching the
// login element's shadowRoot. Waits for the button to appear first.
async function loginClickButton(p, text) {
  await p.waitForFunction(
    (t) => {
      const modal = document.querySelector('simple-modal')
      const login = modal && modal.querySelector('app-hax-site-login')
      if (!login || !login.shadowRoot) return false
      const btns = login.shadowRoot.querySelectorAll('button')
      for (let i = 0; i < btns.length; i++) {
        if (btns[i].textContent.trim().toLowerCase().indexOf(t.toLowerCase()) !== -1) return true
      }
      return false
    },
    { timeout: 10000 },
    text,
  )
  const clicked = await p.evaluate((t) => {
    const modal = document.querySelector('simple-modal')
    const login = modal && modal.querySelector('app-hax-site-login')
    if (!login || !login.shadowRoot) return false
    const btns = login.shadowRoot.querySelectorAll('button')
    for (let i = 0; i < btns.length; i++) {
      if (btns[i].textContent.trim().toLowerCase().indexOf(t.toLowerCase()) !== -1) {
        btns[i].click()
        return true
      }
    }
    return false
  }, text)
  if (!clicked) throw new Error('login button not found: ' + text)
}

// Reload-robust variant of waitForLoginModal. A failed login may trigger a
// full page reload (the recording captured a waitForNavigation after submit),
// which destroys the execution context mid-poll and makes waitForFunction
// reject with "Execution context was destroyed". Retry in short windows until
// the deadline so the modal (re-opened after reload, or still showing an inline
// error) is found. Returns the login handle or null.
async function waitForLoginModalRetry(page, timeoutMs) {
  const deadline = Date.now() + (timeoutMs || 25000)
  while (Date.now() < deadline) {
    const remaining = deadline - Date.now()
    if (remaining <= 0) break
    try {
      const el = await waitForLoginModal(page, Math.min(5000, remaining))
      if (el) return el
    } catch (e) {
      // Context likely destroyed by an in-flight reload; back off and retry.
      await new Promise((r) => setTimeout(r, 300))
    }
  }
  return null
}

// --- generic helpers ---

function findCookie(cookies, name) {
  if (!Array.isArray(cookies)) {
    return null
  }
  for (let i = 0; i < cookies.length; i++) {
    if (cookies[i] && cookies[i].name === name) {
      return cookies[i]
    }
  }
  return null
}

function parseJsonSafely(value) {
  try {
    return JSON.parse(String(value || ''))
  } catch (e) {
    return null
  }
}

function summariseViolations(list) {
  if (!Array.isArray(list) || list.length === 0) {
    return '(none)'
  }
  return list
    .map((v) => {
      const id = (v && v.id) || 'unknown'
      const desc = (v && v.description) || ''
      return id + ': ' + desc
    })
    .join(' | ')
}

// Read the #errorText content from the login element's shadowRoot (or null if
// the element is not present). Non-fatal diagnostic — reports whether the form
// surfaced an inline error vs. reset to its initial state (e.g. after a
// reload-on-error).
async function readErrorText(page, errorSelector) {
  return page.evaluate((sel) => {
    const modal = document.querySelector('simple-modal')
    const login = modal && modal.querySelector('app-hax-site-login')
    const el = login && login.shadowRoot && login.shadowRoot.querySelector(sel)
    return el ? el.textContent.trim() : null
  }, errorSelector)
}

// Status-only response watcher. The shared ResponseCollector awaits
// response.text() before recording a response, which hangs indefinitely for
// some 4xx responses in puppeteer — so 401/403 rejections (exactly what this
// negative-login test needs to assert on) never get pushed to the collector's
// records. This watcher records url + status synchronously when the response
// event fires (status() is sync and always available), then best-effort reads
// the body with a 3s timeout race so a hung response.text() never blocks the
// record. Used alongside the collector: the collector remains the source for
// 200s (kept for consistency with the other e2e files); this watcher is the
// source for error responses.
function createStatusWatcher(page) {
  const records = []
  const handler = (response) => {
    const rec = {
      url: response.url(),
      status: response.status(),
      bodyText: '',
      timestamp: Date.now(),
    }
    records.push(rec)
    // Best-effort body read; status is already recorded above so a hang or
    // rejection here only affects bodyText (defaults to '').
    Promise.race([
      response.text().catch(() => ''),
      new Promise((r) => setTimeout(() => r(''), 3000)),
    ]).then((bodyText) => {
      rec.bodyText = bodyText
    })
  }
  page.on('response', handler)
  return {
    getAll: () => records.slice(),
    getFor: (urlSubstring) =>
      records.filter((r) => r.url.indexOf(urlSubstring) !== -1),
    waitFor: (urlSubstring, timeoutMs) =>
      new Promise((resolve) => {
        const deadline = Date.now() + (timeoutMs || 20000)
        const poll = () => {
          const matches = records.filter(
            (r) => r.url.indexOf(urlSubstring) !== -1,
          )
          if (matches.length > 0) {
            return resolve(matches[matches.length - 1])
          }
          if (Date.now() >= deadline) {
            return resolve(null)
          }
          setTimeout(poll, 200)
        }
        poll()
      }),
    detach: () => page.off('response', handler),
  }
}

// --- setup / teardown ---

test.before(async () => {
  state.runtime = await setupE2ERuntime()
  state.browser = await launchBrowser()
  state.page = await newPage(state.browser)
  state.collector = createResponseCollector(state.page)
  // Status-only watcher supplements the collector: it records url + status
  // synchronously (no response.text() await) so 4xx responses the collector
  // hangs on are still captured. See createStatusWatcher docs.
  state.statusWatcher = createStatusWatcher(state.page)
}, { timeout: 120000 })

test.after(async () => {
  if (state.collector) state.collector.detach()
  if (state.statusWatcher) state.statusWatcher.detach()
  if (state.browser) {
    await state.browser.close()
  }
  if (state.runtime) {
    await teardownE2ERuntime(state.runtime)
  }
}, { timeout: 60000 })

// --- invalid login e2e suite ---

test('invalid login e2e: bogus credentials are rejected', async (t) => {
  const runtime = state.runtime
  const page = state.page
  const collector = state.collector

  await t.test('navigate to dashboard and wait for login modal', async () => {
    await page.goto(runtime.baseUrl, {
      waitUntil: 'networkidle2',
      timeout: 30000,
    })
    await page.waitForSelector('app-hax', { timeout: 30000 })
    const loginEl = await waitForLoginModal(page, 30000)
    assert.ok(
      loginEl,
      'login modal (simple-modal > app-hax-site-login, light DOM) should render at load',
    )
    if (loginEl) {
      await loginEl.dispose()
    }
  })

  await t.test('login: submit bogus credentials via two-step form', async () => {
    // Step 1: bogus username + Next. The recording confirms "cool" passes the
    // username step and advances to #password (contradicting the old skip note
    // in login.e2e.test.cjs which assumed bad usernames never reach it).
    await loginSetInput(page, 'username', BAD_USERNAME)
    await loginClickButton(page, 'Next')
    // Step 2: password input appears after Next -> bogus password -> Login.
    await waitForPasswordInput(page, 15000)
    await loginSetInput(page, 'password', BAD_PASSWORD)
    await loginClickButton(page, 'Login')
  })

  await t.test('response: login API rejects with 4xx and no jwt', async () => {
    // Use the statusWatcher (not the collector): the collector awaits
    // response.text() before recording, which hangs for 4xx responses, so a
    // 403 login rejection never gets pushed. The statusWatcher records the
    // status synchronously and captures it. See createStatusWatcher docs.
    const rec = await state.statusWatcher.waitFor('/session/login', 20000)
    if (!rec) {
      const seen = state.statusWatcher
        .getAll()
        .map((r) => r.status + ' ' + r.url)
        .join('\n  ')
      assert.fail(
        'login API response not captured within 20s.\nCaptured responses:\n  ' +
          (seen || '(none)'),
      )
    }
    assert.ok(rec, 'login API response should be captured by the status watcher')
    // A single bad attempt does not trip the rate limiter (maxAttempts=5), so
    // a 4xx — 403 expected — is the rejection signal. 200 would mean login
    // succeeded, which is a failure of the negative path.
    assert.ok(
      rec.status >= 400 && rec.status < 500,
      'bad credentials should yield a 4xx error, got ' +
        rec.status +
        ' body: ' +
        String(rec.bodyText || '').slice(0, 200),
    )
    const parsed = parseJsonSafely(rec.bodyText)
    assert.ok(
      !parsed || typeof parsed.jwt !== 'string' || parsed.jwt === '',
      'failed login response must not include a jwt',
    )
    console.warn('[e2e] invalid login rejected: status=' + rec.status)
  })

  await t.test('cookies: no refresh token after failed login', async () => {
    // Let any post-submit reload settle before reading cookies. Cookies
    // persist across reloads for the same origin, so this check is valid
    // whether the form showed an inline error or reloaded.
    await new Promise((r) => setTimeout(r, 1500))
    const cookies = await page.cookies()
    const refresh = findCookie(cookies, 'haxcms_refresh_token')
    assert.ok(
      !refresh,
      'haxcms_refresh_token cookie must NOT be set after a failed login',
    )
  })

  await t.test('ui-state: still logged out (no authenticated dashboard)', async () => {
    // No authenticated GET /sites should have returned 200. The SPA fires a
    // pre-login GET /sites (401) on load; a failed login must not produce a
    // 200. Use the statusWatcher (captures ALL statuses incl. 401) as the
    // authoritative source — the collector hangs on 4xx bodies.
    const sitesResps = state.statusWatcher.getFor('/system/api/v1/sites')
    let authedCount = 0
    const sitesStatuses = []
    for (let i = 0; i < sitesResps.length; i++) {
      sitesStatuses.push(sitesResps[i].status)
      if (sitesResps[i].status === 200) {
        authedCount++
      }
    }
    assert.equal(
      authedCount,
      0,
      'no authenticated GET /sites (200) should occur after a failed login (seen: ' +
        JSON.stringify(sitesStatuses) +
        ')',
    )

    // The login modal should still be present — either it stayed open with an
    // inline error, or the page reloaded on error and the modal re-opened.
    // waitForSelector is robust to navigation; use it before the retrying
    // waitForFunction-based modal check.
    await page.waitForSelector('simple-modal', { timeout: 25000 })
    const loginElAfter = await waitForLoginModalRetry(page, 25000)
    assert.ok(
      loginElAfter,
      'login modal should still be present after a failed login (still logged out)',
    )
    if (loginElAfter) {
      await loginElAfter.dispose()
    }
    // Non-fatal diagnostic: report whether the form surfaced an inline error
    // (#errorText changed) or reset to its initial state (reload-on-error).
    const errorText = await readErrorText(page, selectors.login.errorText)
    console.warn('[e2e] post-error #errorText: ' + JSON.stringify(errorText))
  })

  await t.test('a11y: login form (post-error state)', async () => {
    const result = await runA11y(page, 'simple-modal')
    assert.ok(result, 'runA11y should return a result object')
    assert.equal(
      result.critical.length,
      0,
      'login form (post-error) should have 0 critical a11y violations. Critical: ' +
        summariseViolations(result.critical),
    )
    assert.equal(
      result.serious.length,
      0,
      'login form (post-error) should have 0 serious a11y violations. Serious: ' +
        summariseViolations(result.serious),
    )
  })

  await t.test('visual: post-error baseline', async () => {
    const buf = await captureScreenshot(page, 'invalid-login-error')
    const cmp = await compareBaseline('invalid-login-error', buf)
    assert.ok(cmp, 'compareBaseline should return a result object')
  })
}, { timeout: 180000 })
