import { readFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright';

const svg = await readFile(new URL('../public/assets/website-og.svg', import.meta.url), 'utf8');
const browser = await chromium.launch();
try {
  const page = await browser.newPage({ viewport: { width: 1200, height: 630 }, deviceScaleFactor: 1 });
  await page.setContent(`<html><body style="margin:0">${svg}</body></html>`);
  await page.screenshot({ path: fileURLToPath(new URL('../public/assets/website-og.png', import.meta.url)) });
} finally { await browser.close(); }
