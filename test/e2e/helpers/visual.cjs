'use strict'

// Visual regression helper. captureScreenshot grabs a full-page PNG buffer.
// compareBaseline diffs the current buffer against a stored baseline using
// pixelmatch + pngjs + sharp. Diffs WARN but NEVER throw — first run writes
// the baseline, HAXCMS_E2E_UPDATE_SCREENSHOTS=1 regenerates baselines.
//
// Mask dynamic regions (timestamps, avatars) via simple blank-rectangle fills
// using sharp before diffing.

const fs = require('fs-extra')
const path = require('path')
const { PNG } = require('pngjs')
const pixelmatch = require('pixelmatch').default || require('pixelmatch')
const sharp = require('sharp')

const REPO_ROOT = path.resolve(__dirname, '..', '..', '..')
const BASELINE_DIR = path.join(REPO_ROOT, 'test', 'e2e', '__screenshots__')
const DEFAULT_DIFF_THRESHOLD = 0.01 // 1% diff triggers a WARN notice
const DEFAULT_PIXEL_THRESHOLD = 0.1 // pixelmatch threshold

function baselinePath(name) {
  const safeName = String(name || '').replace(/[^a-zA-Z0-9._-]+/g, '-')
  return path.join(BASELINE_DIR, `${safeName}.png`)
}

async function captureScreenshot(page, name, opts) {
  opts = opts || {}
  const buffer = await page.screenshot({
    fullPage: opts.fullPage !== false,
    type: 'png',
  })
  if (opts.save !== false) {
    // Save the current shot alongside baselines for manual inspection.
    const currentPath = path.join(BASELINE_DIR, `${String(name).replace(/[^a-zA-Z0-9._-]+/g, '-')}.current.png`)
    await fs.ensureDir(path.dirname(currentPath))
    await fs.writeFile(currentPath, buffer)
  }
  return buffer
}

// Apply blank-rectangle masks to dynamic regions (timestamps, avatars, etc.)
// regions: array of { x, y, width, height } in CSS pixels.
async function applyMasks(imageBuffer, regions) {
  if (!regions || regions.length === 0) {
    return imageBuffer
  }
  let pipeline = sharp(imageBuffer)
  const metadata = await pipeline.metadata()
  const width = metadata.width
  const height = metadata.height
  // Build a composite of black rectangles over the image.
  const compositeOps = []
  for (let i = 0; i < regions.length; i++) {
    const r = regions[i]
    if (!r) continue
    const x = Math.max(0, Math.floor(r.x))
    const y = Math.max(0, Math.floor(r.y))
    const w = Math.min(width - x, Math.floor(r.width))
    const h = Math.min(height - y, Math.floor(r.height))
    if (w <= 0 || h <= 0) continue
    const svg = Buffer.from(
      `<svg xmlns="http://www.w3.org/2000/svg" width="${w}" height="${h}"><rect width="${w}" height="${h}" fill="#000"/></svg>`,
    )
    compositeOps.push({ input: svg, top: y, left: x })
  }
  if (compositeOps.length === 0) {
    return imageBuffer
  }
  return sharp(imageBuffer).composite(compositeOps).png().toBuffer()
}

function pngFromBuffer(buffer) {
  return new Promise((resolve, reject) => {
    const png = new PNG()
    png.parse(buffer, (err, data) => {
      if (err) {
        reject(err)
        return
      }
      resolve(data)
    })
  })
}

// Compare a current screenshot buffer against the stored baseline.
// Returns { diffPixels, totalPixels, diffPercent, baselineExists }.
// WARN on diff > threshold; NEVER throw.
async function compareBaseline(name, currentBuffer, opts) {
  opts = opts || {}
  const threshold = opts.threshold != null ? opts.threshold : DEFAULT_PIXEL_THRESHOLD
  const diffNoticeThreshold =
    opts.diffThreshold != null ? opts.diffThreshold : DEFAULT_DIFF_THRESHOLD
  const regions = opts.maskRegions || []
  const bp = baselinePath(name)
  const baselineExists = fs.pathExistsSync(bp)
  const updateBaselines =
    process.env.HAXCMS_E2E_UPDATE_SCREENSHOTS === '1' ||
    process.env.HAXCMS_E2E_UPDATE_SCREENSHOTS === 'true'

  // Mask dynamic regions before any comparison / baseline writing.
  const maskedCurrent = await applyMasks(currentBuffer, regions)

  if (!baselineExists || updateBaselines) {
    await fs.ensureDir(path.dirname(bp))
    await fs.writeFile(bp, maskedCurrent)
    // eslint-disable-next-line no-console
    console.warn(
      `[visual] baseline ${updateBaselines ? 'regenerated' : 'created'} for "${name}" at ${bp}`,
    )
    return {
      diffPixels: 0,
      totalPixels: 0,
      diffPercent: 0,
      baselineExists: false,
      baselineUpdated: true,
    }
  }

  const baselineBuffer = await fs.readFile(bp)
  // Normalize both images to the same dimensions before pixelmatch so that
  // different content heights (fullPage screenshots vary by rendered content)
  // do not cause a "sizes do not match" crash. Resize both to the min dims.
  const baselineMeta = await sharp(baselineBuffer).metadata()
  const currentMeta = await sharp(maskedCurrent).metadata()
  const width = Math.min(baselineMeta.width, currentMeta.width)
  const height = Math.min(baselineMeta.height, currentMeta.height)
  const baselinePng = await pngFromBuffer(
    await sharp(baselineBuffer).resize(width, height).png().toBuffer(),
  )
  const currentPng = await pngFromBuffer(
    await sharp(maskedCurrent).resize(width, height).png().toBuffer(),
  )
  const diffPng = new PNG({ width, height })
  const diffPixels = pixelmatch(
    baselinePng.data,
    currentPng.data,
    diffPng.data,
    width,
    height,
    { threshold },
  )
  const totalPixels = width * height
  const diffPercent = totalPixels > 0 ? diffPixels / totalPixels : 0

  if (diffPercent > diffNoticeThreshold) {
    // WARN, do NOT throw.
    const diffPath = path.join(BASELINE_DIR, `${String(name).replace(/[^a-zA-Z0-9._-]+/g, '-')}.diff.png`)
    await fs.ensureDir(path.dirname(diffPath))
    await fs.writeFile(diffPath, PNG.sync.write(diffPng))
    // eslint-disable-next-line no-console
    console.warn(
      `[visual] potential visual regression for "${name}": diffPixels=${diffPixels}, totalPixels=${totalPixels}, diffPercent=${(diffPercent * 100).toFixed(3)}% (threshold ${(diffNoticeThreshold * 100).toFixed(2)}%). Diff written to ${diffPath}`,
    )
  }

  return {
    diffPixels,
    totalPixels,
    diffPercent,
    baselineExists: true,
    baselineUpdated: false,
  }
}

module.exports = {
  captureScreenshot,
  compareBaseline,
  baselinePath,
  BASELINE_DIR,
}
