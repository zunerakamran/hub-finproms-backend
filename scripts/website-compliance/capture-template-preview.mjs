/**
 * Captures a full-page screenshot of a template preview URL.
 * Usage: node capture-template-preview.mjs <url> <output-path>
 */
import puppeteer from 'puppeteer'
import { mkdirSync } from 'fs'
import { dirname } from 'path'

const url = process.argv[2]
const outputPath = process.argv[3]

if (!url || !outputPath) {
  console.error('Usage: node capture-template-preview.mjs <url> <output-path>')
  process.exit(1)
}

mkdirSync(dirname(outputPath), { recursive: true })

const browser = await puppeteer.launch({
  headless: 'new',
  args: ['--no-sandbox', '--disable-setuid-sandbox', '--disable-dev-shm-usage'],
})

try {
  const page = await browser.newPage()
  await page.setViewport({ width: 1280, height: 800, deviceScaleFactor: 1 })
  await page.goto(url, { waitUntil: 'networkidle2', timeout: 90000 })
  await new Promise((resolve) => setTimeout(resolve, 2000))
  await page.screenshot({ path: outputPath, fullPage: true, type: 'jpeg', quality: 85 })
  process.stdout.write(outputPath)
} finally {
  await browser.close()
}
