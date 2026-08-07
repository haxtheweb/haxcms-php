'use strict'

// Centralised selector map for the app-hax dashboard UI.
// Populated from source analysis of the built app-hax components under
// src/public/build/es6/node_modules/@haxtheweb/app-hax/ and refined by the
// runtime discovery pass (test/e2e/helpers/.discovery.cjs).
//
// SELECTORS ARE SHADOW-DOM SCOPED. Most app-hax elements render their controls
// inside shadow DOM. Tests must pierce shadow roots with page.$() /
// page.evaluate() that calls el.shadowRoot.querySelector(...). The helper
// `deepQuery(page, selectorChain)` in this file walks a chain of shadow roots.
//
// VERIFICATION STATUS (from discovery pass):
// - LOGIN: VERIFIED at runtime (simple-modal > app-hax-site-login, #username,
//   Next button, #password, Login button, login API {status:200, jwt}).
// - DASHBOARD: VERIFIED at runtime (app-hax > app-hax-use-case-filter >
//   #returnToSection > app-hax-search-results, #create-site-heading,
//   app-hax-site-creation-modal with #siteName + Create Site button).
// - CREATE SITE: VERIFIED at runtime (modal exists with #siteName.form-input,
//   .button.button-primary text="Create Site", .button.button-secondary text="Cancel").
//   The entry-point trigger (clicking app-hax-use-case to open the modal) is
//   documented from source but NOT exercised in the discovery pass.
// - ARCHIVE: UNVERIFIED at runtime (site list was empty in fresh runtime, so
//   app-hax-site-bar cards did not render). Selectors below are from source
//   analysis of app-hax-site-bar.js. Task agents MUST verify against a live
//   site card before relying on them.

// The fixed site name used by every E2E site operation. Each run boots an
// isolated temp runtime so this name never collides with real work.
const FIXED_SITE_NAME = 'HAXSITEAUTOMATEDTESTING'

// Walk a chain of [shadowRoot]querySelector calls to pierce shadow DOM.
// selectorChain: array of strings, each applied to the current root's
// shadowRoot (or the document for the first element).
// Returns the element handle or null.
async function deepQuery(page, selectorChain) {
  if (!Array.isArray(selectorChain) || selectorChain.length === 0) {
    return null
  }
  const handle = await page.evaluateHandle((chain) => {
    let root = document
    for (let i = 0; i < chain.length; i++) {
      const sel = chain[i]
      let el = null
      if (i === 0) {
        el = root.querySelector(sel)
      } else {
        if (!root || !root.shadowRoot) {
          return null
        }
        el = root.shadowRoot.querySelector(sel)
      }
      if (!el) {
        return null
      }
      root = el
    }
    return root
  }, selectorChain)
  const element = handle.asElement()
  if (!element) {
    await handle.dispose()
    return null
  }
  return element
}

// Query all matches across a shadow-DOM chain (last selector is the "all" one).
async function deepQueryAll(page, selectorChain) {
  if (!Array.isArray(selectorChain) || selectorChain.length === 0) {
    return []
  }
  const handles = await page.evaluateHandle((chain) => {
    let root = document
    for (let i = 0; i < chain.length - 1; i++) {
      const sel = chain[i]
      let el = null
      if (i === 0) {
        el = root.querySelector(sel)
      } else {
        if (!root || !root.shadowRoot) {
          return []
        }
        el = root.shadowRoot.querySelector(sel)
      }
      if (!el) {
        return []
      }
      root = el
    }
    if (!root || !root.shadowRoot) {
      // last selector may be on document or a shadowRoot depending on chain length
      if (chain.length === 1) {
        return Array.prototype.slice.call(root.querySelectorAll(chain[0]))
      }
      return []
    }
    const lastRoot = chain.length === 1 ? root : root.shadowRoot
    return Array.prototype.slice.call(lastRoot.querySelectorAll(chain[chain.length - 1]))
  }, selectorChain)
  const props = await handles.getProperties()
  const elements = []
  for (const key of Object.keys(props)) {
    const el = props[key]
    if (el && typeof el.asElement === 'function') {
      const element = el.asElement()
      if (element) {
        elements.push(element)
      }
    }
  }
  await handles.dispose()
  return elements
}

const selectors = {
  // --- LOGIN (VERIFIED at runtime) ---------------------------------------
  // Confirmed by discovery: document > simple-modal[opened] > app-hax-site-login
  // (shadowRoot) contains rpg-character, p#errorText, input#username, then after
  // clicking "Next" shows input#password + "Login" button.
  // Login API: POST /system/api/v1/session/login -> {status:200, jwt:"..."}.
  login: {
    // The host app element on the page.
    appHax: 'app-hax',
    // The login custom element (slotted into simple-modal content).
    siteLogin: 'app-hax-site-login',
    // The modal that wraps the login element.
    loginModal: 'simple-modal',
    // Shadow-DOM chain to the login host element: document -> simple-modal -> app-hax-site-login
    siteLoginChain: ['simple-modal', 'app-hax-site-login'],
    // Input fields inside app-hax-site-login shadowRoot.
    // VERIFIED: input#username type=text placeholder="Enter your username" aria-label="Username"
    usernameInput: '#username',
    // VERIFIED: input#password type=password placeholder="Enter your password" aria-label="Password"
    // (only present AFTER clicking Next)
    passwordInput: '#password',
    // Buttons inside app-hax-site-login shadowRoot. The form is two-step:
    // step 1 shows the "Next" button, step 2 shows the "Login" button.
    // VERIFIED: button text="Next" (step 1), button text="Login" (step 2).
    nextButton: 'button', // text "Next" — disambiguate by text content
    loginButton: 'button', // text "Login" — disambiguate by text content
    // Full shadow-DOM chains (document -> simple-modal -> app-hax-site-login -> field)
    // VERIFIED at runtime.
    usernameInputChain: ['simple-modal', 'app-hax-site-login', '#username'],
    passwordInputChain: ['simple-modal', 'app-hax-site-login', '#password'],
    // Error text element inside the login shadowRoot.
    // VERIFIED: p#errorText text="Enter User name" (initial)
    errorText: '#errorText',
    // Global event fired on successful login (detail: true).
    loggedInEvent: 'jwt-logged-in',
    // Global event fired to attempt login (detail: { username, password }).
    loginAttemptEvent: 'jwt-login-login',
  },

  // --- DASHBOARD (VERIFIED at runtime) ------------------------------------
  // Confirmed by discovery: document > app-hax (shadowRoot) > app-hax-use-case-filter
  // (shadowRoot) contains #returnToSection > app-hax-search-results,
  // #create-site-heading (text "Create New Site"), app-hax-site-creation-modal,
  // and 43 app-hax-use-case cards.
  // sites API: GET /system/api/v1/sites -> {status:200, data:{items:[...]}}.
  dashboard: {
    // Shadow-DOM chain to the use-case-filter host: document -> app-hax -> app-hax-use-case-filter
    // VERIFIED at runtime.
    useCaseFilterChain: ['app-hax', 'app-hax-use-case-filter'],
    // The "Return to..." section containing the site list.
    // VERIFIED: section#returnToSection.returnTo[aria-labelledby="return-to-heading"]
    returnToSection: '#returnToSection',
    // The search-results host element that renders site cards.
    // VERIFIED: app-hax-search-results inside #returnToSection.
    searchResults: 'app-hax-search-results',
    // Full chain to the site-list container.
    siteListChain: [
      'app-hax',
      'app-hax-use-case-filter',
      '#returnToSection',
      'app-hax-search-results',
    ],
    // Individual site card element (rendered by app-hax-search-results).
    // UNVERIFIED at runtime — site list was empty in fresh runtime so no
    // app-hax-site-bar cards rendered. app-hax-site-bar is the card component
    // per source (app-hax-site-bar.js). Task agents should verify after creating
    // a site.
    siteCard: 'app-hax-site-bar',
    // The heading slot inside a site card.
    siteCardHeadingSlot: 'slot[name="heading"]',
  },

  // --- CREATE SITE (modal VERIFIED, trigger UNVERIFIED) ------------------
  // Confirmed by discovery: app-hax-site-creation-modal exists in use-case-filter
  // shadowDOM with open=false. Its shadowRoot contains web-dialog,
  // button.close-button, input#siteName.form-input, button.button.button-primary
  // (text "Create Site"), button.button.button-secondary (text "Cancel").
  // The modal uses web-dialog (NOT simple-modal). To OPEN the modal, the
  // discovery pass did NOT click a use-case card.
  create: {
    // The create-site modal host element.
    // VERIFIED: app-hax-site-creation-modal in use-case-filter shadowDOM.
    siteCreationModal: 'app-hax-site-creation-modal',
    // Shadow-DOM chain to the modal: document -> app-hax -> app-hax-use-case-filter -> app-hax-site-creation-modal
    // VERIFIED at runtime.
    siteCreationModalChain: [
      'app-hax',
      'app-hax-use-case-filter',
      'app-hax-site-creation-modal',
    ],
    // The site name input inside the modal shadowRoot.
    // VERIFIED: input#siteName.form-input type=text placeholder="Enter your site name..."
    siteNameInput: '#siteName',
    // Full chain to the site name input.
    siteNameInputChain: [
      'app-hax',
      'app-hax-use-case-filter',
      'app-hax-site-creation-modal',
      '#siteName',
    ],
    // "Create Site" button (class .button.button-primary) inside modal shadowRoot.
    // VERIFIED: button.button.button-primary text="Create Site" (with simple-icon-lite icons:add-circle)
    createSiteButton: '.button.button-primary',
    // VERIFIED at runtime.
    createSiteButtonChain: [
      'app-hax',
      'app-hax-use-case-filter',
      'app-hax-site-creation-modal',
      '.button.button-primary',
    ],
    // "Cancel" button (class .button.button-secondary).
    // VERIFIED: button.button.button-secondary text="Cancel"
    cancelButton: '.button.button-secondary',
    // The use-case cards that trigger the create flow. Clicking one with
    // showContinue fires `continue-action`.
    // UNVERIFIED at runtime — discovery did not click a card. Per source
    // (app-hax-use-case-filter.js continueAction()), the blank-site path is
    // index -1. Task agents should trigger the modal by calling
    // continueAction(-1) on the use-case-filter element handle, OR by clicking
    // an app-hax-use-case card's continue button.
    useCaseCard: 'app-hax-use-case',
    // The "Create New Site" section heading (id create-site-heading).
    // VERIFIED: h2#create-site-heading text="Create New Site"
    createSiteHeading: '#create-site-heading',
    // Entry point: to open the create modal programmatically, call
    // continueAction(-1) on the use-case-filter element, OR set
    // store.createSiteSteps = true. See app-hax-use-case-filter.js
    // continueAction().
  },

  // --- ARCHIVE SITE (UNVERIFIED at runtime — source-only) -----------------
  // Archive flow (from app-hax-site-bar.js source): on a site card
  // (app-hax-site-bar), click the "more options" button
  // (simple-icon-button-lite with icon lrn:more-vert), which opens a
  // simple-context-menu. Click the "Archive" simple-toolbar-button, which calls
  // siteOperation("archiveSite",...) creating an `app-hax-confirmation-modal`
  // (appended to document.body) with .button-confirm ("Confirm") and
  // .button-cancel ("Cancel") buttons. Clicking Confirm calls confirmOperation()
  // which calls the archive API.
  //
  // ALL SELECTORS BELOW ARE UNVERIFIED — the discovery pass had an empty site
  // list so no app-hax-site-bar cards rendered. Task agents MUST verify these
  // against a live site card (create a site first, then re-query).
  archive: {
    // The more-options trigger button on a site card (inside app-hax-site-bar shadowRoot).
    // UNVERIFIED — the moreOptionsId is dynamic (`moreOptions-${n}`); select by
    // icon attribute instead. Per source: simple-icon-button-lite icon="lrn:more-vert"
    moreOptionsButton: 'simple-icon-button-lite[icon="lrn:more-vert"]',
    // The context menu that opens.
    // UNVERIFIED — per source: simple-context-menu title="Options"
    contextMenu: 'simple-context-menu',
    // The archive menu item (simple-toolbar-button with label "Archive").
    // UNVERIFIED — per source: simple-toolbar-button label="Archive" icon="archive".
    // Select by label text "Archive" (there are multiple simple-toolbar-button siblings).
    archiveMenuItem: 'simple-toolbar-button',
    // The confirmation modal that appears after clicking Archive.
    // UNVERIFIED — per source: app-hax-confirmation-modal appended to document.body.
    confirmationModal: 'app-hax-confirmation-modal',
    // Confirm button inside confirmation modal shadowRoot.
    // UNVERIFIED — per source (app-hax-confirmation-modal.js): button.button.button-confirm
    confirmButton: '.button.button-confirm',
    // Cancel button inside confirmation modal shadowRoot.
    // UNVERIFIED — per source: button.button.button-cancel (autofocus)
    cancelButton: '.button.button-cancel',
    // Full chain to confirm button (confirmation modal is appended to document.body).
    // UNVERIFIED — document > app-hax-confirmation-modal (shadowRoot) > .button.button-confirm
    confirmButtonChain: ['app-hax-confirmation-modal', '.button.button-confirm'],
    cancelButtonChain: ['app-hax-confirmation-modal', '.button.button-cancel'],
  },

  // --- SITE EDITOR (VERIFIED at runtime by .discovery-editor.cjs) ---------
  // The site editor is reached by navigating to the site URL
  // (runtime.baseUrl + '/_sites/haxsiteautomatedtesting/'). The editor chrome
  // is `haxcms-site-editor-ui` at document root (has shadowRoot). The content
  // body lives inside `haxcms-site-editor` (NO shadowRoot — createRenderRoot
  // returns this) which renders `<h-a-x id="hax">` in light DOM; h-a-x has a
  // shadowRoot containing `<hax-body>` (the content-editable region).
  //
  // The #editbutton toggles edit mode: label="Edit" icon="icons:create" when
  // viewing, label="Save" icon="icons:save" when editing. Clicking it in edit
  // mode fires the global `haxcms-save-node` event which the site-editor
  // handles by calling @site/updateContentByIdOrSlug
  // (PATCH /x/api/v1/content/:idOrSlug).
  editor: {
    // The editor chrome host element at document root.
    // VERIFIED: document > haxcms-site-editor-ui (shadowRoot)
    editorUi: 'haxcms-site-editor-ui',
    // The edit/save toggle button inside editor-ui shadowRoot.
    // VERIFIED: simple-toolbar-button#editbutton, label="Edit • Ctrl⇧E" → "Save • Ctrl⇧S"
    editButton: '#editbutton',
    // The cancel button (exits edit mode without saving).
    // VERIFIED: simple-toolbar-button#cancelbutton, label="Cancel • Ctrl⇧/"
    cancelButton: '#cancelbutton',
    // The outline-editor opener button (disabled while in edit mode).
    // VERIFIED: simple-toolbar-button#outlinebutton, label="Outline • Ctrl⇧2", icon="hax:site-map"
    outlineButton: '#outlinebutton',
    // The add-page button (opens a super-daemon menu; disabled in edit mode).
    // VERIFIED: haxcms-button-add#addpagebutton, label="Add page • Ctrl⇧1", icon="hax:add-page"
    addPageButton: '#addpagebutton',
    // The site-settings button (disabled in edit mode).
    // VERIFIED: simple-toolbar-button#manifestbtn, label="Site Settings • Ctrl⇧3"
    manifestButton: '#manifestbtn',
    // The editor host element (renders inside the active theme).
    // VERIFIED: haxcms-site-editor found via recursive shadow-DOM walk; it has
    // NO shadowRoot (createRenderRoot returns this). Its parent is a <section>.
    // Tests must locate it with a recursive walk (see deepFindRecursive pattern
    // in .discovery-editor.cjs) — deepQuery cannot reach it because it is
    // nested inside theme shadow DOM at an unknown depth.
    editorHost: 'haxcms-site-editor',
    // The HAX editor instance (light DOM child of haxcms-site-editor).
    // VERIFIED: h-a-x#hax inside haxcms-site-editor; has shadowRoot.
    haxInstance: '#hax',
    // The content-editable body inside #hax shadowRoot.
    // VERIFIED: hax-body inside h-a-x#hax shadowRoot. contenteditable=true in
    // edit mode. Has importContent(htmlString) method to load HTML content.
    // To type: call body.importContent(html) then dispatch 'input' event.
    contentBody: 'hax-body',
    // Full chain to the content body. NOTE: haxcms-site-editor is NOT at a
    // fixed shadow-DOM depth (it renders inside the active theme). Tests must
    // use a recursive shadow walk to find haxcms-site-editor, then traverse
    // light DOM to #hax, then shadowRoot to hax-body. This chain is NOT
    // usable with deepQuery directly — it is documented for reference.
    // UNVERIFIED as a deepQuery chain — use recursive walk instead.
    contentBodyChain: ['haxcms-site-editor', '#hax', 'hax-body'],
    // Global event fired to save the active page (site-editor listens).
    // VERIFIED from source (haxcms-site-editor.js connectedCallback).
    saveNodeEvent: 'haxcms-save-node',
    // Global event fired to create a new page (site-editor listens).
    // VERIFIED from source + runtime: dispatching this event with
    // detail.values = {node:{title,location,contents}, order, parent} triggers
    // POST /x/api/v1/items (createNode).
    createNodeEvent: 'haxcms-create-node',
    // Global event fired to delete a page (site-editor listens).
    // VERIFIED from source + runtime: dispatching this event with
    // detail.item = {id} triggers DELETE /x/api/v1/items/:idOrSlug (deleteNode).
    deleteNodeEvent: 'haxcms-delete-node',
  },

  // --- EXPORT / DOWNLOAD SITE (VERIFIED menu, UNVERIFIED confirm modal) ---
  // The export (download) flow reuses the more-vert menu on a site card
  // (app-hax-site-bar). The menu items are: Copy, Download, Create Template,
  // Archive (and conditionally User Access). Clicking "Download" calls
  // downloadSite() which calls siteOperation("downloadSite",...) — this
  // creates an app-hax-confirmation-modal on document.body (same pattern as
  // archive). Clicking Confirm calls confirmOperation() which calls the
  // download API and triggers a browser file download via an <a> click.
  //
  // MENU ITEMS + DOWNLOAD LABEL: VERIFIED at runtime.
  // CONFIRMATION MODAL: UNVERIFIED at runtime — in the discovery pass the
  // app-hax-confirmation-modal did not appear on document.body after clicking
  // Download. This is likely the same store-manifest timing issue documented
  // in the archive flow (siteOperation looks up the site in store.manifest.items
  // by siteId). Task agents should reuse the archive test's fallback pattern:
  // if the confirmation modal does not appear after clicking the Download menu
  // item, call cardHandle.evaluate((el) => el.downloadSite()) directly, or
  // call cardHandle.evaluate((el) => el.siteOperation("downloadSite","Download","file-download")) directly.
  export: {
    // The more-options trigger button on a site card (same as archive).
    // VERIFIED: simple-icon-button-lite[icon="lrn:more-vert"] in app-hax-site-bar shadowRoot.
    moreOptionsButton: 'simple-icon-button-lite[icon="lrn:more-vert"]',
    // The context menu that opens.
    // VERIFIED: simple-context-menu title="Options" in app-hax-site-bar shadowRoot.
    contextMenu: 'simple-context-menu',
    // ALL menu items in the more-vert context menu (in order).
    // VERIFIED at runtime: Copy, Download, Create Template, Archive.
    // (User Access appears only if AppHaxAPI.supportsCall("haxiamAddUserAccess")).
    menuItems: {
      copy: 'Copy',
      download: 'Download',
      createTemplate: 'Create Template',
      archive: 'Archive',
      userAccess: 'User Access',
    },
    // The download menu item (simple-toolbar-button label="Download").
    // VERIFIED: simple-toolbar-button label="Download" icon="file-download".
    // Select by label text "Download" among simple-toolbar-button siblings.
    downloadMenuItem: 'simple-toolbar-button',
    // The confirmation modal (same component as archive).
    // UNVERIFIED for download — see note above. Same as archive confirmation modal.
    confirmationModal: 'app-hax-confirmation-modal',
    confirmButton: '.button.button-confirm',
    cancelButton: '.button.button-cancel',
    confirmButtonChain: ['app-hax-confirmation-modal', '.button.button-confirm'],
    cancelButtonChain: ['app-hax-confirmation-modal', '.button.button-cancel'],
    // The card method to call directly if the menu click doesn't open the modal.
    // UNVERIFIED at runtime (fallback) — call via cardHandle.evaluate((el) => el.downloadSite())
    downloadSiteMethod: 'downloadSite',
  },

  // --- OUTLINE EDITOR (VERIFIED at runtime by .discovery-editor.cjs) -------
  // The outline editor (page management) is opened by clicking #outlinebutton
  // in the editor chrome (haxcms-site-editor-ui shadowRoot). It opens a
  // simple-modal containing haxcms-outline-editor-dialog as a light-DOM
  // (slotted) child. The dialog shadowRoot contains outline-designer#outline
  // and two .hax-modal-btn buttons ("Save Outline" and "Import From File").
  // The outline-designer has its own shadowRoot with an "Add page"
  // simple-toolbar-button.
  //
  // Adding a page via the outline-designer fires haxcms-save-outline with the
  // updated items array, which the site-editor handles by calling
  // @site/updateSiteOutline (PATCH /x/api/v1/site/outline). Individual
  // createNode/deleteNode can also be triggered directly via the
  // haxcms-create-node / haxcms-delete-node global events (see selectors.editor).
  outline: {
    // The outline editor dialog host element (slotted into simple-modal).
    // VERIFIED: haxcms-outline-editor-dialog is a light-DOM child of simple-modal
    // (NOT in simple-modal's shadowRoot — same pattern as login).
    // To reach it: document.querySelector('simple-modal').querySelector('haxcms-outline-editor-dialog')
    outlineDialog: 'haxcms-outline-editor-dialog',
    // The outline-designer element inside the dialog shadowRoot.
    // VERIFIED: outline-designer#outline inside haxcms-outline-editor-dialog shadowRoot.
    outlineDesigner: '#outline',
    // The "Save Outline" button inside the dialog shadowRoot.
    // VERIFIED: button.hax-modal-btn text="Save Outline"
    saveOutlineButton: '.hax-modal-btn',
    // The "Import From File" button inside the dialog shadowRoot.
    // VERIFIED: button.hax-modal-btn.import text="Import From File"
    importButton: '.hax-modal-btn.import',
    // The "Add page" button inside outline-designer shadowRoot.
    // VERIFIED: simple-toolbar-button label="Add page" found via recursive
    // shadow search inside outline-designer. No fixed id — select by label text.
    addPageButton: 'simple-toolbar-button',
    // Global event fired by the dialog when Save Outline is clicked.
    // VERIFIED from source (haxcms-outline-editor-dialog.js _saveTap):
    // dispatches haxcms-save-outline with detail = items array.
    saveOutlineEvent: 'haxcms-save-outline',
    // The outline dialog is in simple-modal light DOM, so deepQuery cannot
    // reach it (same as login). Use: document.querySelector('simple-modal').querySelector('haxcms-outline-editor-dialog')
    // then operate on its shadowRoot.
    // VERIFIED at runtime: dialog.shadowRoot.querySelector('#outline') + '.hax-modal-btn'
  },

  // --- API PATHS (canonical v1 system + site API) -------------------------
  api: {
    // system API (dashboard / site lifecycle)
    login: '/system/api/v1/session/login',
    createSite: '/system/api/v1/sites',
    listSites: '/system/api/v1/sites',
    archiveSite: '/system/api/v1/sites/:siteName/archive',
    downloadSite: '/system/api/v1/sites/:siteName/download',
    connectionSettings: '/system/api/v1/session/connection-settings',
    // site API (per-site, under /x/api/v1)
    // saveNode: PATCH /x/api/v1/content/:idOrSlug → {status:200, data:page}
    saveNode: '/x/api/v1/content/:idOrSlug',
    // createNode: POST /x/api/v1/items → {status:200, data:item}
    createNode: '/x/api/v1/items',
    // deleteNode: DELETE /x/api/v1/items/:idOrSlug → {status:200, data:item}
    deleteNode: '/x/api/v1/items/:idOrSlug',
    // saveOutline: PATCH /x/api/v1/site/outline → {status:200, data:...}
    saveOutline: '/x/api/v1/site/outline',
  },
}

module.exports = {
  selectors,
  FIXED_SITE_NAME,
  deepQuery,
  deepQueryAll,
}
