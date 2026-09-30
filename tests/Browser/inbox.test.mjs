import { test } from 'node:test';
import assert from 'node:assert/strict';
import { chromium } from 'playwright';
import { startServer, FORM_ID, ORG_ID } from './fixtures/server.mjs';
import { parseJSON } from '../../ui/schema-json.js';

const SECOND_FORM = '33333333-3333-4333-8333-333333333333';
const FIRST_SITE = '55555555-5555-4555-8555-555555555555';
const SECOND_SITE = '66666666-6666-4666-8666-666666666666';
function submission(id, formId, siteId, formName, formTitle, schemaVersion) {
  return { id, formId, siteId, formName, formTitle, schemaVersion, status: 'accepted',
    submittedAt: '2026-09-29T12:00:00Z', requestId: 'req_acceptance', redactedPaths: ['/private'],
    schema: { type: 'object', properties: { message: { title: 'Original message', type: 'string' } } },
    data: parseJSON('{"message":"<img src=x onerror=alert(1)>"}') };
}
async function fixture(t, role = 'owner') {
  const server = await startServer({ populated: true, role });
  server.data.sites = [
    { id: FIRST_SITE, organizationId: ORG_ID, name: 'First site', origin: 'https://first.example', createdAt: '2026-09-29T00:00:00Z', updatedAt: '2026-09-29T00:00:00Z' },
    { id: SECOND_SITE, organizationId: ORG_ID, name: 'Second site', origin: 'https://second.example', createdAt: '2026-09-29T00:00:00Z', updatedAt: '2026-09-29T00:00:00Z' },
  ];
  server.data.submissions = [
    submission('77777777-7777-4777-8777-777777777777', FORM_ID, FIRST_SITE, 'first-contact', 'First contact', 1),
    submission('88888888-8888-4888-8888-888888888888', SECOND_FORM, SECOND_SITE, 'second-contact', 'Second contact', 2),
  ];
  server.data.forms.push({ id: SECOND_FORM, organizationId: ORG_ID, name: 'second-contact', title: 'Second contact',
    description: '', publicKey: 'gfpk_second', allowedOrigins: ['https://second.example'], status: 'published', currentVersion: 3 });
  server.data.versions.push({ formId: SECOND_FORM, version: 3, state: 'published',
    schema: { type: 'object', properties: { message: { title: 'Current message', type: 'string' } } } });
  server.data.workspaceSubmissions = server.data.submissions.map(({ schema, ...row }) => row);
  const browser = await chromium.launch(); const page = await browser.newPage(); page.setDefaultTimeout(10000);
  const errors = []; page.on('pageerror', () => errors.push('browser runtime error'));
  t.after(async () => { await browser.close(); await server.close(); assert.deepEqual(errors, []); });
  await page.goto(server.url + '/app');
  return { page, data: server.data };
}

test('workspace inbox combines two sites, filters by site and shows exact accepted detail safely', async t => {
  const { page, data } = await fixture(t);
  await page.getByRole('button', { name: 'All submissions' }).click();
  await page.getByText('2 submissions · page 1', { exact: true }).waitFor();
  assert.match(await page.locator('#inbox-list').textContent(), /First contact/);
  assert.match(await page.locator('#inbox-list').textContent(), /Second contact/);
  await page.locator('#inbox-site').selectOption(SECOND_SITE);
  await page.getByRole('button', { name: 'Apply filters', exact: true }).click();
  await page.getByText('1 submission · page 1', { exact: true }).waitFor();
  assert.doesNotMatch(await page.locator('#inbox-list').textContent(), /First contact/);
  await page.locator('#inbox-list button').click();
  await page.getByRole('heading', { name: 'Submission detail' }).waitFor();
  assert.match(await page.locator('#inbox-values').textContent(), /Original message/);
  assert.doesNotMatch(await page.locator('#inbox-values').textContent(), /Current message/);
  assert.match(await page.locator('#inbox-values').textContent(), /<img src=x onerror=alert\(1\)>/);
  assert.equal(await page.locator('#inbox-values img').count(), 0);
  assert.match(await page.locator('#inbox-redactions').textContent(), /private/);
  assert.equal(data.requests.some(request => request.path === '/api/control-plane/submissions' && request.query.includes(`siteId=${SECOND_SITE}`)), true);
  data.sites = data.sites.filter(site => site.id !== SECOND_SITE);
  await page.locator('#inbox-refresh').click();
  await page.getByText('2 submissions · page 1', { exact: true }).waitFor();
  assert.equal(await page.locator('#inbox-site').inputValue(), '');
  assert.match(await page.locator('#inbox-list').textContent(), /First contact/);
  await page.locator('#inbox-list button').first().click();
  await page.locator('#inbox-open-form').click();
  await page.getByRole('heading', { name: 'Contact us', exact: true }).waitFor();
  assert.equal(await page.locator('#workspace-inbox').isHidden(), true);
  assert.deepEqual(await page.evaluate(() => [localStorage.length, sessionStorage.length]), [0, 0]);
  await page.evaluate(() => window.dispatchEvent(new Event('pagehide')));
  assert.equal(await page.locator('#inbox-values').textContent(), '');
});

test('inbox clears received content after role loss and does not fetch for members', async t => {
  const { page, data } = await fixture(t);
  await page.getByRole('button', { name: 'All submissions' }).click();
  await page.getByText('2 submissions · page 1', { exact: true }).waitFor();
  data.role = 'member';
  await page.getByRole('button', { name: 'Refresh', exact: true }).last().click();
  await page.getByText('Workspace access changed. Reload before reviewing submissions.', { exact: true }).waitFor();
  assert.equal(await page.locator('#inbox-list').textContent(), '');
  assert.equal(await page.locator('#inbox-refresh').isDisabled(), true);
  const reads = data.requests.filter(request => request.path === '/api/control-plane/submissions').length;
  await page.locator('#open-inbox').click();
  assert.equal(data.requests.filter(request => request.path === '/api/control-plane/submissions').length, reads);
});
