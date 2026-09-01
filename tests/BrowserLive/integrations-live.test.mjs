import { test } from 'node:test';
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { randomUUID } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { chromium } from 'playwright';

test('real owner dashboard → PHP → Go → PostgreSQL integration lifecycle', { timeout: 90000 }, async t => {
  assert.equal(process.env.GOFORMX_BROWSER_REHEARSAL, '1');
  const uiUrl = process.env.GOFORMX_CROSS_SERVICE_UI_URL;
  const apiUrl = process.env.GOFORMX_PUBLIC_API_URL;
  const receiverUrl = process.env.GOFORMX_WEBHOOK_RECEIVER_URL;
  const receiverControlUrl = process.env.GOFORMX_WEBHOOK_RECEIVER_CONTROL_URL;
  const currentSecret = process.env.GOFORMX_WEBHOOK_CURRENT_SECRET;
  const previousSecret = process.env.GOFORMX_WEBHOOK_PREVIOUS_SECRET;
  assert.equal(uiUrl, 'http://127.0.0.1:18091');
  assert.equal(apiUrl, 'http://127.0.0.1:18090');
  assert.equal(receiverUrl, 'https://receiver.goformx.test/hooks/goformx');
  assert.equal(receiverControlUrl, 'http://127.0.0.1:18092');
  assert.ok(currentSecret && previousSecret && currentSecret !== previousSecret);

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
  async function setReceiverMode(mode) {
    const response = await fetch(receiverControlUrl + '/mode', {
      method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ mode }), signal: AbortSignal.timeout(5000),
    });
    assert.equal(response.status, 200); await response.text();
  }
  async function receiverState() {
    const response = await fetch(receiverControlUrl + '/state', { signal: AbortSignal.timeout(5000) });
    assert.equal(response.status, 200); return response.json();
  }
  async function waitFor(predicate, description) {
    const deadline = Date.now() + 20000;
    while (Date.now() < deadline) {
      const value = await predicate();
      if (value) return value;
      await new Promise(resolve => setTimeout(resolve, 250));
    }
    throw new Error(`Timed out waiting for ${description}; receiver bodies, signatures, and credentials withheld.`);
  }
  async function deliveryHistory() {
    return page.evaluate(async formId => {
      const response = await fetch(`/api/control-plane/forms/${encodeURIComponent(formId)}/deliveries`, { cache: 'no-store' });
      if (!response.ok) throw new Error(`Delivery history returned HTTP ${response.status}; body withheld.`);
      return (await response.json()).data;
    }, owned.id);
  }
  async function submit(endpoint, label) {
    return page.evaluate(async ({ endpoint, key, label }) => {
      const response = await fetch(endpoint, {
        method: 'POST', signal: AbortSignal.timeout(15000),
        headers: { 'Content-Type': 'application/json', 'Idempotency-Key': key, 'X-GoFormX-Schema-Version': '1' },
        body: JSON.stringify({ data: { email: `${label}@example.test`, message: `Synthetic ${label}` } }),
      });
      return { status: response.status, body: await response.json() };
    }, { endpoint, key: randomUUID(), label });
  }
  const expectedNegativeChecks = {
    tamperedBody: 'invalid_signature', staleTimestamp: 'stale_timestamp', wrongKey: 'invalid_signature',
    deliveryIdMismatch: 'delivery_id_mismatch',
  };

  const formName = `integration-${randomUUID()}`;
  await page.getByRole('button', { name: '+ New form', exact: true }).click();
  await page.getByRole('textbox', { name: 'Name', exact: true }).fill(formName);
  await page.getByRole('textbox', { name: 'Title', exact: true }).fill('Integration lifecycle gate');
  await page.getByRole('textbox', { name: 'Allowed browser origins' }).fill(uiUrl);
  await page.getByRole('button', { name: 'Validate & create form' }).click();
  await page.getByText('Form created as a draft.', { exact: false }).waitFor();
  await page.getByRole('button', { name: 'Review publication' }).click();
  await page.getByRole('button', { name: 'Publish version', exact: true }).click();
  await page.getByText('Version 1 published.', { exact: false }).waitFor();
  const submissionEndpoint = await page.getByLabel('Submission endpoint').inputValue();
  assert.match(submissionEndpoint, /^http:\/\/127\.0\.0\.1:18090\/v1\/public\/forms\/gfpk_[A-Za-z0-9_-]+\/submissions$/);
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
  assert.equal(await page.locator('#issued-token').inputValue(), '', 'A later dashboard action must clear the one-time token reveal.');
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
  await page.locator('#webhook-url').fill(receiverUrl);
  await page.locator('#webhook-headers').fill('{"X-Receiver-Fixture":"browser-live"}');
  await page.locator('#webhook-secret').fill(previousSecret);
  await page.locator('#receiver-ready').check();
  await page.getByRole('button', { name: 'Save complete webhook configuration', exact: true }).click();
  await waitForIntegration('Enabled for future submissions · https://receiver.goformx.test', { exact: false });
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
  assert.deepEqual(Object.keys(projectedBody.data).sort(), ['createdAt', 'enabled', 'formId', 'id', 'origin', 'updatedAt']);
  assert.ok(!JSON.stringify(projectedBody).includes(previousSecret), 'Projected webhook response exposed its signing secret.');
  assert.ok(!JSON.stringify(projectedBody).includes('X-Receiver-Fixture'), 'Projected webhook response exposed write-only headers.');
  page.once('dialog', dialog => dialog.accept());
  await page.getByRole('button', { name: 'Pause future deliveries', exact: true }).click();
  await waitForIntegration('Paused for future submissions · https://receiver.goformx.test', { exact: false });
  page.once('dialog', dialog => dialog.accept());
  await page.getByRole('button', { name: 'Resume future deliveries', exact: true }).click();
  await waitForIntegration('Enabled for future submissions · https://receiver.goformx.test', { exact: false });

  await setReceiverMode('retry-once');
  const retriedSubmission = await submit(submissionEndpoint, 'retry');
  assert.equal(retriedSubmission.status, 202);
  const retriedReceiver = await waitFor(async () => {
    const state = await receiverState();
    return state.deliveries.find(delivery => delivery.submissionId === retriedSubmission.body.data.id && delivery.attempts === 2) ?? false;
  }, 'a signed delivery retry');
  assert.deepEqual(retriedReceiver, {
    id: retriedReceiver.id, submissionId: retriedSubmission.body.data.id, attempts: 2, effectCount: 1, duplicateCount: 1,
    keys: ['previous', 'previous'], timestampsDistinct: true, signaturesDistinct: true, bodiesIdentical: true,
    negativeChecks: expectedNegativeChecks,
  });
  const retriedDelivery = await waitFor(async () => (await deliveryHistory()).find(delivery =>
    delivery.submissionId === retriedSubmission.body.data.id && delivery.status === 'delivered' && delivery.attemptCount === 2), 'durable retry state');

  await setReceiverMode('reject');
  const replayedSubmission = await submit(submissionEndpoint, 'replay');
  assert.equal(replayedSubmission.status, 202);
  const deadLetter = await waitFor(async () => (await deliveryHistory()).find(delivery =>
    delivery.submissionId === replayedSubmission.body.data.id && delivery.status === 'dead_letter'), 'a durable dead letter');
  assert.equal(deadLetter.attemptCount, 1);

  await page.locator('#webhook-secret').fill(currentSecret);
  await page.locator('#receiver-ready').check();
  page.once('dialog', dialog => dialog.accept());
  await page.getByRole('button', { name: 'Rotate signing secret only', exact: true }).click();
  await waitForIntegration('Signing secret rotated for future deliveries.', { exact: false });

  await setReceiverMode('accept');
  await page.getByRole('button', { name: 'Load delivery history', exact: true }).click();
  await page.getByRole('button', { name: `Replay ${deadLetter.id}`, exact: true }).waitFor();
  page.once('dialog', dialog => dialog.accept());
  await page.getByRole('button', { name: `Replay ${deadLetter.id}`, exact: true }).click();
  const replayedDelivery = await waitFor(async () => (await deliveryHistory()).find(delivery =>
    delivery.id === deadLetter.id && delivery.status === 'delivered'), 'dashboard replay delivery');
  assert.equal(replayedDelivery.attemptCount, 1);
  const replayedReceiver = await waitFor(async () => (await receiverState()).deliveries.find(delivery =>
    delivery.id === deadLetter.id && delivery.attempts === 2), 'receiver replay evidence');
  assert.equal(replayedReceiver.effectCount, 1);
  assert.deepEqual(replayedReceiver.keys, ['previous', 'previous']);
  assert.deepEqual(replayedReceiver.negativeChecks, expectedNegativeChecks);

  const rotatedSubmission = await submit(submissionEndpoint, 'rotated');
  assert.equal(rotatedSubmission.status, 202);
  const rotatedReceiver = await waitFor(async () => (await receiverState()).deliveries.find(delivery =>
    delivery.submissionId === rotatedSubmission.body.data.id && delivery.attempts === 1), 'rotated-key delivery');
  assert.deepEqual(rotatedReceiver.keys, ['current']);
  assert.equal(rotatedReceiver.effectCount, 1);
  const rotatedDelivery = await waitFor(async () => (await deliveryHistory()).find(delivery =>
    delivery.submissionId === rotatedSubmission.body.data.id && delivery.status === 'delivered'), 'rotated-key durable state');

  page.once('dialog', dialog => dialog.accept());
  await page.getByRole('button', { name: 'Remove webhook endpoint', exact: true }).click();
  await waitForIntegration('No webhook endpoint is configured for this form.', { exact: true });

  const durable = dataPlaneEvidence({ organizationId: owned.organizationId, formId: owned.id, tokenName: integrationName,
    tokenPlaintext: externalToken, webhookSecrets: [previousSecret, currentSecret] });
  assert.deepEqual([durable.activeTokenCount, durable.revokedTokenCount, durable.webhookEndpointCount], [0, 1, 0]);
  assert.deepEqual([durable.plaintextTokenMatches, durable.plaintextWebhookConfigMatches], [0, 0]);
  assert.deepEqual(new Set(durable.deliveries.map(delivery => delivery.endpointId)).size, 1);
  assert.ok(durable.deliveries.every(delivery => delivery.origin === 'https://receiver.goformx.test' && delivery.hasEncryptedConfig));
  assert.deepEqual(Object.fromEntries(durable.deliveries.map(delivery => [delivery.id, [delivery.status, delivery.attemptCount, delivery.lastHttpStatus]])), {
    [retriedDelivery.id]: ['delivered', 2, 204],
    [replayedDelivery.id]: ['delivered', 1, 204],
    [rotatedDelivery.id]: ['delivered', 1, 204],
  });
  assert.deepEqual(durable.events.map(event => event.event), [
    'service_token.created', 'service_token.revoked', 'webhook.created', 'webhook.paused',
    'webhook.resumed', 'webhook.signing_secret_rotated', 'webhook.delivery_replayed', 'webhook.deleted',
  ]);
  assert.equal(new Set(durable.events.map(event => event.auditId)).size, durable.events.length);
  assert.ok(durable.events.every(event => /^[0-9a-f-]{36}$/i.test(event.auditId)));

  const logs = [process.env.GOFORMX_CROSS_SERVICE_LOG, process.env.GOFORMX_CROSS_SERVICE_UI_LOG, process.env.GOFORMX_WEBHOOK_RECEIVER_LOG]
    .map(path => readFileSync(path, 'utf8')).join('\n');
  for (const secret of [externalToken, previousSecret, currentSecret]) {
    assert.equal(logs.includes(secret), false, 'Service logs must not retain browser-managed credentials.');
  }

  assert.deepEqual(await page.evaluate(() => [localStorage.length, sessionStorage.length]), [0, 0]);
  assert.deepEqual(leaked, []);
  assert.deepEqual(errors, []);
});
