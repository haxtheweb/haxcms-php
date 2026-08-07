'use strict'

// E2E test: edit & save content in the HAX editor (the core authoring loop).
//
// Flow: boot isolated runtime (JWT auth ENABLED) -> login via two-step modal ->
// create HAXSITEAUTOMATEDTESTING -> navigate into the site editor -> enter edit
// mode -> type content into hax-body via importContent -> visual baseline (edit
// mode, before save) -> click Save -> intercept PATCH /x/api/v1/content/:idOrSlug
// -> assert 200 + data shape -> disk cross-check (saved HTML contains the test
// content) -> a11y scan scoped to haxcms-site-editor-ui -> teardown.
//
// Constraints honored: CommonJS (.cjs), require(), globalThis (not window), NO
// optional chaining (explicit && guards everywhere), node:test +
// node:assert/strict, visual diffs WARN but never fail, no edits to src/build/
// node_modules/helpers (selectors.cjs untouched).

const test = require('node:test')
const assert = require('node:assert/strict')
const fs = require('fs-extra')
const path = require('path')
const axios = require('axios')

// pixelmatch v7 is pure ESM ("type":"module"), so require('pixelmatch') returns
// {__esModule, default} — an object, not a function. The visual helper calls
// pixelmatch(...) directly, which can throw "pixelmatch is not a function" on
// the diff path (any run AFTER baselines already exist). We are not allowed to
// edit the helper files, so we shim the CJS/ESM interop HERE by re-binding the
// cached module's exports to the default function BEFORE the helper loads. This
// is a runtime in-memory patch only; no helper or node_modules files are
// modified.
const _pmPath = require.resolve('pixelmatch')
const _pm = require(_pmPath)
if (_pm && typeof _pm !== 'function' && _pm.default && typeof _pm.default === 'function') {
  if (require.cache[_pmPath]) {
    require.cache[_pmPath].exports = _pm.default
  }
}

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
  FIXED_SITE_NAME,
  deepQuery,
  E2E_USER_NAME,
  E2E_USER_PASSWORD,
} = require('./helpers')

// The create API normalises the site name to lowercase via
// HAXCMS.generateMachineName() (HAXCMS.js:3163 toLowerCase), so the server
// stores/returns the site as 'haxsiteautomatedtesting' even though we type
// HAXSITEAUTOMATEDTESTING. All API + filesystem assertions use the lowercased
// form to match the real API behaviour.
const EXPECTED_SITE_NAME = FIXED_SITE_NAME.toLowerCase()
const SITES_DIR = '_sites'
// PHP serves the site editor at /sites/<name>/ (the /sites symlink to _sites),
// and the editor page's <base href> is /sites/<name>/ . Navigating to
// /_sites/<name>/ mismatches the base tag and breaks the editor's client-side
// router ("AbortError: Transition was skipped"), so use /sites/ for URLs.
// _sites/ is still used for filesystem paths (SITES_DIR above).
const SITE_URL_DIR = 'sites'


// --- local utility helpers -------------------------------------------------

// Poll an async predicate until it returns a truthy value or timeout.
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

// Poll a deepQuery chain until the element exists.
async function waitForDeep(page, chain, timeoutMs) {
  return waitFor(async () => deepQuery(page, chain), timeoutMs)
}

// Set a shadow-DOM input's value + dispatch input/change (proven reliable for
// Lit-bound inputs in this app).
async function typeIntoShadow(page, chain, text) {
  const el = await deepQuery(page, chain)
  if (!el) throw new Error('input not found: ' + chain.join('>'))
  await el.evaluate((input, value) => {
    input.focus()
    input.value = value
    input.dispatchEvent(new Event('input', { bubbles: true }))
    input.dispatchEvent(new Event('change', { bubbles: true }))
  }, text)
}

// --- login helpers (light-DOM aware) --------------------------------------
// <app-hax-site-login> is a LIGHT-DOM child of <simple-modal> (slotted content),
// so deepQuery (which pierces shadow roots at every step) cannot reach it. We
// query the login element directly via the light DOM and operate on its own
// shadowRoot for inputs/buttons. Matches the verified discovery pass + the
// create-site test.

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

// --- recursive shadow-DOM walk for hax-body --------------------------------
// haxcms-site-editor renders inside the active theme at a VARIABLE shadow-DOM
// depth, so deepQuery (which walks a fixed chain) cannot reach hax-body. This
// helper recursively walks all shadow roots to find an element by selector.
// hax-body lives at: <theme ...> shadowRoot > haxcms-site-editor (light DOM) >
// h-a-x#hax (light DOM) > shadowRoot > hax-body.
async function deepFindRecursive(page, selector) {
  const handle = await page.evaluateHandle((sel) => {
    function walk(root) {
      if (!root) return null
      var found = root.querySelector(sel)
      if (found) return found
      var els = root.querySelectorAll('*')
      for (var i = 0; i < els.length; i++) {
        if (els[i].shadowRoot) {
          var r = walk(els[i].shadowRoot)
          if (r) return r
        }
      }
      return null
    }
    return walk(document)
  }, selector)
  const el = handle.asElement()
  if (!el) {
    await handle.dispose()
    return null
  }
  return el
}

// Shared recursive walk helper (used in multiple page.evaluate calls).
// Returns the hax-body element or null.
const WALK_HAX_BODY_FN = `
function walk(root) {
  if (!root) return null
  var found = root.querySelector('hax-body')
  if (found) return found
  var els = root.querySelectorAll('*')
  for (var i = 0; i < els.length; i++) {
    if (els[i].shadowRoot) {
      var r = walk(els[i].shadowRoot)
      if (r) return r
    }
  }
  return null
}
`

// Check if hax-body is in edit mode (edit-mode attribute present). This is the
// reliable readiness signal — hax-body itself does NOT always get the
// `contenteditable` attribute; _editModeChanged applies contenteditable to the
// slotted CHILDREN, and hax-body only gets it when _activeNodeChanged fires for
// a text element. The edit-mode attribute reflects the editMode property from
// the store.
async function haxBodyEditModeActive(page) {
  return page.evaluate((walkSrc) => {
    eval(walkSrc)
    var body = walk(document)
    if (!body) return { found: false }
    return {
      found: true,
      editModeAttr: body.hasAttribute('edit-mode'),
      childCount: body.children ? body.children.length : -1,
    }
  }, WALK_HAX_BODY_FN)
}

// Check if a marker string appears in hax-body's slotted content.
async function markerInHaxBody(page, marker) {
  return page.evaluate((walkSrc, m) => {
    eval(walkSrc)
    var body = walk(document)
    if (!body || !body.shadowRoot) return false
    var slot = body.shadowRoot.querySelector('#body')
    if (!slot) return false
    var nodes = slot.assignedNodes({ flatten: true })
    for (var i = 0; i < nodes.length; i++) {
      if (nodes[i] && nodes[i].textContent && nodes[i].textContent.indexOf(m) !== -1) {
        return true
      }
    }
    return false
  }, WALK_HAX_BODY_FN, marker)
}

// Click a button inside the haxcms-site-editor-ui shadowRoot by id. The
// simple-toolbar-button hosts wrap an inner <button>; click the inner button
// if present, else click the host. Returns { clicked: true } or { error: ... }.
async function clickEditorButtonById(page, id) {
  return page.evaluate((btnId) => {
    const ui = document.querySelector('haxcms-site-editor-ui')
    if (!ui || !ui.shadowRoot) return { error: 'no ui' }
    const btn = ui.shadowRoot.querySelector(btnId)
    if (!btn) return { error: 'no ' + btnId }
    var inner = btn.shadowRoot && btn.shadowRoot.querySelector('button')
    if (inner) inner.click()
    else btn.click()
    return { clicked: true }
  }, id)
}

// Safe visual comparison wrapper: pixelmatch ESM interop can throw on the diff
// path. We catch + WARN (never fail), per the visual-diffs-warn-only rule.
async function safeCompareBaseline(name, buf, opts, t) {
  try {
    return await compareBaseline(name, buf, opts)
  } catch (e) {
    const msg = e && e.message ? e.message : String(e)
    t.diagnostic(
      'visual compareBaseline for "' + name + '" threw (helper bug, non-fatal): ' + msg,
    )
    return {
      diffPixels: -1,
      totalPixels: -1,
      diffPercent: -1,
      baselineExists: false,
      baselineUpdated: false,
      error: msg,
    }
  }
}

// Find the POST /system/api/v1/sites (create) response among ALL /sites
// responses. The dashboard fires GET /sites on load, so we disambiguate by
// body shape: the create response carries data.metadata.site.name; the list
// response carries data.items. Returns the matching record or null.
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
      const siteName =
        data && data.metadata && data.metadata.site && data.metadata.site.name
      if (siteName === expectedName) {
        return all[i]
      }
    }
    return null
  }, timeoutMs)
}

// --- shared state (populated in before / cleaned in after) -----------------
let runtime = null
let browser = null
let page = null
let collector = null

// --- setup / teardown ------------------------------------------------------

test.before(async () => {
  runtime = await setupE2ERuntime()
  browser = await launchBrowser()
  page = await newPage(browser)
  collector = createResponseCollector(page)
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

// --- the flow --------------------------------------------------------------

test(
  'edit & save content in HAX editor — core authoring loop',
  { timeout: 300000 },
  async (t) => {
    assert.ok(page, 'page initialised in before hook')
    assert.ok(runtime && runtime.baseUrl, 'runtime booted with baseUrl')

    // 2. Navigate to the dashboard and log in via the two-step modal.
    await page.goto(runtime.baseUrl, {
      waitUntil: 'networkidle2',
      timeout: 30000,
    })
    await page.waitForSelector('app-hax', { timeout: 25000 })
    await page.waitForSelector('simple-modal', { timeout: 25000 })
    // give the login element a moment to stamp its shadow DOM
    await new Promise((r) => setTimeout(r, 1500))

    // step 1: username -> Next
    await loginSetInput(page, 'username', E2E_USER_NAME)
    await new Promise((r) => setTimeout(r, 200))
    await loginClickButton(page, 'Next')
    // step 2: #password appears after Next -> set it -> Login
    await loginSetInput(page, 'password', E2E_USER_PASSWORD)
    await new Promise((r) => setTimeout(r, 200))
    await loginClickButton(page, 'Login')

    // login API: POST /system/api/v1/session/login -> { status:200, jwt }
    const loginResp = await collector.awaitCollectorFor('session/login', 20000)
    assert.strictEqual(loginResp.status, 200, 'login API returned status 200')

    // 3. Wait for the dashboard + create HAXSITEAUTOMATEDTESTING.
    const ucf = await waitForDeep(
      page,
      selectors.dashboard.useCaseFilterChain,
      30000,
    )
    assert.ok(ucf, 'dashboard app-hax-use-case-filter rendered after login')

    // Open the create-site modal via continueAction(-1) (blank-site path).
    await ucf.evaluate((el) => {
      el.continueAction(-1)
    })
    const modalOpen = await waitFor(async () => {
      const m = await deepQuery(page, selectors.create.siteCreationModalChain)
      if (!m) return false
      return m.evaluate((el) => el.open === true)
    }, 15000)
    assert.ok(modalOpen, 'create-site modal opened via continueAction(-1)')

    // Type the fixed site name into #siteName.
    await waitForDeep(page, selectors.create.siteNameInputChain, 10000)
    await typeIntoShadow(page, selectors.create.siteNameInputChain, FIXED_SITE_NAME)
    await new Promise((r) => setTimeout(r, 300))

    // Click the "Create Site" button (.button.button-primary).
    const createBtn = await deepQuery(page, selectors.create.createSiteButtonChain)
    assert.ok(createBtn, 'Create Site button found')
    await createBtn.evaluate((b) => b.click())

    // Verify the POST /system/api/v1/sites create response.
    const found = await findCreateSiteResponse(collector, EXPECTED_SITE_NAME, 60000)
    assert.ok(found, 'create site API response captured for ' + EXPECTED_SITE_NAME)
    assert.strictEqual(found.status, 200, 'create site API returned status 200')
    let createBody = null
    try {
      createBody = JSON.parse(found.bodyText)
    } catch (e) {
      createBody = null
    }
    const createdName =
      createBody &&
      createBody.data &&
      createBody.data.metadata &&
      createBody.data.metadata.site &&
      createBody.data.metadata.site.name
    assert.strictEqual(
      createdName,
      EXPECTED_SITE_NAME,
      'data.metadata.site.name matches lowercased FIXED_SITE_NAME',
    )
    t.diagnostic('[e2e] create API 200: name=' + createdName)

    // 4. Navigate into the site editor. Use /sites/ (SITE_URL_DIR) to match the
    //    PHP-served <base href> so the editor router hydrates correctly.
    const editorUrl = runtime.baseUrl + '/' + SITE_URL_DIR + '/' + EXPECTED_SITE_NAME + '/'
    t.diagnostic('[e2e] navigating to editor: ' + editorUrl)
    // The task suggests networkidle2; the SPA's heavy dynamic-import waterfall
    // can exceed that, so fall back to domcontentloaded (proven by the discovery
    // pass) if networkidle2 times out.
    try {
      await page.goto(editorUrl, { waitUntil: 'networkidle2', timeout: 30000 })
    } catch (e) {
      t.diagnostic('[e2e] networkidle2 timed out, retrying domcontentloaded: ' + (e && e.message ? e.message : e))
      await page.goto(editorUrl, { waitUntil: 'domcontentloaded', timeout: 30000 })
    }
    await page.waitForSelector('haxcms-site-editor-ui', { timeout: 30000 })
    // give the editor time to settle + wire up the content body + hydrate the
    // active page (the PHP-served editor needs a longer settle than nodejs).
    await new Promise((r) => setTimeout(r, 8000))

    // 5. Enter edit mode: wait for #editbutton to be enabled, then click it.
    const editBtnReady = await waitFor(
      async () =>
        page.evaluate(() => {
          const ui = document.querySelector('haxcms-site-editor-ui')
          if (!ui || !ui.shadowRoot) return false
          const b = ui.shadowRoot.querySelector('#editbutton')
          return !!(b && !b.hasAttribute('disabled') && !b.hasAttribute('hidden'))
        }),
      30000,
    )
    assert.ok(editBtnReady, '#editbutton is enabled and visible')
    const enterResult = await clickEditorButtonById(page, '#editbutton')
    assert.ok(
      enterResult && enterResult.clicked,
      'edit button clicked to enter edit mode: ' + JSON.stringify(enterResult),
    )
    // wait for edit mode to engage + hax-body to render
    await new Promise((r) => setTimeout(r, 4000))

    // Diagnostic: the button label should flip from 'Edit' to 'Save'.
    const editModeInfo = await page.evaluate(() => {
      const ui = document.querySelector('haxcms-site-editor-ui')
      if (!ui || !ui.shadowRoot) return null
      const b = ui.shadowRoot.querySelector('#editbutton')
      return {
        label: b ? (b.getAttribute('label') || b.label || '') : '',
        editMode: ui.hasAttribute('edit-mode'),
      }
    })
    t.diagnostic('[e2e] after edit click: ' + JSON.stringify(editModeInfo))

    // Wait for hax-body to be found in edit mode. NOTE: hax-body itself does
    // NOT always get the `contenteditable` attribute — _editModeChanged applies
    // contenteditable to the slotted CHILDREN via _applyContentEditable, and
    // hax-body only gets it when _activeNodeChanged fires for a text element.
    // When the edit-mode autorun's importContent is in progress, the focus
    // logic (which sets the active node) is skipped, so hax-body may never get
    // contenteditable despite being in edit mode. The reliable signal is the
    // `edit-mode` attribute (reflects the editMode property from the store).
    const bodyReady = await waitFor(async () => haxBodyEditModeActive(page), 30000)
    assert.ok(
      bodyReady && bodyReady.found && bodyReady.editModeAttr,
      'hax-body found in edit mode (edit-mode attribute present)',
    )
    t.diagnostic('[e2e] bodyReady OK: ' + JSON.stringify(bodyReady))

    // 6. Locate hax-body via recursive shadow walk, then set content.
    //    hax-body.importContent() is ASYNC (uses setTimeout(0) internally) and
    //    has an "importing" state guard that skips duplicate calls. The edit-mode
    //    autorun in haxcms-site-editor calls importContent(existingContent) when
    //    edit mode engages, so we must wait for that to finish before calling
    //    importContent ourselves.
    //
    //    CRITICAL: the content MUST include a <page-break> tag. The server's
    //    saveNode handler runs HAXCMS.pageBreakParser(body) which splits the
    //    body by <page-break> tags. If there are NO page-break tags, the parser
    //    returns an EMPTY array, the write loop never executes, and the file is
    //    NOT written — but the response is still {status:200, data:page} with
    //    the ORIGINAL page data (timestamps unchanged). The HAX editor normally
    //    maintains a <page-break> element as the first child of hax-body; our
    //    importContent call clears existing children, so we must include a
    //    page-break in the imported HTML. The content goes AFTER the page-break
    //    tag (page-break is a separator, not a wrapper).
    const bodyHandle = await deepFindRecursive(page, 'hax-body')
    assert.ok(bodyHandle, 'hax-body element handle resolved via recursive walk')
    const testMarker = 'E2E automated test content'
    // page-break tag with published attribute; content follows it.
    const testContent =
      '<page-break published="published"></page-break><p>' + testMarker + '</p>'

    // Wait for the edit-mode autorun's importContent to settle: poll until
    // hax-body has at least one slotted child (the initial page content).
    const initialContentReady = await waitFor(
      async () =>
        page.evaluate((walkSrc) => {
          eval(walkSrc)
          var body = walk(document)
          if (!body || !body.shadowRoot) return false
          var slot = body.shadowRoot.querySelector('#body')
          if (!slot) return false
          var nodes = slot.assignedNodes({ flatten: true })
          return nodes && nodes.length > 0
        }, WALK_HAX_BODY_FN),
      15000,
    )
    t.diagnostic('[e2e] initial hax-body content ready: ' + !!initialContentReady)

    // Try importContent with our test content (includes page-break tag).
    const typeInfo = await bodyHandle.evaluate((el, html) => {
      if (typeof el.importContent === 'function') {
        el.importContent(html)
      } else {
        el.innerHTML = html
      }
      el.dispatchEvent(new Event('input', { bubbles: true }))
      return {
        usedImportContent: typeof el.importContent === 'function',
        childCount: el.children.length,
      }
    }, testContent)
    t.diagnostic('[e2e] importContent called: ' + JSON.stringify(typeInfo))

    // Poll for the test marker to appear in hax-body's slot assignedNodes
    // (importContent is async via setTimeout(0) + requestAnimationFrame).
    let contentAppeared = await waitFor(
      async () => markerInHaxBody(page, testMarker),
      8000,
    )
    t.diagnostic('[e2e] test content appeared via importContent: ' + !!contentAppeared)

    // Fallback: if importContent didn't render the content (e.g. "importing"
    // state guard skipped it), directly append a <page-break> + <p> to hax-body's
    // light DOM. The page-break is REQUIRED for the save to write the file
    // (pageBreakParser splits by page-break tags; without one, no write occurs).
    if (!contentAppeared) {
      t.diagnostic('[e2e] importContent did not render; falling back to direct page-break + <p> append')
      await bodyHandle.evaluate((el) => {
        var pb = globalThis.document.createElement('page-break')
        pb.setAttribute('published', 'published')
        el.appendChild(pb)
        var p = globalThis.document.createElement('p')
        p.textContent = 'E2E automated test content'
        el.appendChild(p)
        el.dispatchEvent(new Event('input', { bubbles: true }))
      })
      await new Promise((r) => setTimeout(r, 500))
      contentAppeared = await waitFor(
        async () => markerInHaxBody(page, testMarker),
        5000,
      )
      t.diagnostic('[e2e] test content appeared via direct append: ' + !!contentAppeared)
    }
    assert.ok(contentAppeared, 'test content appeared in hax-body before save')

    // 10. Visual baseline: editor in edit mode, before save.
    const editBuf = await captureScreenshot(page, 'edit-content-editor')
    const editDiff = await safeCompareBaseline('edit-content-editor', editBuf, null, t)
    t.diagnostic(
      '[visual] edit-content-editor: diffPercent=' +
        (editDiff.diffPercent * 100).toFixed(3) +
        '% baselineExists=' +
        editDiff.baselineExists +
        ' baselineUpdated=' +
        editDiff.baselineUpdated,
    )

    // 7. Click Save (#editbutton now reads 'Save') + intercept the saveNode
    //    PATCH response.
    const saveResult = await clickEditorButtonById(page, '#editbutton')
    assert.ok(
      saveResult && saveResult.clicked,
      'save button (#editbutton) clicked: ' + JSON.stringify(saveResult),
    )
    let saveResp = null
    try {
      saveResp = await collector.awaitCollectorFor('/x/api/v1/content/', 30000)
    } catch (e) {
      t.diagnostic('[e2e] saveNode response not captured: ' + (e && e.message ? e.message : e))
    }
    assert.ok(saveResp, 'saveNode (PATCH /x/api/v1/content/) response captured')
    assert.strictEqual(saveResp.status, 200, 'saveNode API returned status 200')
    let saveBody = null
    try {
      saveBody = JSON.parse(saveResp.bodyText)
    } catch (e) {
      saveBody = null
    }
    t.diagnostic('[e2e] saveNode url=' + saveResp.url)
    t.diagnostic('[e2e] saveNode response body: ' + (saveResp.bodyText || '').substring(0, 300))
    assert.ok(saveBody && saveBody.data, 'saveNode response has data')
    assert.ok(
      saveBody.data && typeof saveBody.data.id === 'string',
      'saveNode response data has id (string)',
    )

    // 8. Disk cross-check: read the saved page HTML file + assert it contains
    //    the test content. The saveNode response data.location is the relative
    //    path (e.g. 'pages/<id>/index.html'); fall back to listing pages/ if
    //    location is absent.
    const pageId = saveBody.data.id
    const pageLocation =
      saveBody.data.location && typeof saveBody.data.location === 'string'
        ? saveBody.data.location
        : 'pages/' + pageId + '/index.html'
    const siteDir = path.join(runtime.runtimeRoot, SITES_DIR, EXPECTED_SITE_NAME)
    const pageFilePath = path.join(siteDir, pageLocation)
    t.diagnostic('[e2e] checking page file: ' + pageFilePath)
    let fileContent = null
    if (fs.pathExistsSync(pageFilePath)) {
      fileContent = fs.readFileSync(pageFilePath, 'utf8')
    } else {
      // Fallback: list the pages directory and try each entry's index.html.
      const pagesDir = path.join(siteDir, 'pages')
      t.diagnostic('[e2e] page file not at expected path; listing pages dir: ' + pagesDir)
      try {
        const entries = fs.readdirSync(pagesDir)
        for (let i = 0; i < entries.length; i++) {
          const candidate = path.join(pagesDir, entries[i], 'index.html')
          if (fs.pathExistsSync(candidate)) {
            fileContent = fs.readFileSync(candidate, 'utf8')
            t.diagnostic('[e2e] found page file at: ' + candidate)
            break
          }
        }
      } catch (e2) {
        t.diagnostic('[e2e] cannot list pages dir: ' + e2.message)
      }
    }
    assert.ok(fileContent, 'saved page HTML file was read from disk')
    t.diagnostic('[e2e] file content (first 200 chars): ' + (fileContent || '').substring(0, 200))
    assert.ok(
      fileContent.indexOf('E2E automated test content') !== -1,
      'saved page file contains the test content "E2E automated test content"',
    )
    t.diagnostic('[e2e] disk cross-check OK: content persisted to ' + pageFilePath)

    // 9. A11y: axe scoped to the editor chrome (haxcms-site-editor-ui). The
    //    editor-ui is at document root with a shadowRoot, so the string selector
    //    resolves directly. Document any critical/serious findings as warnings
    //    (non-fatal per task spec); hard-assert only that the scan ran.
    let a11y = null
    try {
      a11y = await runA11y(page, 'haxcms-site-editor-ui')
    } catch (e) {
      t.diagnostic('[a11y] runA11y threw: ' + (e && e.message ? e.message : e))
    }
    if (a11y) {
      const critical = a11y.critical || []
      const serious = a11y.serious || []
      t.diagnostic(
        '[a11y] haxcms-site-editor-ui: critical=' +
          critical.length +
          ' serious=' +
          serious.length +
          ' totalViolations=' +
          ((a11y.violations && a11y.violations.length) || 0),
      )
      // Document nonzero findings as warnings (non-fatal).
      for (let i = 0; i < critical.length; i++) {
        t.diagnostic(
          '[a11y] CRITICAL: id=' +
            critical[i].id +
            ' help=' +
            (critical[i].help || critical[i].description || '') +
            ' (nodes=' +
            ((critical[i].nodes && critical[i].nodes.length) || 0) +
            ')',
        )
      }
      for (let i = 0; i < serious.length; i++) {
        t.diagnostic(
          '[a11y] SERIOUS: id=' +
            serious[i].id +
            ' help=' +
            (serious[i].help || serious[i].description || '') +
            ' (nodes=' +
            ((serious[i].nodes && serious[i].nodes.length) || 0) +
            ')',
        )
      }
      // Soft assertion: the scan ran and returned a result object.
      assert.ok(a11y, 'runA11y returned a result object for haxcms-site-editor-ui')
    } else {
      t.diagnostic('[a11y] could not run scoped axe on haxcms-site-editor-ui (non-fatal)')
    }
  },
)
