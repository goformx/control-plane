import { test } from 'node:test';
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { randomUUID } from 'node:crypto';
import { chromium } from 'playwright';
import { parseJSON, stringify } from '../../ui/schema-json.js';
import { readSchemaEditor } from '../helpers/editor.mjs';

test('real browser login → create → publish → integrations → submission, with foreign workspace denial', { timeout: 120000 }, async t => {
  assert.equal(process.env.GOFORMX_BROWSER_REHEARSAL, '1');
  const url = process.env.GOFORMX_CROSS_SERVICE_UI_URL;
  assert.equal(url, 'http://127.0.0.1:18091');
  assert.equal(process.env.GOFORMX_PUBLIC_API_URL, 'http://127.0.0.1:18090');
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
  let browserServer;
  t.after(async () => {
    try { if (browserServer) await browserServer.kill(); }
    finally { fixture({ action: 'cleanup', users: users.map(({ id, subject }) => ({ id, subject })) }); }
  });
  // Explicit ownership permits deterministic teardown even after a failed browser request.
  browserServer = await chromium.launchServer();
  const browser = await chromium.connect(browserServer.wsEndpoint());
  const errors = [], leaked = [];
  async function login(user) {
    const context = await browser.newContext(); const page = await context.newPage(); page.setDefaultTimeout(15000);
    const statuses = [];
    page.on('response', response => { const path = new URL(response.url()).pathname; if (path === '/api/control-plane/context' || path === '/api/auth/login') statuses.push([path, response.status()]); });
    page.on('pageerror', error => errors.push(error.message));
    page.on('request', request => { if (request.headers().authorization) leaked.push(request.url()); });
    const loginPage = await page.goto(url + '/login');
    assert.equal(loginPage.status(), 200, `Login page HTTP ${loginPage.status()}; rate-limit remaining=${loginPage.headers()['x-ratelimit-remaining'] ?? 'absent'}, retry-after=${loginPage.headers()['retry-after'] ?? 'absent'}`);
    await page.getByLabel('Email', { exact: true }).fill(user.email);
    await page.getByLabel('Password', { exact: true }).fill(user.password);
    await page.getByRole('button', { name: 'Sign in', exact: true }).click();
    await page.waitForURL(url + '/app');
    try { await page.getByText('owner · Server-authorized workspace').waitFor(); }
    catch {
      // Only the application's already-sanitized UI error and non-secret status codes.
      const detail = await page.locator('#error-message').textContent();
      throw new Error(`Workspace initialization failed: ${detail}; HTTP statuses ${JSON.stringify(statuses)}; script errors ${JSON.stringify(errors)}`);
    }
    return page;
  }
  const owner = await login(users[0]);
  const name = `browser-${randomUUID()}`;
  await owner.getByRole('button', { name: '+ New form', exact: true }).click();
  await owner.getByRole('textbox', { name: 'Name', exact: true }).fill(name);
  await owner.getByRole('textbox', { name: 'Title', exact: true }).fill('Browser release gate');
  await owner.getByRole('textbox', { name: 'Allowed browser origins' }).fill(url);
  await owner.getByRole('button', { name: 'Validate & create form' }).click();
  await owner.getByText('Form created as a draft.', { exact: false }).waitFor();
  const schema = owner.getByRole('textbox', { name: 'JSON Schema editor' });
  const original = JSON.parse(await readSchemaEditor(owner));
  const invalid = JSON.stringify({ ...original, type: 'array' });
  await schema.fill(invalid);
  await owner.getByRole('button', { name: 'Validate & save new draft' }).click();
  await owner.locator('#error-fields li').first().waitFor();
  assert.equal(await readSchemaEditor(owner), invalid, 'Go validation failure retains the editable schema');
  const revised = { ...original, properties: { ...original.properties, unconstrained: {}, ['__proto__']: { type: 'string' } } };
  revised.properties.exactInteger = parseJSON('{"type":"integer","minimum":9007199254740993}');
  revised.properties.exactInteger.title = 'Original accepted integer';
  revised['x-goformx-sensitive'] = ['/email', '/message'];
  revised.properties.exactDecimal = parseJSON('{"type":"number","minimum":0.1234567890123456789}');
  const exactSchema = stringify(revised);
  assert.match(exactSchema, /"minimum":\s*9007199254740993/);
  assert.match(exactSchema, /"minimum":\s*0\.1234567890123456789/);
  await schema.fill(exactSchema);
  await owner.getByRole('button', { name: 'Validate & save new draft' }).click();
  await owner.getByText('Saved draft version 2.', { exact: false }).waitFor();
  await owner.getByLabel('Saved versions').selectOption('1');
  await owner.getByText('Viewing saved version 1', { exact: false }).waitFor();
  assert.deepEqual(JSON.parse(await readSchemaEditor(owner)), original);
  await owner.getByLabel('Saved versions').selectOption('2');
  await owner.getByText('Viewing saved version 2', { exact: false }).waitFor();
  const readback = await readSchemaEditor(owner);
  assert.deepEqual(JSON.parse(readback).properties.__proto__, { type: 'string' }, 'Special property names survive the real editor → PHP → Go → readback path');
  assert.match(readback, /"minimum":\s*9007199254740993/, 'Saved integer constraint must not be rounded');
  assert.match(readback, /"minimum":\s*0\.1234567890123456789/, 'Saved decimal constraint must not be rounded');
  await owner.getByRole('button', { name: 'Review publication' }).click();
  await owner.getByRole('button', { name: 'Publish version', exact: true }).click();
  await owner.getByText('Version 2 published.', { exact: false }).waitFor();
  const endpoint = await owner.getByLabel('Submission endpoint').inputValue();
  assert.match(endpoint, /^http:\/\/127\.0\.0\.1:18090\/v1\/public\/forms\/gfpk_[A-Za-z0-9_-]+\/submissions$/);
  const submission = await owner.evaluate(async ({ endpoint, key }) => {
    // Raw JSON is intentional: JavaScript Number would invalidate this oracle.
    const body = '{"data":{"email":"browser@example.test","message":"Synthetic browser gate","exactInteger":9007199254740993,"exactDecimal":0.1234567890123456789}}';
    const options = { method: 'POST', signal: AbortSignal.timeout(15000), headers: { 'Content-Type': 'application/json', 'Idempotency-Key': key, 'X-GoFormX-Schema-Version': '2' }, body };
    const rejected = [];
    for (const below of [body.replace('9007199254740993', '9007199254740992'), body.replace('0.1234567890123456789', '0.1234567890123456788')]) {
      const response = await fetch(endpoint, { ...options, headers: { ...options.headers, 'Idempotency-Key': crypto.randomUUID() }, body: below });
      rejected.push(response.status);
      await response.text();
    }
    const first = await fetch(endpoint, options), second = await fetch(endpoint, options);
    return { rejected, statuses: [first.status, second.status], first: await first.json(), second: await second.json() };
  }, { endpoint, key: randomUUID() });
  assert.deepEqual(submission.rejected, [422, 422], 'Adjacent integer and decimal values must fail exact schema constraints');
  assert.deepEqual(submission.statuses, [202, 202]); assert.equal(submission.first.data.id, submission.second.data.id);
  const contextData = await owner.evaluate(async () => (await fetch('/api/control-plane/forms')).json());
  const owned = contextData.data.find(form => form.name === name); assert.ok(owned);
  const organizationId = owned.organizationId;
  assert.ok(/^[0-9a-f-]{36}$/i.test(organizationId), 'Owned form organization identifier is unavailable.');
  // Publishing a different policy must not reinterpret an already accepted row.
  const newer = parseJSON(stringify(revised));
  newer.properties.exactInteger.title = 'Newer integer label';
  newer['x-goformx-sensitive'] = ['/email', '/message', '/exactInteger'];
  await schema.fill(stringify(newer));
  await owner.getByRole('button', { name: 'Validate & save new draft' }).click();
  await owner.getByText('Saved draft version 3.', { exact: false }).waitFor();
  await owner.getByRole('button', { name: 'Review publication' }).click();
  await owner.getByRole('button', { name: 'Publish version', exact: true }).click();
  await owner.getByText('Version 3 published.', { exact: false }).waitFor();
  await owner.getByRole('button', { name: 'Load submissions', exact: true }).click();
  await owner.getByText('1 submission · page 1', { exact: true }).waitFor();
  await owner.locator('#submission-list button').first().click();
  await owner.getByRole('heading', { name: 'Submission detail', exact: true }).waitFor();
  const acceptedValues = await owner.locator('#submission-values').textContent();
  assert.match(acceptedValues, /Original accepted integer/);
  assert.match(acceptedValues, /9007199254740993/); assert.match(acceptedValues, /0\.1234567890123456789/);
  assert.doesNotMatch(acceptedValues, /browser@example\.test|Synthetic browser gate|Newer integer label/);
  assert.match(await owner.locator('#submission-schema').textContent(), /Original accepted integer/);
  await owner.getByRole('textbox', { name: 'Accepted schema version', exact: true }).fill('2');
  await owner.getByRole('button', { name: 'Apply submission filters', exact: true }).click();
  await owner.getByText('1 submission · page 1', { exact: true }).waitFor();
  for (const format of ['json', 'csv']) {
    const ready = owner.waitForEvent('download');
    await owner.getByRole('button', { name: `Export ${format.toUpperCase()}`, exact: true }).click();
    const download = await ready;
    const chunks = []; for await (const chunk of await download.createReadStream()) chunks.push(chunk);
    const exported = Buffer.concat(chunks).toString();
    assert.match(download.suggestedFilename(), new RegExp(`^goformx-submissions-[0-9a-f-]+\\.${format}$`));
    assert.ok(exported.includes(submission.first.data.id));
    assert.match(exported, /9007199254740993/); assert.match(exported, /0\.1234567890123456789/);
    assert.doesNotMatch(exported, /browser@example\.test|Synthetic browser gate/);
  }
  assert.equal(owner.url(), url + '/app');
  assert.deepEqual(await owner.evaluate(() => [localStorage.length, sessionStorage.length]), [0, 0]);
  const noCsrf = await owner.evaluate(async id => (await fetch(`/api/control-plane/forms/${id}/submissions/export`, {
    method: 'POST', headers: { 'Content-Type': 'application/json' }, body: '{"format":"json"}',
  })).status, owned.id);
  assert.equal(noCsrf, 403);
  const foreign = await login(users[1]);
  await foreign.getByText('No forms here yet.', { exact: false }).waitFor();
  assert.equal(await foreign.getByRole('button', { name: /Browser release gate/ }).count(), 0);
  const denied = await foreign.evaluate(async id => (await fetch(`/api/control-plane/forms/${id}`)).status, owned.id);
  assert.equal(denied, 404); assert.deepEqual(leaked, []); assert.deepEqual(errors, []);
  const deniedSubmissions = await foreign.evaluate(async ({ form, submission }) => {
    const root = `/api/control-plane/forms/${form}/submissions`;
    const headers = { 'Content-Type': 'application/json', 'X-XSRF-TOKEN': decodeURIComponent(document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/)?.[1] ?? '') };
    const responses = await Promise.all([fetch(root), fetch(`${root}/${submission}`), fetch(`${root}/export`, { method: 'POST', headers, body: '{"format":"json"}' })]);
    return responses.map(response => ({ status: response.status, attachment: response.headers.get('Content-Disposition') }));
  }, { form: owned.id, submission: submission.first.data.id });
  assert.deepEqual(deniedSubmissions, Array.from({ length: 3 }, () => ({ status: 404, attachment: null })));

  // Run the integration lifecycle last so it cannot mask the pre-existing form
  // and tenancy assertions if a management operation regresses shared UI state.
  const integrationName = `browser-token-${randomUUID()}`;
  await owner.getByRole('button', { name: 'Manage API access' }).click();
  await owner.getByText('No token metadata returned.', { exact: true }).waitFor();
  await owner.locator('#token-name').fill(integrationName);
  await owner.getByLabel('forms:read', { exact: true }).check();
  await owner.locator('#token-warning').check();
  await owner.getByRole('button', { name: 'Create scoped token' }).click();
  await owner.getByRole('heading', { name: 'Save this token now', exact: true }).waitFor();
  const externalToken = await owner.locator('#issued-token').inputValue();
  assert.ok(/^gfst_[A-Za-z0-9_-]{43}$/.test(externalToken), 'Issued token shape mismatched; value withheld.');
  const externalBeforeRevoke = await fetch(`${process.env.GOFORMX_PUBLIC_API_URL}/v1/forms?limit=25&offset=0`, {
    headers: { Authorization: `Bearer ${externalToken}` }, signal: AbortSignal.timeout(15000),
  });
  assert.equal(externalBeforeRevoke.status, 200);
  assert.ok((await externalBeforeRevoke.text()).includes(owned.id), 'Scoped token did not return the owned form; body withheld.');
  await owner.getByRole('button', { name: 'Reload token metadata', exact: true }).click();
  await owner.getByText(`${integrationName} · active`, { exact: true }).waitFor();
  owner.once('dialog', dialog => dialog.accept());
  await owner.getByRole('button', { name: `Revoke ${integrationName}`, exact: true }).click();
  await owner.getByText('Token revoked. It cannot authenticate new API requests.', { exact: true }).waitFor();
  const externalAfterRevoke = await fetch(`${process.env.GOFORMX_PUBLIC_API_URL}/v1/forms?limit=25&offset=0`, {
    headers: { Authorization: `Bearer ${externalToken}` }, signal: AbortSignal.timeout(15000),
  });
  assert.equal(externalAfterRevoke.status, 401);
  await externalAfterRevoke.text();

  await owner.getByRole('button', { name: 'Load webhook', exact: true }).click();
  await owner.getByText('No webhook endpoint is configured for this form.', { exact: true }).waitFor();
  // The real data plane resolves destinations as an SSRF defense. Use a public
  // documentation origin; no submission follows this configuration lifecycle.
  await owner.locator('#webhook-url').fill('https://example.com/hooks/goformx');
  await owner.locator('#webhook-headers').fill('{"X-Receiver-Fixture":"browser-live"}');
  await owner.locator('#webhook-secret').fill('browser-live-original-signing-key-123456');
  await owner.locator('#receiver-ready').check();
  await owner.getByRole('button', { name: 'Save complete webhook configuration', exact: true }).click();
  await owner.getByText('Enabled for future submissions · https://example.com', { exact: false }).waitFor();
  assert.equal(await owner.locator('#webhook-secret').inputValue(), '');
  assert.equal(await owner.locator('#webhook-headers').inputValue(), '');
  owner.once('dialog', dialog => dialog.accept());
  await owner.getByRole('button', { name: 'Pause future deliveries', exact: true }).click();
  await owner.getByText('Paused for future submissions · https://example.com', { exact: false }).waitFor();
  owner.once('dialog', dialog => dialog.accept());
  await owner.getByRole('button', { name: 'Resume future deliveries', exact: true }).click();
  await owner.getByText('Enabled for future submissions · https://example.com', { exact: false }).waitFor();
  await owner.locator('#webhook-secret').fill('browser-live-rotated-signing-key-123456');
  await owner.locator('#receiver-ready').check();
  owner.once('dialog', dialog => dialog.accept());
  await owner.getByRole('button', { name: 'Rotate signing secret only', exact: true }).click();
  await owner.getByText('Signing secret rotated for future deliveries.', { exact: false }).waitFor();
  assert.equal(await owner.locator('#webhook-secret').inputValue(), '');
  owner.once('dialog', dialog => dialog.accept());
  await owner.getByRole('button', { name: 'Remove webhook endpoint', exact: true }).click();
  await owner.getByText('No webhook endpoint is configured for this form.', { exact: true }).waitFor();
  await owner.getByText('Endpoint removed. Accepted deliveries are retained.', { exact: true }).waitFor();
  const durable = dataPlaneEvidence({ organizationId, formId: owned.id, tokenName: integrationName });
  assert.equal(durable.activeTokenCount, 0);
  assert.equal(durable.revokedTokenCount, 1);
  assert.equal(durable.webhookEndpointCount, 0);
  assert.deepEqual(durable.events.map(event => event.event), [
    'service_token.created', 'service_token.revoked', 'webhook.created', 'webhook.paused',
    'webhook.resumed', 'webhook.signing_secret_rotated', 'webhook.deleted',
  ]);
  assert.equal(new Set(durable.events.map(event => event.auditId)).size, durable.events.length);
  assert.ok(durable.events.every(event => /^[0-9a-f-]{36}$/i.test(event.auditId)));
});
