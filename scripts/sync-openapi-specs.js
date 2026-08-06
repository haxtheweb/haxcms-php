#!/usr/bin/env node
/**
 * sync-openapi-specs.js
 *
 * Copy the canonical HAXcms OpenAPI specs from the haxcms-nodejs repository
 * into the haxcms-php repository so both backends share one byte-identical
 * contract. The NodeJS specs (src/openapi/*-spec.yaml) are the source of truth.
 *
 * Usage (run from the haxcms-php repo root, or anywhere — the script resolves
 * paths from its own location):
 *
 *   node scripts/sync-openapi-specs.js
 *   HAXCMS_NODEJS_ROOT=/path/to/haxcms-nodejs node scripts/sync-openapi-specs.js
 *
 * This is a manually invoked utility. It is NOT wired into any build step, the
 * ubiquity script, or any CI run. Run it after the NodeJS specs change and you
 * want the PHP backends to serve the updated contract.
 */
const fs = require('fs')
const path = require('path')
const crypto = require('crypto')

// Resolve the haxcms-php repo root from this script's location:
// scripts/sync-openapi-specs.js -> repo root two levels up.
const phpRepoRoot = path.resolve(__dirname, '..')

// Resolve the haxcms-nodejs repo root. Default to the sibling directory
// (the standard haxtheweb layout: haxcms-nodejs and haxcms-php sit next to each
// other). Override with HAXCMS_NODEJS_ROOT for non-standard layouts.
const nodejsRepoRoot = process.env.HAXCMS_NODEJS_ROOT
  ? path.resolve(process.env.HAXCMS_NODEJS_ROOT)
  : path.resolve(phpRepoRoot, '..', 'haxcms-nodejs')

// Source (canonical) and destination spec pairs.
const specPairs = [
  {
    name: 'site-spec',
    source: path.join(nodejsRepoRoot, 'src', 'openapi', 'site-spec.yaml'),
    dest: path.join(
      phpRepoRoot,
      'system',
      'backend',
      'php',
      'lib',
      'siteRoutes',
      'openapi',
      'site-spec.yaml',
    ),
  },
  {
    name: 'system-spec',
    source: path.join(nodejsRepoRoot, 'src', 'openapi', 'system-spec.yaml'),
    dest: path.join(
      phpRepoRoot,
      'system',
      'backend',
      'php',
      'lib',
      'systemRoutes',
      'openapi',
      'system-spec.yaml',
    ),
  },
]

function md5(filePath) {
  return crypto
    .createHash('md5')
    .update(fs.readFileSync(filePath))
    .digest('hex')
}

function fail(message) {
  console.error('sync-openapi-specs: ' + message)
  process.exitCode = 1
}

let allOk = true

for (let i = 0; i < specPairs.length; i++) {
  const pair = specPairs[i]
  if (!fs.existsSync(pair.source)) {
    fail(pair.name + ': source not found at ' + pair.source)
    allOk = false
    continue
  }
  if (!fs.existsSync(path.dirname(pair.dest))) {
    fail(pair.name + ': destination directory not found at ' + path.dirname(pair.dest))
    allOk = false
    continue
  }
  const sourceHashBefore = md5(pair.source)
  fs.copyFileSync(pair.source, pair.dest)
  const destHashAfter = md5(pair.dest)
  const match = sourceHashBefore === destHashAfter
  console.log(
    pair.name +
      ': ' +
      (match ? 'OK' : 'MISMATCH') +
      ' (' +
      sourceHashBefore +
      ')' +
      '\n  src: ' +
      pair.source +
      '\n  dst: ' +
      pair.dest,
  )
  if (!match) {
    allOk = false
  }
}

if (allOk) {
  console.log('sync-openapi-specs: all specs copied byte-identical.')
} else {
  console.error('sync-openapi-specs: one or more specs failed to sync.')
}
