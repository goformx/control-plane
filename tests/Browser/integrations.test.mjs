import { test } from 'node:test';
import assert from 'node:assert/strict';
import { chromium } from 'playwright';
import { startServer, FORM_ID } from './fixtures/server.mjs';

async function fixture(t) {
  const server = await startServer({ populated: true });
  const browser = await chromium.launch();
  const page = await browser.newPage();
  page.setDefaultTimeout(5000);
  const errors = []; page.on('pageerror', error => errors.push(error.message));
  t.after(async () => { await browser.close(); await server.close(); assert.deepEqual(errors, []); });
  await page.goto(server.url + '/app');
  await page.getByRole('heading', { name: 'Test workspace' }).waitFor();
  return { page, data: server.data };
}
async function prepareToken(page) {
  await page.getByRole('button', { name: 'Manage API access' }).click();
  await page.getByText('No token metadata returned.', { exact: true }).waitFor();
  assert.equal(await page.locator('#token-scopes input:checked').count(), 0);
  await page.locator('#token-name').fill('Review fixture');
  await page.getByLabel('forms:read', { exact: true }).check();
  await page.locator('#token-warning').check();
}

test('one-time token reveal needs no metadata follow-up and clears on form change', async t => {
  const { page } = await fixture(t); const requests = [];
  const token = 'gfst_' + 'a'.repeat(43); // Synthetic, never usable against a real API.
  await page.route('**/api/control-plane/service-tokens', async route => {
    requests.push(route.request());
    await route.fulfill({ status: route.request().method() === 'POST' ? 201 : 200, json: { data: route.request().method() === 'POST' ? { token } : [] } });
  });
  await prepareToken(page);
  await page.getByRole('button', { name: 'Create scoped token' }).click();
  await page.getByRole('heading', { name: 'Save this token now' }).waitFor();
  assert.equal(await page.locator('#issued-token').inputValue(), token);
  assert.equal(requests.length, 2, 'Reveal must not depend on a metadata reload');
  assert.deepEqual(requests[1].postDataJSON().scopes, ['forms:read']);
  assert.equal(requests[1].headers()['x-xsrf-token'], 'test-csrf');
  assert.equal(requests[1].headers().authorization, undefined);
  await page.getByRole('button', { name: /Contact us/ }).click();
  await page.getByText('Viewing saved version 1', { exact: false }).waitFor();
  assert.equal(await page.locator('#issued-token').inputValue(), '');
  assert.equal(await page.locator('#token-reveal').isVisible(), false);
});

test('uncertain issuance blocks retry; refreshed membership blocks integration access', async t => {
  const { page, data } = await fixture(t); let mutations = 0;
  await page.route('**/api/control-plane/service-tokens', async route => {
    if (route.request().method() === 'POST') { mutations++; await route.fulfill({ status: 502, json: { error: {} } }); }
    else await route.fulfill({ json: { data: [] } });
  });
  await prepareToken(page);
  await page.getByRole('button', { name: 'Create scoped token' }).click();
  await page.locator('#integration-uncertain').waitFor();
  assert.equal(await page.getByRole('button', { name: 'Create scoped token' }).isDisabled(), true);
  assert.equal(mutations, 1);
  await page.route(`**/api/control-plane/forms/${FORM_ID}/webhook`, route => route.fulfill({ json: { data: {
    formId: FORM_ID, enabled: false, origin: 'https://receiver.example', updatedAt: '2026-08-30T12:00:00Z',
  } } }));
  await page.getByRole('button', { name: /Contact us/ }).click();
  await page.getByText('Viewing saved version 1', { exact: false }).waitFor();
  await page.getByRole('button', { name: 'Load webhook', exact: true }).click();
  await page.getByText('Paused for future submissions', { exact: false }).waitFor();
  assert.equal(await page.locator('#pause-webhook').textContent(), 'Resume future deliveries');
  data.role = 'member';
  await page.getByRole('button', { name: 'Reload token metadata' }).click();
  await page.getByText('member · Server-authorized workspace').waitFor();
  assert.equal(await page.locator('#tokens-panel').isVisible(), false);
  assert.equal(await page.locator('#manage-tokens').isDisabled(), true);
  assert.equal(await page.locator('#webhook-state').textContent(), 'Load this form’s webhook to see its current state.');
  assert.equal(await page.locator('#pause-webhook').textContent(), 'Pause future deliveries');
  data.role = 'owner';
  await page.getByRole('button', { name: 'Refresh', exact: true }).click();
  await page.getByText('owner · Server-authorized workspace').waitFor();
  page.once('dialog', dialog => dialog.accept());
  await page.getByRole('button', { name: 'I have reconciled the change' }).click();
  assert.equal(await page.locator('#pause-webhook').isDisabled(), true, 'Purged webhook state must not revive with authorization');
});

test('proxied assertion failure is a definite no-op and does not expire the PHP session', async t => {
  const { page } = await fixture(t);
  await page.route('**/api/control-plane/service-tokens', route => route.fulfill(route.request().method() === 'GET' ?
    { json: { data: [] } } : { status: 502, json: { error: { code: 'data_plane_authentication_failed' } } }));
  await prepareToken(page);
  await page.getByRole('button', { name: 'Create scoped token' }).click();
  await page.getByText('data-plane authentication is unavailable', { exact: false }).waitFor();
  assert.equal(await page.locator('#integration-uncertain').isVisible(), false);
  assert.equal(await page.locator('#token-fields').isDisabled(), false);
  assert.equal(await page.locator('#new-form').isDisabled(), false);
  assert.equal(await page.getByRole('link', { name: 'Sign in again' }).isVisible(), false);
});

test('delivery-history assertion failure does not latch a valid workspace read-only', async t => {
  const { page } = await fixture(t);
  await page.route(`**/api/control-plane/forms/${FORM_ID}/deliveries`, route => route.fulfill({
    status: 502, json: { error: { code: 'data_plane_authentication_failed' } },
  }));
  await page.getByRole('button', { name: /Contact us/ }).click();
  await page.getByText('Viewing saved version 1', { exact: false }).waitFor();
  await page.getByRole('button', { name: 'Load delivery history' }).click();
  await page.getByText('Data-plane authentication is unavailable.', { exact: false }).waitFor();
  await page.getByRole('button', { name: 'Refresh', exact: true }).click();
  await page.waitForFunction(() => !document.getElementById('refresh').disabled);
  assert.equal(await page.locator('#new-form').isDisabled(), false);
  assert.equal(await page.locator('#read-only').isVisible(), false);
  assert.equal(await page.getByRole('link', { name: 'Sign in again' }).isVisible(), false);
});

test('definite mutation rejections do not require uncertain-outcome acknowledgement', async t => {
  const { page } = await fixture(t); let tokenStatus = 503;
  await page.route('**/api/control-plane/service-tokens', async route => {
    if (route.request().method() === 'GET') { await route.fulfill({ json: { data: [] } }); return; }
    const code = tokenStatus === 503 ? 'service_unavailable' : tokenStatus === 403 ? 'data_plane_access_denied' : 'conflict';
    await route.fulfill({ status: tokenStatus, json: { error: { code } } });
  });
  await prepareToken(page);
  await page.getByRole('button', { name: 'Create scoped token' }).click();
  await page.getByText('service-token management is not available', { exact: false }).waitFor();
  assert.equal(await page.locator('#integration-uncertain').isVisible(), false);
  assert.equal(await page.locator('#token-fields').isDisabled(), false);

  tokenStatus = 409;
  await page.locator('#token-name').fill('Conflict fixture');
  await page.getByLabel('forms:read', { exact: true }).check();
  await page.locator('#token-warning').check();
  await page.getByRole('button', { name: 'Create scoped token' }).click();
  await page.getByText('changed concurrently', { exact: false }).waitFor();
  assert.equal(await page.locator('#integration-uncertain').isVisible(), false);

  tokenStatus = 403;
  await page.getByRole('button', { name: 'Create scoped token' }).click();
  await page.getByText('data plane denied this integration operation', { exact: false }).waitFor();
  assert.equal(await page.locator('#integration-uncertain').isVisible(), false);

  let webhookStatus = 503;
  await page.route(`**/api/control-plane/forms/${FORM_ID}/webhook`, async route => {
    if (route.request().method() === 'GET') { await route.fulfill({ status: 404, json: { error: { code: 'not_found' } } }); return; }
    await route.fulfill({ status: webhookStatus, json: { error: { code: webhookStatus === 503 ? 'webhooks_disabled' : 'precondition_failed' } } });
  });
  await page.getByRole('button', { name: /Contact us/ }).click();
  await page.getByText('Viewing saved version 1', { exact: false }).waitFor();
  await page.getByRole('button', { name: 'Load webhook', exact: true }).click();
  await page.getByText('No webhook endpoint is configured', { exact: false }).waitFor();
  await page.locator('#webhook-url').fill('https://receiver.example/hook');
  await page.locator('#webhook-secret').fill('b'.repeat(32));
  await page.locator('#receiver-ready').check();
  await page.getByRole('button', { name: 'Save complete webhook configuration' }).click();
  await page.getByText('webhook management is not available', { exact: false }).waitFor();
  assert.equal(await page.locator('#integration-uncertain').isVisible(), false);
  assert.equal(await page.locator('#webhook-fields').isDisabled(), false);

  webhookStatus = 412;
  await page.locator('#webhook-secret').fill('c'.repeat(32));
  await page.locator('#receiver-ready').check();
  await page.getByRole('button', { name: 'Save complete webhook configuration' }).click();
  await page.getByText('precondition is stale', { exact: false }).waitFor();
  assert.equal(await page.locator('#integration-uncertain').isVisible(), false);
  assert.equal(await page.locator('#webhook-fields').isDisabled(), false);
});

test('webhook pause and rotation send narrow JSON patches with explicit snapshot warnings', async t => {
  const { page } = await fixture(t); const patches = [], warnings = [];
  let enabled = true;
  page.on('dialog', async dialog => { warnings.push(dialog.message()); await dialog.accept(); });
  await page.route(`**/api/control-plane/forms/${FORM_ID}/webhook`, async route => {
    if (route.request().method() === 'PATCH') {
      patches.push({ body: route.request().postDataJSON(), headers: route.request().headers() });
      if ('enabled' in patches.at(-1).body) enabled = patches.at(-1).body.enabled;
    }
    await route.fulfill({ json: { data: { formId: FORM_ID, enabled, origin: 'https://receiver.example', updatedAt: '2026-08-30T12:00:00Z' } } });
  });
  await page.getByRole('button', { name: /Contact us/ }).click();
  await page.getByText('Viewing saved version 1', { exact: false }).waitFor();
  await page.getByRole('button', { name: 'Load webhook', exact: true }).click();
  await page.getByRole('button', { name: 'Pause future deliveries' }).click();
  await page.getByText('Future delivery setting updated.', { exact: false }).waitFor();
  assert.deepEqual(patches[0].body, { enabled: false });
  await page.locator('#webhook-secret').fill('b'.repeat(32));
  await page.locator('#receiver-ready').check();
  await page.locator('#rotate-webhook').click();
  await page.getByText('Signing secret rotated for future deliveries.', { exact: false }).waitFor();
  assert.deepEqual(patches[1].body, { signingSecret: 'b'.repeat(32) });
  assert.equal(await page.locator('#webhook-secret').inputValue(), '');
  assert.ok(patches.every(patch => patch.headers['content-type'] === 'application/json' && !patch.headers['if-match']));
  assert.match(warnings[0], /Accepted deliveries can still be dispatched/);
  assert.match(warnings[1], /keep the old key/);
});
