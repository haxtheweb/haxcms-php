'use strict'

// E2E test: logged-in editor can export the whole site and the active page as
// pdf / docx / epub through the REAL editor client code (haxcms-php).
//
// PHP counterpart of haxcms-nodejs test/e2e/export-formats-auth.e2e.test.cjs.
//
// Why this exists (haxtheweb/issues#3179): the site API reads credentials only
// from the Authorization header (SiteRouteUtils::getBearerTokenFromRequest),
// never from cookies. The editor's export buttons fetched
// x/api/v1/.../export/<format> with `credentials: "include"` and no
// Authorization header, so a logged-in editor was treated as anonymous and
// every binary export answered 401 ("Authentication required for pdf site
// exports"). PHPUnit never noticed: it calls the route handlers directly with
// an auth context it builds itself. The client fix (siteApiFetch,
// haxtheweb/webcomponents#1004) reaches PHP users only once the bundled
// build/ is refreshed; this test fails until then and guards it afterwards.
//
// Flow: log in via the dashboard modal -> create HAXSITEAUTOMATEDTESTING ->
// open the site editor (/sites/<name>/) -> call haxcms-site-editor-ui
// exportSiteAs(fmt) / exportPageAs(fmt) for each binary format -> assert the
// export request carried a bearer token and returned 200.
//
// Constraints honored: CommonJS (.cjs), require(), globalThis (not window), NO
// optional chaining (explicit && guards), node:test + node:assert/strict.

const test = require('node:test')
const assert = require('node:assert/strict')

const {
  setupE2ERuntime,
  teardownE2ERuntime,
  launchBrowser,
  newPage,
  createResponseCollector,
  selectors,
  FIXED_SITE_NAME,
  deepQuery,
  E2E_USER_NAME,
  E2E_USER_PASSWORD,
} = require('./helpers')

const EXPECTED_SITE_NAME = FIXED_SITE_NAME.toLowerCase()
// PHP serves the editor at /sites/<name>/ (see edit-content.e2e.test.cjs);
// /_sites/<name>/ mismatches the page's <base href> and breaks the router.
const SITE_URL_DIR = 'sites'
const BINARY_FORMATS = ['pdf', 'docx', 'epub']

// --- local helpers (same shapes as edit-content.e2e.test.cjs) ---------------

async function waitFor(fn, timeoutMs, intervalMs) {
  const interval = intervalMs || 250
  const start = Date.now()
  let last = null
  while (Date.now() - start < timeoutMs) {
    last = await fn()
    if (last) return last
    await new Promise((r) => setTimeout(r, interval))
  }
  return last
}

async function waitForDeep(p, chain, timeoutMs) {
  return waitFor(async () => deepQuery(p, chain), timeoutMs)
}

async function typeIntoShadow(p, chain, text) {
  const el = await deepQuery(p, chain)
  if (!el) throw new Error('input not found: ' + chain.join('>'))
  await el.evaluate((input, value) => {
    input.focus()
    input.value = value
    input.dispatchEvent(new Event('input', { bubbles: true }))
    input.dispatchEvent(new Event('change', { bubbles: true }))
  }, text)
}

// <app-hax-site-login> is a light-DOM child of <simple-modal>
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

async function loginClickButton(p, text) {
  const clicked = await waitFor(
    async () =>
      p.evaluate((t) => {
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
      }, text),
    10000,
  )
  if (!clicked) throw new Error('login button not found: ' + text)
}

// The dashboard also fires GET /sites; the create response carries
// data.metadata.site.name.
async function findCreateSiteResponse(coll, expectedName, timeoutMs) {
  return waitFor(async () => {
    const all = coll.getResponsesFor('/system/api/v1/sites')
    for (let i = 0; i < all.length; i++) {
      let parsed = null
      try {
        parsed = JSON.parse(all[i].bodyText)
      } catch (e) {
        continue
      }
      const data = parsed && parsed.data
      const siteName = data && data.metadata && data.metadata.site && data.metadata.site.name
      if (siteName === expectedName) {
        return all[i]
      }
    }
    return null
  }, timeoutMs)
}

// --- shared state -----------------------------------------------------------

let runtime = null
let browser = null
let page = null
let collector = null
// export request url -> Authorization header the browser actually sent
const sentAuthByUrl = {}

test.before(async () => {
  runtime = await setupE2ERuntime()
  browser = await launchBrowser()
  page = await newPage(browser)
  collector = createResponseCollector(page)
  page.on('request', (req) => {
    const url = req.url()
    if (url.indexOf('/export/') !== -1) {
      const headers = req.headers() || {}
      sentAuthByUrl[url] = headers.authorization || ''
    }
  })
}, { timeout: 120000 })

test.after(async () => {
  if (collector) {
    try { collector.detach() } catch (e) { /* ignore */ }
  }
  if (browser) {
    try { await browser.close() } catch (e) { /* ignore */ }
  }
  if (runtime) {
    try { await teardownE2ERuntime(runtime) } catch (e) { /* ignore */ }
  }
}, { timeout: 60000 })

function sentAuthFor(urlSubstring) {
  const keys = Object.keys(sentAuthByUrl)
  for (let i = keys.length - 1; i >= 0; i--) {
    if (keys[i].indexOf(urlSubstring) !== -1) {
      return sentAuthByUrl[keys[i]]
    }
  }
  return null
}

async function runEditorExport(method, format) {
  return page.evaluate(
    async (m, f) => {
      const ui = globalThis.document.querySelector('haxcms-site-editor-ui')
      if (!ui || typeof ui[m] !== 'function') {
        return 'missing ' + m
      }
      await ui[m](f)
      return 'ok'
    },
    method,
    format,
  )
}

async function assertExport(t, method, format, urlSubstring) {
  const result = await runEditorExport(method, format)
  assert.equal(result, 'ok', 'haxcms-site-editor-ui.' + method + ' available')
  let resp = null
  try {
    resp = await collector.awaitCollectorFor(urlSubstring, 120000)
  } catch (e) {
    // asserted below
  }
  assert.ok(resp, urlSubstring + ' response captured')
  const auth = sentAuthFor(urlSubstring)
  t.diagnostic(urlSubstring + ' -> ' + resp.status + ', authorization sent: ' + (auth ? 'yes' : 'no'))
  assert.ok(
    auth && auth.toLowerCase().indexOf('bearer ') === 0,
    urlSubstring + ' must send Authorization: Bearer <jwt>; the site API ignores cookies',
  )
  assert.notEqual(
    resp.status,
    401,
    urlSubstring + ' answered 401 for a logged-in editor (client sent no usable credentials)',
  )
  assert.equal(resp.status, 200, urlSubstring + ' returned 200')
}

// --- the flow ---------------------------------------------------------------

test(
  'logged-in editor exports site and page as pdf/docx/epub without 401',
  { timeout: 600000 },
  async (t) => {
    // 1. log in via the two-step dashboard modal
    await page.goto(runtime.baseUrl, { waitUntil: 'networkidle2', timeout: 30000 })
    await page.waitForSelector('app-hax', { timeout: 25000 })
    await page.waitForSelector('simple-modal', { timeout: 25000 })
    await new Promise((r) => setTimeout(r, 1500))
    await loginSetInput(page, 'username', E2E_USER_NAME)
    await new Promise((r) => setTimeout(r, 200))
    await loginClickButton(page, 'Next')
    await loginSetInput(page, 'password', E2E_USER_PASSWORD)
    await new Promise((r) => setTimeout(r, 200))
    await loginClickButton(page, 'Login')
    const loginResp = await collector.awaitCollectorFor('session/login', 20000)
    assert.equal(loginResp.status, 200, 'login API returned 200')

    // 2. create the fixed site (blank-site path)
    const ucf = await waitForDeep(page, selectors.dashboard.useCaseFilterChain, 30000)
    assert.ok(ucf, 'dashboard rendered after login')
    await ucf.evaluate((el) => {
      el.continueAction(-1)
    })
    const modalOpen = await waitFor(async () => {
      const m = await deepQuery(page, selectors.create.siteCreationModalChain)
      if (!m) return false
      return m.evaluate((el) => el.open === true)
    }, 15000)
    assert.ok(modalOpen, 'create-site modal opened')
    await waitForDeep(page, selectors.create.siteNameInputChain, 10000)
    await typeIntoShadow(page, selectors.create.siteNameInputChain, FIXED_SITE_NAME)
    await new Promise((r) => setTimeout(r, 300))
    const createBtn = await deepQuery(page, selectors.create.createSiteButtonChain)
    assert.ok(createBtn, 'Create Site button found')
    await createBtn.evaluate((b) => b.click())
    const created = await findCreateSiteResponse(collector, EXPECTED_SITE_NAME, 60000)
    assert.ok(created, 'create site API response captured')
    assert.equal(created.status, 200, 'create site API returned 200')

    // 3. open the site editor
    const editorUrl = runtime.baseUrl + '/' + SITE_URL_DIR + '/' + EXPECTED_SITE_NAME + '/'
    t.diagnostic('[e2e] navigating to editor: ' + editorUrl)
    try {
      await page.goto(editorUrl, { waitUntil: 'networkidle2', timeout: 30000 })
    } catch (e) {
      await page.goto(editorUrl, { waitUntil: 'domcontentloaded', timeout: 30000 })
    }
    await page.waitForSelector('haxcms-site-editor-ui', { timeout: 30000 })
    // page-level exports need an active item id
    const activeId = await waitFor(
      async () =>
        page.evaluate(() => {
          const s =
            globalThis.HAXCMS && globalThis.HAXCMS.requestAvailability
              ? globalThis.HAXCMS.requestAvailability().store
              : null
          return s && s.activeId ? String(s.activeId) : false
        }),
      30000,
    )
    assert.ok(activeId, 'editor has an active page')

    // 4. every binary export, whole site then active page
    for (const format of BINARY_FORMATS) {
      await t.test('whole-site ' + format + ' export', async (st) => {
        await assertExport(st, 'exportSiteAs', format, '/x/api/v1/site/export/' + format)
      })
    }
    for (const format of BINARY_FORMATS) {
      await t.test('page ' + format + ' export', async (st) => {
        await assertExport(
          st,
          'exportPageAs',
          format,
          '/x/api/v1/items/' + encodeURIComponent(activeId) + '/export/' + format,
        )
      })
    }
  },
)
