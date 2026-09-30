import test from 'node:test';
import assert from 'node:assert/strict';
import { mkdir, readFile } from 'node:fs/promises';
import { join } from 'node:path';
import { chromium } from 'playwright';
import { startPublicServer } from './fixtures/public-server.mjs';

test('public website: actual anonymous routes, metadata, links, mobile, zoom and keyboard', async t => {
  const fixture = await startPublicServer();
  t.after(fixture.stop);
  const browser = await chromium.launch();
  t.after(() => browser.close());
  const page = await browser.newPage({ viewport: { width: 1440, height: 1000 } });
  const errors = [], outside = [];
  page.on('pageerror', error => errors.push(error.message));
  page.on('request', request => { if (!request.url().startsWith(fixture.origin)) outside.push(request.url()); });
  const routes = ['/', '/getting-started', '/compatibility', '/docs'];
  const evidence = process.env.WEBSITE_EVIDENCE_DIR;
  if (evidence) await mkdir(evidence, { recursive: true });
  for (const route of routes) {
    const response = await page.goto(fixture.origin + route);
    assert.equal(response.status(), 200);
    assert.equal(await page.locator('h1').count(), 1);
    assert.equal(await page.locator('link[rel="canonical"]').getAttribute('href'), fixture.origin + route);
    assert.ok(await page.locator('meta[name="description"]').getAttribute('content'));
    assert.equal(await page.locator('meta[property="og:image"]').getAttribute('content'), fixture.origin + '/assets/website-og.png');
    assert.ok((await page.content()).includes('Developer preview'));
    const localLinks = await page.locator('a[href^="/"]').evaluateAll(links => [...new Set(links.map(link => link.getAttribute('href').split('#')[0]))]);
    for (const path of localLinks) assert.equal((await page.request.get(fixture.origin + path)).status(), 200, path);
    if (evidence) await page.screenshot({ path: join(evidence, `${route.replaceAll('/', '') || 'home'}-desktop.png`), fullPage: true });
    await page.evaluate(() => { document.documentElement.style.zoom = '2'; });
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), true, `${route}: 200% scale`);
    await page.setViewportSize({ width: 375, height: 812 });
    await page.goto(fixture.origin + route);
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), true, `${route}: mobile`);
    if (evidence) await page.screenshot({ path: join(evidence, `${route.replaceAll('/', '') || 'home'}-mobile.png`), fullPage: true });
    await page.setViewportSize({ width: 1440, height: 1000 });
  }
  await page.setViewportSize({ width: 375, height: 812 });
  await page.goto(fixture.origin);
  const menu = page.getByRole('button', { name: 'Menu', exact: true });
  await menu.focus(); await page.keyboard.press('Enter');
  assert.equal(await menu.getAttribute('aria-expanded'), 'true');
  assert.equal(await page.getByRole('navigation', { name: 'Primary navigation' }).isVisible(), true);
  await page.keyboard.press('Tab');
  assert.equal(await page.evaluate(() => document.activeElement.textContent.trim()), 'How it works');
  await page.keyboard.press('Escape');
  assert.equal(await menu.getAttribute('aria-expanded'), 'false');
  assert.equal(await menu.evaluate(element => element === document.activeElement), true);
  await page.getByRole('link', { name: 'Skip to content' }).focus(); await page.keyboard.press('Enter');
  assert.equal(await page.evaluate(() => document.activeElement.id), 'main');
  await page.emulateMedia({ reducedMotion: 'reduce' });
  assert.equal(await page.locator('.button').last().evaluate(element => getComputedStyle(element).animationName), 'none');
  for (const route of ['/login', '/register']) {
    assert.equal((await page.goto(fixture.origin + route)).status(), 200);
    assert.equal(await page.locator('form').count(), 1);
    assert.equal(await page.locator('link[href="/assets/website.css"]').count(), 0);
    assert.equal(await page.locator('body.public-site').count(), 0);
  }
  assert.equal((await page.request.get(fixture.origin + '/not-a-page')).status(), 404);
  const sitemap = await page.request.get(fixture.origin + '/sitemap.xml');
  assert.equal(sitemap.status(), 200); assert.ok((await sitemap.text()).includes('/getting-started'));
  assert.equal((await page.request.get(fixture.origin + '/assets/website-og.png')).status(), 200);
  assert.deepEqual(errors, []); assert.deepEqual(outside, []);
});

test('public website: no-JavaScript navigation and measured palette contrast', async t => {
  const fixture = await startPublicServer(); t.after(fixture.stop);
  const browser = await chromium.launch(); t.after(() => browser.close());
  const page = await browser.newPage({ javaScriptEnabled: false, viewport: { width: 375, height: 812 } });
  await page.goto(fixture.origin + '/docs');
  assert.equal(await page.getByRole('navigation', { name: 'Primary navigation' }).isVisible(), true);
  assert.equal(await page.getByRole('link', { name: 'Get started', exact: true }).isVisible(), true);
  const luminance = hex => { const rgb = hex.match(/../g).map(value => parseInt(value, 16) / 255).map(value => value <= .04045 ? value / 12.92 : ((value + .055) / 1.055) ** 2.4); return rgb[0] * .2126 + rgb[1] * .7152 + rgb[2] * .0722; };
  for (const [first, second] of [['242424', 'ffffff'], ['595959', 'f4f4f2'], ['c43d0b', 'ffffff'], ['9d2e07', 'ffffff'], ['242424', 'f15a24'], ['c43d0b', 'f4f4f2']]) {
    const values = [luminance(first), luminance(second)].sort((a,b) => b-a);
    assert.ok((values[0] + .05) / (values[1] + .05) >= 4.5, `${first}/${second}`);
  }
  const css = await readFile(new URL('../../public/assets/website.css', import.meta.url), 'utf8');
  assert.ok(css.includes(':focus-visible')); assert.ok(css.includes('prefers-reduced-motion'));
});
