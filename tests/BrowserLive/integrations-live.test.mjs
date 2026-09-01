import { test } from 'node:test';
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { randomUUID } from 'node:crypto';
import { chromium } from 'playwright';

test('real owner dashboard → PHP → Go → PostgreSQL integration lifecycle', { timeout: 90000 }, async t => {
  assert.equal(process.env.GOFORMX_BROWSER_REHEARSAL, '1');
  const uiUrl = process.env.GOFORMX_CROSS_SERVICE_UI_URL;
  const apiUrl = process.env.GOFORMX_PUBLIC_API_URL;
  assert.equal(uiUrl, 'http://127.0.0.1:18091');
  assert.equal(apiUrl, 'http://127.0.0.1:18090');

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
  const dataPlaneEvidence = input => {
    try { return JSON.parse(execFileSync('php', ['tests/BrowserLive/fixtures/data-plane-evidence.php'], { input: JSON.stringify(input), encoding: 'utf8', stdio: ['pipe', 'pipe', 'pipe'], timeout: 30000 })); }
    catch (error) {
      let location = 'unavailable';
      try {
        const info = JSON.parse(error.stderr);
        if (/^[a-z-]+$/.test(info.stage) && /^[A-Za-z0-9_\\]+$/.test(info.exception) && /^[A-Za-z0-9_.-]+$/.test(info.file) && Number.isInteger(info.line)) location = `${info.stage}: ${info.exception} in ${info.file}:${info.line}`;
      } catch { /* Never print unstructured subprocess output. */ }
      throw new Error(`Disposable data-plane evidence query failed or timed out (${location}); credentials and rows withheld.`);
    }
  };

  const users = JSON.parse(fixture({ action: 'create' }));
  const browserServer = await chromium.launchServer();
  const browser = await chromium.connect(browserServer.wsEndpoint());
  t.after(async () => {
    try { await browserServer.kill(); }
    finally { fixture({ action: 'cleanup', users: users.map(({ id, subject }) => ({ id, subject })) }); }
  });
  const page = await browser.newPage();
  page.setDefaultTimeout(15000);
  const errors = [], leaked = [];
  page.on('pageerror', error => errors.push(error.message));
  page.on('request', request => { if (request.headers().authorization) leaked.push(request.url()); });

  const loginPage = await page.goto(uiUrl + '/login');
  assert.equal(loginPage.status(), 200, `Login page HTTP ${loginPage.status()}; rate-limit remaining=${loginPage.headers()['x-ratelimit-remaining'] ?? 'absent'}, retry-after=${loginPage.headers()['retry-after'] ?? 'absent'}`);
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
  async function waitForIntegration(text, options = {}) {
    return Promise.race([
      page.getByText(text, options).waitFor(),
      page.locator('#integration-error:not([hidden])').waitFor().then(async () => {
        const detail = await page.locator('#integration-error').textContent();
        throw new Error(`Integration workflow failed: ${detail}`);
      }),
    ]);
  }

  const formName = `integration-${randomUUID()}`;
  await page.getByRole('button', { name: '+ New form', exact: true }).click();
  await page.getByRole('textbox', { name: 'Name', exact: true }).fill(formName);
  await page.getByRole('textbox', { name: 'Title', exact: true }).fill('Integration lifecycle gate');
  await page.getByRole('textbox', { name: 'Allowed browser origins' }).fill(uiUrl);
  await page.getByRole('button', { name: 'Validate & create form' }).click();
  await page.getByText('Form created as a draft.', { exact: false }).waitFor();
  const forms = await page.evaluate(async () => (await (await fetch('/api/control-plane/forms')).json()).data);
  const owned = forms.find(form => form.name === formName);
  assert.ok(owned && /^[0-9a-f-]{36}$/i.test(owned.id), 'Created form evidence is unavailable.');
  assert.ok(/^[0-9a-f-]{36}$/i.test(owned.organizationId), 'Owned organization evidence is unavailable.');

  const integrationName = `browser-token-${randomUUID()}`;
  await page.getByRole('button', { name: 'Manage API access' }).click();
  await waitForIntegration('No token metadata returned.', { exact: true });
  await page.locator('#token-name').fill(integrationName);
  await page.getByLabel('forms:read', { exact: true }).check();
  await page.locator('#token-warning').check();
  await page.getByRole('button', { name: 'Create scoped token' }).click();
  await Promise.race([
    page.getByRole('heading', { name: 'Save this token now', exact: true }).waitFor(),
    page.locator('#integration-error:not([hidden])').waitFor().then(async () => {
      throw new Error(`Integration workflow failed: ${await page.locator('#integration-error').textContent()}`);
    }),
  ]);
  const externalToken = await page.locator('#issued-token').inputValue();
  assert.ok(/^gfst_[A-Za-z0-9_-]{43}$/.test(externalToken), 'Issued token shape mismatched; value withheld.');
  const beforeRevoke = await fetch(`${apiUrl}/v1/forms?limit=25&offset=0`, {
    headers: { Authorization: `Bearer ${externalToken}` }, signal: AbortSignal.timeout(15000),
  });
  assert.equal(beforeRevoke.status, 200);
  assert.ok((await beforeRevoke.text()).includes(owned.id), 'Scoped token did not return the owned form; body withheld.');
  await page.getByRole('button', { name: 'Reload token metadata', exact: true }).click();
  await waitForIntegration(`${integrationName} · active`, { exact: true });
  page.once('dialog', dialog => dialog.accept());
  await page.getByRole('button', { name: `Revoke ${integrationName}`, exact: true }).click();
  await waitForIntegration('Token revoked. It cannot authenticate new API requests.', { exact: true });
  const afterRevoke = await fetch(`${apiUrl}/v1/forms?limit=25&offset=0`, {
    headers: { Authorization: `Bearer ${externalToken}` }, signal: AbortSignal.timeout(15000),
  });
  assert.equal(afterRevoke.status, 401);
  await afterRevoke.text();

  await page.getByRole('button', { name: 'Load webhook', exact: true }).click();
  await waitForIntegration('No webhook endpoint is configured for this form.', { exact: true });
  // The real data plane resolves destinations as an SSRF defense. This public
  // documentation origin receives no traffic because this draft has no submissions.
  await page.locator('#webhook-url').fill('https://example.com/hooks/goformx');
  await page.locator('#webhook-headers').fill('{"X-Receiver-Fixture":"browser-live"}');
  await page.locator('#webhook-secret').fill('browser-live-original-signing-key-123456');
  await page.locator('#receiver-ready').check();
  await page.getByRole('button', { name: 'Save complete webhook configuration', exact: true }).click();
  await waitForIntegration('Enabled for future submissions · https://example.com', { exact: false });
  const projection = await page.evaluate(async formId => {
    const response = await fetch(`/api/control-plane/forms/${encodeURIComponent(formId)}/webhook`, { headers: { Accept: 'application/json' }, cache: 'no-store' });
    return { status: response.status, text: await response.text() };
  }, owned.id);
  assert.equal(projection.status, 200);
  const projectedText = projection.text;
  assert.ok(projectedText.length > 0, 'Projected webhook response was empty.');
  let projectedBody;
  try { projectedBody = JSON.parse(projectedText); }
  catch { throw new Error('Projected webhook response was not valid JSON; body withheld.'); }
  assert.ok(projectedBody?.data && typeof projectedBody.data === 'object', 'Projected webhook response returned no data; body withheld.');
  assert.deepEqual(Object.keys(projectedBody.data).sort(), ['enabled', 'formId', 'origin', 'updatedAt']);
  assert.ok(!JSON.stringify(projectedBody).includes('browser-live-original-signing-key-123456'), 'Projected webhook response exposed its signing secret.');
  assert.ok(!JSON.stringify(projectedBody).includes('X-Receiver-Fixture'), 'Projected webhook response exposed write-only headers.');
  page.once('dialog', dialog => dialog.accept());
  await page.getByRole('button', { name: 'Pause future deliveries', exact: true }).click();
  await waitForIntegration('Paused for future submissions · https://example.com', { exact: false });
  page.once('dialog', dialog => dialog.accept());
  await page.getByRole('button', { name: 'Resume future deliveries', exact: true }).click();
  await waitForIntegration('Enabled for future submissions · https://example.com', { exact: false });
  await page.locator('#webhook-secret').fill('browser-live-rotated-signing-key-123456');
  await page.locator('#receiver-ready').check();
  page.once('dialog', dialog => dialog.accept());
  await page.getByRole('button', { name: 'Rotate signing secret only', exact: true }).click();
  await waitForIntegration('Signing secret rotated for future deliveries.', { exact: false });
  page.once('dialog', dialog => dialog.accept());
  await page.getByRole('button', { name: 'Remove webhook endpoint', exact: true }).click();
  await waitForIntegration('No webhook endpoint is configured for this form.', { exact: true });

  const durable = dataPlaneEvidence({ organizationId: owned.organizationId, formId: owned.id, tokenName: integrationName });
  assert.deepEqual([durable.activeTokenCount, durable.revokedTokenCount, durable.webhookEndpointCount], [0, 1, 0]);
  assert.deepEqual(durable.events.map(event => event.event), [
    'service_token.created', 'service_token.revoked', 'webhook.created', 'webhook.paused',
    'webhook.resumed', 'webhook.signing_secret_rotated', 'webhook.deleted',
  ]);
  assert.equal(new Set(durable.events.map(event => event.auditId)).size, durable.events.length);
  assert.ok(durable.events.every(event => /^[0-9a-f-]{36}$/i.test(event.auditId)));
  assert.deepEqual(await page.evaluate(() => [localStorage.length, sessionStorage.length]), [0, 0]);
  assert.deepEqual(leaked, []);
  assert.deepEqual(errors, []);
});
