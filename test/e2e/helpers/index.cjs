'use strict'

// Convenience re-exports for the E2E helper suite.
// Usage: const { setupE2ERuntime, launchBrowser, runA11y } = require('./test/e2e/helpers')

const harness = require('./harness.cjs')
const browser = require('./browser.cjs')
const axe = require('./axe.cjs')
const visual = require('./visual.cjs')
const selectors = require('./selectors.cjs')

module.exports = {
  // harness
  setupE2ERuntime: harness.setupE2ERuntime,
  teardownE2ERuntime: harness.teardownE2ERuntime,
  E2E_USER_NAME: harness.E2E_USER_NAME,
  E2E_USER_PASSWORD: harness.E2E_USER_PASSWORD,
  // browser
  launchBrowser: browser.launchBrowser,
  newPage: browser.newPage,
  createResponseCollector: browser.createResponseCollector,
  DEFAULT_VIEWPORT: browser.DEFAULT_VIEWPORT,
  // axe
  runA11y: axe.runA11y,
  // visual
  captureScreenshot: visual.captureScreenshot,
  compareBaseline: visual.compareBaseline,
  baselinePath: visual.baselinePath,
  BASELINE_DIR: visual.BASELINE_DIR,
  // selectors
  selectors: selectors.selectors,
  FIXED_SITE_NAME: selectors.FIXED_SITE_NAME,
  deepQuery: selectors.deepQuery,
  deepQueryAll: selectors.deepQueryAll,
}
