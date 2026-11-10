#!/usr/bin/env node
'use strict'

// Dependency license check for haxcms-php (haxtheweb/issues#3262).
// Policy lives in .github/license-policy.json. Used by
// .github/workflows/dependency-license.yml in two modes:
//
//   review  PR gate. Reads the dependency-review-action `dependency-changes`
//           output (JSON, from the CHANGES env var) and checks every ADDED
//           Composer / npm dependency, direct or transitive.
//             node license-check.cjs review --policy <json> --composer-lock <lock>
//
//   sweep   Weekly. Checks the whole installed tree (composer.lock plus an
//           npm node_modules folder) and that the project itself is still
//           Apache-2.0 (LICENSE file + manifest license fields).
//             node license-check.cjs sweep --policy <json> --composer-lock <lock>
//               [--node-modules <dir>] --license-file <file>
//               --project-manifest <json> [--project-manifest <json> ...]
//
// Findings go to the job summary and as error annotations; exit code 1 when
// anything fails. No network writes, no issues or PRs are created.
//
// Style: CommonJS, no optional chaining (same rules as the rest of the repo).

const fs = require('fs')
const path = require('path')
const { execFileSync } = require('child_process')

const STATUS_RANK = { allowed: 0, excepted: 1, review: 2, other: 3, unknown: 4, denied: 5 }
const STATUS_TEXT = {
  allowed: 'allowed',
  excepted: 'accepted exception',
  review: 'needs review (LGPL/MPL are not automatically compatible with Apache-2.0)',
  other: 'needs review (license is not on the allowlist)',
  unknown: 'no license / unknown license',
  denied: 'incompatible with Apache-2.0',
}
// paths whose manifests are vendored copies or build output, not our deps
const IGNORED_MANIFEST_PREFIXES = [
  'system/backend/php/vendor/',
  'build/',
  'archived/',
  'node_modules/',
]

function parseArgs(argv) {
  const args = { _: [], 'project-manifest': [] }
  for (let i = 0; i < argv.length; i++) {
    const a = argv[i]
    if (a.indexOf('--') === 0) {
      const key = a.slice(2)
      const val = argv[i + 1]
      i++
      if (Array.isArray(args[key])) {
        args[key].push(val)
      } else {
        args[key] = val
      }
    } else {
      args._.push(a)
    }
  }
  return args
}

function readJson(file) {
  return JSON.parse(fs.readFileSync(file, 'utf8'))
}

function loadPolicy(file) {
  const p = readJson(file)
  return {
    allow: new Set((p.allow || []).map((l) => l.toLowerCase())),
    allowPatterns: (p.allowPatterns || []).map((r) => new RegExp(r, 'i')),
    reviewPatterns: (p.reviewPatterns || []).map((r) => new RegExp(r, 'i')),
    denyPatterns: (p.denyPatterns || []).map((r) => new RegExp(r, 'i')),
    exceptions: p.exceptions || [],
  }
}

// npm package.json "license" can be a string, an object, or a legacy array
function licenseFromPackageJson(pkg) {
  let lic = pkg.license
  if (!lic && Array.isArray(pkg.licenses)) {
    lic = pkg.licenses
      .map((l) => (typeof l === 'string' ? l : l && l.type))
      .filter(Boolean)
      .join(' OR ')
  }
  if (lic && typeof lic === 'object') {
    lic = lic.type
  }
  return typeof lic === 'string' ? lic.trim() : ''
}

// composer.lock "license" is an array of alternatives
function licenseFromComposer(pkg) {
  if (Array.isArray(pkg.license)) {
    return pkg.license.join(' OR ')
  }
  return typeof pkg.license === 'string' ? pkg.license : ''
}

function classifyTerm(term, policy) {
  // "Apache-2.0 WITH LLVM-exception" -> judge the base license
  const t = term.split(/\s+WITH\s+/i)[0].trim()
  if (!t) return 'unknown'
  if (policy.denyPatterns.some((r) => r.test(t))) return 'denied'
  if (policy.reviewPatterns.some((r) => r.test(t))) return 'review'
  if (policy.allow.has(t.toLowerCase())) return 'allowed'
  if (policy.allowPatterns.some((r) => r.test(t))) return 'allowed'
  return 'other'
}

// Small SPDX expression evaluator: OR picks the best alternative, AND the
// worst term. Parentheses are flattened, which is conservative for mixed
// expressions such as "(MIT AND GPL-2.0) OR BSD-3-Clause".
function classifyLicense(expr, policy) {
  const raw = (expr || '').trim()
  if (
    !raw ||
    raw === 'UNLICENSED' ||
    /^(NOASSERTION|NONE|UNKNOWN|proprietary)$/i.test(raw) ||
    /^SEE LICEN[CS]E IN/i.test(raw)
  ) {
    return 'unknown'
  }
  const flat = raw.replace(/[()]/g, ' ')
  if (/Commons[- ]?Clause/i.test(flat)) return 'denied'
  let best = null
  flat.split(/\s+OR\s+|\s*\/\s*/i).forEach((alt) => {
    let worst = 'allowed'
    alt.split(/\s+AND\s+/i).forEach((term) => {
      const s = classifyTerm(term, policy)
      if (STATUS_RANK[s] > STATUS_RANK[worst]) worst = s
    })
    if (best === null || STATUS_RANK[worst] < STATUS_RANK[best]) best = worst
  })
  return best || 'unknown'
}

function findException(policy, ecosystem, name, license) {
  return policy.exceptions.find(
    (e) => e.ecosystem === ecosystem && e.name === name && e.license === license
  )
}

function evaluate(policy, dep) {
  let status = classifyLicense(dep.license, policy)
  let note = ''
  if (status !== 'allowed') {
    const ex = findException(policy, dep.ecosystem, dep.name, dep.license)
    if (ex) {
      status = 'excepted'
      note = ex.reason || ''
    }
  }
  return Object.assign({}, dep, { status, note })
}

function composerLockIndex(lockFile) {
  const index = new Map()
  if (!lockFile || !fs.existsSync(lockFile)) return index
  const lock = readJson(lockFile)
  ;['packages', 'packages-dev'].forEach((key) => {
    ;(lock[key] || []).forEach((pkg) => {
      index.set(pkg.name.toLowerCase(), {
        ecosystem: 'composer',
        name: pkg.name,
        version: pkg.version,
        license: licenseFromComposer(pkg),
        scope: key === 'packages' ? 'runtime' : 'development',
        source: lockFile,
      })
    })
  })
  return index
}

function walkNodeModules(dir, out) {
  if (!fs.existsSync(dir)) return
  fs.readdirSync(dir).forEach((entry) => {
    if (entry.charAt(0) === '.') return
    const full = path.join(dir, entry)
    if (entry.charAt(0) === '@') {
      walkNodeModules(full, out)
      return
    }
    const pj = path.join(full, 'package.json')
    if (fs.existsSync(pj)) {
      try {
        const pkg = readJson(pj)
        if (pkg.name && pkg.version) {
          const key = pkg.name + '@' + pkg.version
          if (!out.has(key)) {
            out.set(key, {
              ecosystem: 'npm',
              name: pkg.name,
              version: pkg.version,
              license: licenseFromPackageJson(pkg),
              scope: 'installed',
              source: 'node_modules',
            })
          }
        }
      } catch (e) {
        // unreadable package.json: ignore, it is not a real package
      }
    }
    walkNodeModules(path.join(full, 'node_modules'), out)
  })
}

// npm registry lookup for packages the dependency graph has no license for
function npmRegistryLicense(name, version) {
  if (!/^(@[a-z0-9._~-]+\/)?[a-z0-9._~-]+$/i.test(name)) return ''
  const spec = version ? name + '@' + version : name
  try {
    const out = execFileSync('npm', ['view', spec, 'license', '--json'], {
      encoding: 'utf8',
      stdio: ['ignore', 'pipe', 'ignore'],
      timeout: 60000,
    })
    let parsed = JSON.parse(out || '""')
    if (Array.isArray(parsed)) parsed = parsed[parsed.length - 1]
    return typeof parsed === 'string' ? parsed : ''
  } catch (e) {
    return ''
  }
}

function mdEscape(s) {
  return String(s === undefined || s === null || s === '' ? '-' : s)
    .replace(/\\/g, '\\\\')
    .replace(/\|/g, '\\|')
    .replace(/[\r\n]+/g, ' ')
}

// workflow-command data escaping (% CR LF), so package metadata cannot
// inject extra workflow commands into the log
function cmdEscape(s) {
  return String(s).replace(/%/g, '%25').replace(/\r/g, '%0D').replace(/\n/g, '%0A')
}

function report(title, results, extraProblems) {
  const problems = results.filter(
    (r) => r.status !== 'allowed' && r.status !== 'excepted'
  )
  const excepted = results.filter((r) => r.status === 'excepted')
  const lines = ['## ' + title, '']
  lines.push(
    'Checked ' +
      results.length +
      ' dependencies against `.github/license-policy.json` (project license: Apache-2.0).',
    ''
  )
  if (problems.length === 0 && extraProblems.length === 0) {
    lines.push('No license problems found.', '')
  }
  if (problems.length > 0) {
    lines.push('### Problems', '')
    lines.push('| Ecosystem | Package | Version | License | Result | Where |')
    lines.push('| --- | --- | --- | --- | --- | --- |')
    problems.forEach((r) => {
      lines.push(
        '| ' +
          [r.ecosystem, r.name, r.version, r.license, STATUS_TEXT[r.status], r.source]
            .map(mdEscape)
            .join(' | ') +
          ' |'
      )
    })
    lines.push('')
    lines.push(
      'To accept a reviewed package, a maintainer adds it to `exceptions` in `.github/license-policy.json` with a reason.',
      ''
    )
  }
  if (extraProblems.length > 0) {
    lines.push('### Project license', '')
    extraProblems.forEach((p) => lines.push('- ' + p))
    lines.push('')
  }
  if (excepted.length > 0) {
    lines.push('### Accepted exceptions', '')
    excepted.forEach((r) => {
      lines.push(
        '- ' + mdEscape(r.name) + ' ' + mdEscape(r.version) + ' (' + mdEscape(r.license) + '): ' + mdEscape(r.note)
      )
    })
    lines.push('')
  }
  const md = lines.join('\n')
  if (process.env.GITHUB_STEP_SUMMARY) {
    fs.appendFileSync(process.env.GITHUB_STEP_SUMMARY, md + '\n')
  }
  console.log(md)
  problems.forEach((r) => {
    console.log(
      '::error title=Dependency license::' +
        cmdEscape(
          r.ecosystem + ' ' + r.name + '@' + r.version + ' (' + (r.license || 'no license') + '): ' + STATUS_TEXT[r.status]
        )
    )
  })
  extraProblems.forEach((p) => {
    console.log('::error title=Project license::' + cmdEscape(p))
  })
  return problems.length + extraProblems.length
}

function runReview(args, policy) {
  const raw = process.env.CHANGES || '[]'
  let changes
  try {
    changes = JSON.parse(raw)
  } catch (e) {
    console.log('::error title=Dependency license::could not parse dependency-review output')
    return 1
  }
  const lockIndex = composerLockIndex(args['composer-lock'])
  const results = []
  changes.forEach((c) => {
    if (c.change_type !== 'added') return
    if (c.ecosystem !== 'composer' && c.ecosystem !== 'npm') return
    const manifest = c.manifest || ''
    if (IGNORED_MANIFEST_PREFIXES.some((p) => manifest.indexOf(p) === 0)) return
    let license = c.license || ''
    if (c.ecosystem === 'composer') {
      // composer.lock carries the license packagist publishes; the dependency
      // graph often has none for Composer packages, so the lock file wins.
      const fromLock = lockIndex.get(String(c.name).toLowerCase())
      if (fromLock && fromLock.license) license = fromLock.license
    } else if (!license || license === 'NOASSERTION') {
      license = npmRegistryLicense(c.name, c.version) || license
    }
    results.push(
      evaluate(policy, {
        ecosystem: c.ecosystem,
        name: c.name,
        version: c.version,
        license: license,
        scope: c.scope,
        source: manifest,
      })
    )
  })
  return report('Dependency license gate (new dependencies in this PR)', results, [])
}

function runSweep(args, policy) {
  const results = []
  composerLockIndex(args['composer-lock']).forEach((dep) => {
    results.push(evaluate(policy, dep))
  })
  if (args['node-modules']) {
    const npm = new Map()
    walkNodeModules(args['node-modules'], npm)
    npm.forEach((dep) => results.push(evaluate(policy, dep)))
  }
  const extra = []
  const licenseFile = args['license-file']
  if (!licenseFile || !fs.existsSync(licenseFile)) {
    extra.push('License file ' + (licenseFile || '(none given)') + ' is missing.')
  } else {
    const text = fs.readFileSync(licenseFile, 'utf8')
    if (!/Apache License/.test(text) || !/Version 2\.0/.test(text)) {
      extra.push(licenseFile + ' no longer contains the Apache License, Version 2.0 text.')
    }
  }
  args['project-manifest'].forEach((m) => {
    try {
      const lic = licenseFromPackageJson(readJson(m))
      if (lic !== 'Apache-2.0') {
        extra.push(m + ' declares license "' + lic + '", expected "Apache-2.0".')
      }
    } catch (e) {
      extra.push(m + ' could not be read.')
    }
  })
  return report('Weekly dependency license sweep (full tree)', results, extra)
}

function main() {
  const args = parseArgs(process.argv.slice(2))
  const mode = args._[0]
  if (!args.policy) {
    console.error('--policy is required')
    process.exit(2)
  }
  const policy = loadPolicy(args.policy)
  let failures
  if (mode === 'review') {
    failures = runReview(args, policy)
  } else if (mode === 'sweep') {
    failures = runSweep(args, policy)
  } else {
    console.error('usage: license-check.cjs review|sweep --policy <file> ...')
    process.exit(2)
  }
  process.exit(failures > 0 ? 1 : 0)
}

if (require.main === module) {
  main()
}

module.exports = { classifyLicense, loadPolicy }
