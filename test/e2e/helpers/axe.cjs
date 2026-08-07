'use strict'

// Accessibility helper: injects axe-core into a puppeteer page and runs axe
// against a scoped selector (or document). Returns violations filtered to
// critical/serious by default. Tests decide how to assert — this helper only
// reports, it never throws on its own.

const axeCoreSource = require('axe-core')

async function runA11y(page, scopeSelector, opts) {
  opts = opts || {}
  const impactFilter = opts.impact || ['critical', 'serious']
  // axe-core ships a pre-built browser bundle as a string via axeCoreSource.
  const axeScript = axeCoreSource.source || axeCoreSource
  if (typeof axeScript !== 'string' || axeScript === '') {
    throw new Error('Unable to load axe-core browser bundle source')
  }
  await page.evaluate((src) => {
    // eslint-disable-next-line no-eval
    window.eval(src)
  }, axeScript)

  const results = await page.evaluate((selector, impact) => {
    /* global axe */
    const context = selector && typeof selector === 'string' && selector !== ''
      ? selector
      : document
    return axe.run(context, {
      runOnly: {
        type: 'tag',
        values: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'],
      },
    }).then((r) => {
      return r
    })
  }, scopeSelector, impactFilter)

  const violations = (results && Array.isArray(results.violations))
    ? results.violations
    : []
  function filterByImpact(list, impacts) {
    return list.filter((v) => {
      const imp = v && v.impact ? v.impact : null
      return impacts.indexOf(imp) !== -1
    })
  }
  const critical = filterByImpact(violations, ['critical'])
  const serious = filterByImpact(violations, ['serious'])
  return {
    violations,
    critical,
    serious,
    inapplicable: results.inapplicable,
    incomplete: results.incomplete,
    passes: results.passes,
  }
}

module.exports = {
  runA11y,
}
