import { test } from 'node:test';
import assert from 'node:assert/strict';
import { chromium } from 'playwright';
import { startServer, FORM_ID } from './fixtures/server.mjs';
import { parseJSON } from '../../ui/schema-json.js';

function row(index = 1) {
  return { id: `44444444-4444-4444-8444-${String(index).padStart(12, '0')}`, formId: FORM_ID,
    schemaVersion: index % 2 ? 1 : 2, submittedAt: '2026-08-30T12:34:56.123456Z', status: 'accepted', requestId: 'req_acceptance',
    redactedPaths: ['/secret'], schema: { type: 'object', properties: { amount: { type: 'integer', title: 'Accepted amount' }, secret: { type: 'string' } } },
    data: parseJSON('{"amount":9007199254740993,"decimal":0.1234567890123456789,"note":"<img src=x onerror=alert(1)>"}') };
}
async function fixture(t, { role = 'owner', rows = [row()] } = {}) {
  const server = await startServer({ populated: true, role }); server.data.submissions = rows;
  const browser = await chromium.launch(), context = await browser.newContext({ viewport: { width: 1280, height: 900 } }), page = await context.newPage();
  page.setDefaultTimeout(10000); const errors = [];
  page.on('pageerror', () => errors.push('browser runtime error'));
  t.after(async () => { await browser.close(); await server.close(); assert.deepEqual(errors, []); });
  await page.goto(server.url + '/app');
  await page.getByRole('button', { name: /Contact us/ }).click();
  await page.getByText('Viewing saved version 1', { exact: false }).waitFor();
  return { page, data: server.data, url: server.url };
}

test('submission detail uses accepted schema, safe text, precise values and memory-only state', async t => {
  const { page, url, data } = await fixture(t);
  data.deliveries = [{ id: '55555555-5555-4555-8555-555555555555', submissionId: row().id,
    status: 'delivered', attemptCount: 2, updatedAt: '2026-08-30T12:40:00Z', deliveredAt: '2026-08-30T12:40:00Z', lastHttpStatus: 204 }];
  await page.getByRole('button', { name: 'Load submissions', exact: true }).click();
  await page.locator('#submission-list button').first().click();
  await page.getByRole('heading', { name: 'Submission detail', exact: true }).waitFor();
  assert.match(await page.locator('#submission-values').textContent(), /Accepted amount/);
  assert.match(await page.locator('#submission-values').textContent(), /9007199254740993/);
  assert.match(await page.locator('#submission-values').textContent(), /0\.1234567890123456789/);
  assert.match(await page.locator('#submission-values').textContent(), /<img src=x onerror=alert\(1\)>/);
  assert.equal(await page.locator('#submission-values img, #submission-values script').count(), 0);
  assert.match(await page.locator('#submission-redactions').textContent(), /\/secret/);
  await page.getByText('delivered · 2 attempts', { exact: false }).waitFor();
  assert.match(await page.locator('#submission-deliveries').textContent(), /HTTP 204/);
  assert.equal(page.url(), url + '/app');
  assert.deepEqual(await page.evaluate(() => [localStorage.length, sessionStorage.length]), [0, 0]);
  await page.setViewportSize({ width: 390, height: 844 });
  assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth), false);
  const other = await page.context().newPage(); await other.goto('about:blank'); await other.bringToFront();
  await page.bringToFront();
  // Headless visibility differs across engines; pagehide is also a required boundary.
  await page.evaluate(() => window.dispatchEvent(new Event('pagehide')));
  assert.equal(await page.locator('#submission-detail').isHidden(), true);
  assert.equal(await page.locator('#submission-values').textContent(), '');
});

test('pagination and applied filters drive both verified export formats without exposing credentials', async t => {
  const { page, data } = await fixture(t, { rows: Array.from({ length: 26 }, (_, index) => row(index + 1)) });
  await page.getByRole('button', { name: 'Load submissions', exact: true }).click();
  await page.getByText('25 submissions · page 1', { exact: true }).waitFor();
  await page.getByRole('button', { name: 'Next submissions', exact: true }).click();
  await page.getByText('1 submissions · page 2', { exact: true }).waitFor();
  await page.getByRole('button', { name: 'Previous submissions', exact: true }).click();
  await page.getByText('25 submissions · page 1', { exact: true }).waitFor();
  await page.getByRole('textbox', { name: 'Accepted schema version', exact: true }).fill('1');
  await page.getByRole('button', { name: 'Apply submission filters', exact: true }).click();
  await page.getByText('13 submissions · page 1', { exact: true }).waitFor();
  for (const format of ['json', 'csv']) {
    const ready = page.waitForEvent('download');
    await page.getByRole('button', { name: `Export ${format.toUpperCase()}`, exact: true }).click();
    const download = await ready;
    assert.match(download.suggestedFilename(), new RegExp(`^goformx-submissions-[a-f0-9-]+\\.${format}$`));
    const chunks = []; for await (const chunk of await download.createReadStream()) chunks.push(chunk);
    const text = Buffer.concat(chunks).toString();
    assert.ok(text.includes(row().id));
    if (format === 'json') assert.match(text, /9007199254740993/);
    const request = data.requests.filter(request => request.path.endsWith('/export')).at(-1);
    assert.deepEqual(request.body, { schemaVersion: 1, format });
    assert.equal(request.query, ''); assert.equal(request.headers['x-xsrf-token'], 'test-csrf');
    assert.equal(request.headers.authorization, undefined);
  }
});

test('empty, loading, denied and incomplete export states clear data and never offer partial files', async t => {
  const { page, data } = await fixture(t, { rows: [] });
  data.delaySubmissions = 500;
  await page.getByRole('button', { name: 'Load submissions', exact: true }).click();
  await page.getByText('Loading submissions…', { exact: true }).waitFor();
  await page.getByText('No submissions match these filters.', { exact: true }).waitFor();
  data.delaySubmissions = 0; data.submissions = [row()];
  await page.getByRole('button', { name: 'Load submissions', exact: true }).click();
  await page.locator('#submission-list button').first().click();
  await page.getByRole('heading', { name: 'Submission detail', exact: true }).waitFor();
  const downloads = []; page.on('download', () => downloads.push(true));
  await page.route('**/submissions/export', route => route.fulfill({ status: 200, contentType: 'application/json', body: '{"data":[]}' }));
  await page.getByRole('button', { name: 'Export JSON', exact: true }).click();
  await page.getByText('Download integrity could not be verified. No file was offered.', { exact: true }).waitFor();
  assert.deepEqual(downloads, []); assert.equal(await page.locator('#submission-detail').isHidden(), true);
  data.failure = { method: 'GET', path: `/api/control-plane/forms/${FORM_ID}/submissions`, status: 403, body: { error: { message: 'private-canary' } } };
  await page.getByRole('button', { name: 'Load submissions', exact: true }).click();
  await page.getByText('Submission access denied. Check your current workspace membership.', { exact: true }).waitFor();
  assert.equal(await page.locator('#submission-error').textContent().then(text => text.includes('private-canary')), false);
});

test('member cannot browse or export submissions despite reading the form', async t => {
  const { page, data } = await fixture(t, { role: 'member' });
  assert.equal(await page.getByRole('button', { name: 'Load submissions', exact: true }).isDisabled(), true);
  assert.equal(await page.getByRole('button', { name: 'Export JSON', exact: true }).isDisabled(), true);
  assert.equal(data.requests.some(request => request.path.includes('/submissions')), false);
});
