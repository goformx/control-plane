import { test } from 'node:test';
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { randomUUID } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { chromium } from 'playwright';

test('committed mutations with lost responses require explicit reconciliation', { timeout: 90000 }, async t => {
  assert.equal(process.env.GOFORMX_BROWSER_REHEARSAL, '1');
  const uiUrl = process.env.GOFORMX_CROSS_SERVICE_UI_URL;
  const controlUrl = process.env.GOFORMX_FAULT_PROXY_CONTROL_URL;
  assert.equal(uiUrl, 'http://127.0.0.1:18091');
  assert.equal(controlUrl, 'http://127.0.0.1:18094');
  const fixture = input => {
    try { return execFileSync('php', ['tests/BrowserLive/fixtures/users.php'], { input: JSON.stringify(input), encoding: 'utf8', stdio: ['pipe', 'pipe', 'pipe'], timeout: 30000 }); }
    catch (error) {
      let location = 'unavailable';
      try {
        const info = JSON.parse(error.stderr);
        if (/^[a-z-]+$/.test(info.stage) && /^[A-Za-z0-9_\\]+$/.test(info.exception) && /^[A-Za-z0-9_.-]+$/.test(info.file) && Number.isInteger(info.line)) location = `${info.stage}: ${info.exception} in ${info.file}:${info.line}`;
      } catch { /* Never print unstructured subprocess output. */ }
      throw new Error(`Disposable browser fixture ${input.action} failed or timed out (${location}); credentials and logs withheld.`);
    }
  };
  const evidence = input => {
    try { return JSON.parse(execFileSync('php', ['tests/BrowserLive/fixtures/data-plane-evidence.php'], {
      input: JSON.stringify(input), encoding: 'utf8', stdio: ['pipe', 'pipe', 'pipe'], timeout: 30000,
    })); }
    catch (error) {
      let location = 'unavailable';
      try {
        const info = JSON.parse(error.stderr);
        if (/^[a-z-]+$/.test(info.stage) && /^[A-Za-z0-9_\\]+$/.test(info.exception) && /^[A-Za-z0-9_.-]+$/.test(info.file) && Number.isInteger(info.line)) location = `${info.stage}: ${info.exception} in ${info.file}:${info.line}`;
      } catch { /* Never print unstructured subprocess output. */ }
      throw new Error(`Disposable data-plane evidence query failed or timed out (${location}); credentials and rows withheld.`);
    }
  };
  async function arm(method, path) {
    const response = await fetch(controlUrl + '/arm', { method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ method, path }), signal: AbortSignal.timeout(5000) });
    assert.equal(response.status, 200); await response.text();
  }
  async function proxyState() {
    const response = await fetch(controlUrl + '/state', { signal: AbortSignal.timeout(5000) });
    assert.equal(response.status, 200); return response.json();
  }

  const users = JSON.parse(fixture({ action: 'create' }));
  const browser = await chromium.launch();
  t.after(async () => { try { await browser.close(); } finally { fixture({ action: 'cleanup', users: users.map(({ id, subject }) => ({ id, subject })) }); } });
  const page = await browser.newPage(); page.setDefaultTimeout(15000);
  const errors = [], leaked = [];
  page.on('pageerror', error => errors.push(error.message));
  page.on('request', request => { if (request.headers().authorization) leaked.push(request.url()); });
  await page.goto(uiUrl + '/login');
  await page.getByLabel('Email', { exact: true }).fill(users[0].email);
  await page.getByLabel('Password', { exact: true }).fill(users[0].password);
  await page.getByRole('button', { name: 'Sign in', exact: true }).click();
  await page.waitForURL(uiUrl + '/app');
  try { await page.getByText('owner · Server-authorized workspace').waitFor(); }
  catch (error) {
    const detail = await page.locator('#error-message').textContent();
    if (!detail) throw error;
    throw new Error(`Workspace initialization failed: ${detail}; credentials and raw responses withheld.`);
  }

  const formName = `failure-${randomUUID()}`;
  await page.getByRole('button', { name: '+ New form', exact: true }).click();
  await page.getByRole('textbox', { name: 'Name', exact: true }).fill(formName);
  await page.getByRole('textbox', { name: 'Title', exact: true }).fill('Uncertain mutation gate');
  await page.getByRole('textbox', { name: 'Allowed browser origins' }).fill(uiUrl);
  await page.getByRole('button', { name: 'Validate & create form' }).click();
  await page.getByText('Form created as a draft.', { exact: false }).waitFor();
  const forms = await page.evaluate(async () => (await (await fetch('/api/control-plane/forms')).json()).data);
  const owned = forms.find(form => form.name === formName);
  assert.ok(owned && /^[0-9a-f-]{36}$/i.test(owned.id) && /^[0-9a-f-]{36}$/i.test(owned.organizationId));

  const tokenName = `browser-token-${randomUUID()}`;
  await page.getByRole('button', { name: 'Manage API access' }).click();
  await page.getByText('No token metadata returned.', { exact: true }).waitFor();
  await arm('POST', '/v1/service-tokens');
  await page.locator('#token-name').fill(tokenName);
  await page.getByLabel('forms:read', { exact: true }).check();
  await page.locator('#token-warning').check();
  await page.getByRole('button', { name: 'Create scoped token' }).click();
  await page.locator('#integration-uncertain').waitFor();
  await page.getByText('The outcome may be uncertain.', { exact: false }).waitFor();
  assert.equal(await page.locator('#issued-token').inputValue(), '');
  await page.waitForFunction(() => document.querySelector('#token-fields')?.disabled === true);
  assert.equal(await page.locator('#token-fields').evaluate(fieldset => fieldset.disabled), true);
  assert.deepEqual((await proxyState()).lastDrop, { method: 'POST', path: '/v1/service-tokens', upstreamStatus: 201 });

  await page.getByRole('button', { name: 'Reload token metadata', exact: true }).click();
  await page.getByText(`${tokenName} · active`, { exact: true }).waitFor();
  const committedToken = evidence({ organizationId: owned.organizationId, formId: owned.id, tokenName });
  assert.deepEqual([committedToken.activeTokenCount, committedToken.revokedTokenCount], [1, 0]);
  page.once('dialog', dialog => dialog.accept());
  await page.getByRole('button', { name: `Revoke ${tokenName}`, exact: true }).click();
  await page.getByText('Token revoked. It cannot authenticate new API requests.', { exact: true }).waitFor();
  page.once('dialog', dialog => dialog.accept());
  await page.getByRole('button', { name: 'I have reconciled the change', exact: true }).click();
  assert.equal(await page.locator('#integration-uncertain').isVisible(), false);

  await page.getByRole('button', { name: 'Load webhook', exact: true }).click();
  await page.getByText('No webhook endpoint is configured for this form.', { exact: true }).waitFor();
  await arm('PUT', `/v1/forms/${owned.id}/webhook`);
  const webhookSecret = `failure-secret-${randomUUID()}-1234567890`;
  await page.locator('#webhook-url').fill('https://example.com/goformx-failure-fixture');
  await page.locator('#webhook-secret').fill(webhookSecret);
  await page.locator('#receiver-ready').check();
  await page.getByRole('button', { name: 'Save complete webhook configuration', exact: true }).click();
  await page.locator('#integration-uncertain').waitFor();
  assert.equal(await page.locator('#webhook-secret').inputValue(), '');
  assert.deepEqual((await proxyState()).lastDrop, { method: 'PUT', path: `/v1/forms/${owned.id}/webhook`, upstreamStatus: 200 });
  await page.getByRole('button', { name: 'Load webhook', exact: true }).click();
  await page.getByText('Enabled for future submissions · https://example.com', { exact: false }).waitFor();
  const committedWebhook = evidence({ organizationId: owned.organizationId, formId: owned.id, tokenName, webhookSecrets: [webhookSecret] });
  assert.deepEqual([committedWebhook.activeTokenCount, committedWebhook.revokedTokenCount, committedWebhook.webhookEndpointCount], [0, 1, 1]);
  assert.equal(committedWebhook.plaintextWebhookConfigMatches, 0);
  assert.ok(committedWebhook.webhookConfigRowsScanned >= 1, 'Plaintext evidence must inspect the live endpoint configuration row.');
  await page.waitForFunction(() => document.querySelector('#webhook-fields')?.disabled === true
    && document.querySelector('#delete-webhook')?.disabled === true);
  assert.equal(await page.locator('#webhook-fields').evaluate(fieldset => fieldset.disabled), true);
  assert.equal(await page.locator('#delete-webhook').isDisabled(), true);
  page.once('dialog', dialog => dialog.accept());
  await page.getByRole('button', { name: 'I have reconciled the change', exact: true }).click();
  page.once('dialog', dialog => dialog.accept());
  await page.getByRole('button', { name: 'Remove webhook endpoint', exact: true }).click();
  await page.getByText('No webhook endpoint is configured for this form.', { exact: true }).waitFor();

  const finalEvidence = evidence({ organizationId: owned.organizationId, formId: owned.id, tokenName, webhookSecrets: [webhookSecret] });
  assert.deepEqual(finalEvidence.events.map(event => event.event), [
    'service_token.created', 'service_token.revoked', 'webhook.created', 'webhook.deleted',
  ]);
  const logs = [process.env.GOFORMX_CROSS_SERVICE_LOG, process.env.GOFORMX_CROSS_SERVICE_UI_LOG, process.env.GOFORMX_FAULT_PROXY_LOG]
    .map(path => readFileSync(path, 'utf8')).join('\n');
  assert.equal(logs.includes(webhookSecret), false, 'Failure-rehearsal logs must not retain the webhook signing secret.');
  assert.deepEqual(await page.evaluate(() => [localStorage.length, sessionStorage.length]), [0, 0]);
  assert.deepEqual(leaked, []); assert.deepEqual(errors, []);
});
