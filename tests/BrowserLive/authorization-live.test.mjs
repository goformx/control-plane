import { test } from 'node:test';
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { randomUUID } from 'node:crypto';
import { chromium } from 'playwright';

test('real integration authorization changes take effect on the next request', { timeout: 90000 }, async t => {
  assert.equal(process.env.GOFORMX_BROWSER_REHEARSAL, '1');
  const uiUrl = process.env.GOFORMX_CROSS_SERVICE_UI_URL;
  assert.equal(uiUrl, 'http://127.0.0.1:18091');

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
    finally { fixture({ action: 'cleanup', users: users.map(({ id, subject }, index) => ({ id, subject, allowMissing: index === 1 })) }); }
  });
  const errors = [], leaked = [];
  async function login(user) {
    const context = await browser.newContext();
    const page = await context.newPage(); page.setDefaultTimeout(15000);
    page.on('pageerror', error => errors.push(error.message));
    page.on('request', request => { if (request.headers().authorization) leaked.push(request.url()); });
    const loginPage = await page.goto(uiUrl + '/login');
    assert.equal(loginPage.status(), 200, `Login HTTP ${loginPage.status()}; retry-after=${loginPage.headers()['retry-after'] ?? 'absent'}`);
    await page.getByLabel('Email', { exact: true }).fill(user.email);
    await page.getByLabel('Password', { exact: true }).fill(user.password);
    await page.getByRole('button', { name: 'Sign in', exact: true }).click();
    await page.waitForURL(uiUrl + '/app');
    await page.getByText('owner · Server-authorized workspace').waitFor();
    return { context, page };
  }

  const ownerSession = await login(users[0]);
  const foreignSession = await login(users[1]);
  const owner = ownerSession.page, foreign = foreignSession.page;
  const formName = `authorization-${randomUUID()}`;
  await owner.getByRole('button', { name: '+ New form', exact: true }).click();
  await owner.getByRole('textbox', { name: 'Name', exact: true }).fill(formName);
  await owner.getByRole('textbox', { name: 'Title', exact: true }).fill('Authorization transition gate');
  await owner.getByRole('textbox', { name: 'Allowed browser origins' }).fill(uiUrl);
  await owner.getByRole('button', { name: 'Validate & create form' }).click();
  await owner.getByText('Form created as a draft.', { exact: false }).waitFor();
  const forms = await owner.evaluate(async () => (await (await fetch('/api/control-plane/forms')).json()).data);
  const owned = forms.find(form => form.name === formName);
  assert.ok(owned && /^[0-9a-f-]{36}$/i.test(owned.id) && /^[0-9a-f-]{36}$/i.test(owned.organizationId));

  const integrationName = `browser-token-${randomUUID()}`;
  await owner.getByRole('button', { name: 'Manage API access' }).click();
  await owner.locator('#token-name').fill(integrationName);
  await owner.getByLabel('forms:read', { exact: true }).check();
  await owner.locator('#token-warning').check();
  await owner.getByRole('button', { name: 'Create scoped token' }).click();
  await owner.getByRole('heading', { name: 'Save this token now', exact: true }).waitFor();
  await owner.getByRole('button', { name: 'Dismiss token', exact: true }).click();
  await owner.getByRole('button', { name: 'Reload token metadata', exact: true }).click();
  await owner.getByText(`${integrationName} · active`, { exact: true }).waitFor();
  const beforeDenied = evidence({ organizationId: owned.organizationId, formId: owned.id, tokenName: integrationName });

  const foreignDenial = await foreign.evaluate(async ({ formId, tokenName }) => {
    const tokens = await fetch('/api/control-plane/service-tokens');
    const tokenBody = await tokens.text();
    const resources = await Promise.all([fetch(`/api/control-plane/forms/${formId}/webhook`), fetch(`/api/control-plane/forms/${formId}/deliveries`)]);
    return { tokenStatus: tokens.status, leakedToken: tokenBody.includes(tokenName),
      resourceStatuses: await Promise.all(resources.map(async response => { await response.text(); return response.status; })) };
  }, { formId: owned.id, tokenName: integrationName });
  assert.deepEqual(foreignDenial, { tokenStatus: 200, leakedToken: false, resourceStatuses: [404, 404] });

  assert.deepEqual(JSON.parse(fixture({ action: 'grant-membership', user: users[1], organizationId: owned.organizationId, role: 'member' })), { ok: true });
  const switched = await foreign.evaluate(async organizationId => {
    const token = decodeURIComponent(document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/)?.[1] ?? '');
    const response = await fetch('/api/control-plane/context/switch', { method: 'POST', headers: {
      'Content-Type': 'application/json', 'X-XSRF-TOKEN': token,
    }, body: JSON.stringify({ organization_id: organizationId }) });
    return { status: response.status, body: await response.json() };
  }, owned.organizationId);
  assert.equal(switched.status, 200);
  assert.equal(switched.body.data.id, owned.organizationId);
  assert.equal(switched.body.data.attributes.role, 'member');
  await foreign.reload();
  await foreign.getByText('member · Server-authorized workspace').waitFor();
  await foreign.getByRole('button', { name: /Authorization transition gate/ }).click();
  await foreign.getByRole('heading', { name: 'Authorization transition gate', exact: true }).waitFor();
  await foreign.locator('#webhook-access').waitFor({ state: 'visible' });
  await foreign.waitForFunction(() => document.querySelector('#manage-tokens')?.disabled === true);
  assert.equal(await foreign.getByRole('button', { name: 'Manage API access', exact: true }).isDisabled(), true);

  const memberDenial = await foreign.evaluate(async ({ formId, name }) => {
    const token = decodeURIComponent(document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/)?.[1] ?? '');
    const headers = { 'Content-Type': 'application/json', 'X-XSRF-TOKEN': token, 'X-Role': 'owner' };
    const requests = [
      fetch('/api/control-plane/service-tokens'), fetch(`/api/control-plane/forms/${formId}/webhook`),
      fetch(`/api/control-plane/forms/${formId}/deliveries`),
      fetch('/api/control-plane/service-tokens', { method: 'POST', headers,
        body: JSON.stringify({ name, scopes: ['forms:read'], expiresInSeconds: 86400 }) }),
    ];
    return Promise.all(requests).then(responses => Promise.all(responses.map(async response => { await response.text(); return response.status; })));
  }, { formId: owned.id, name: `denied-token-${randomUUID()}` });
  assert.deepEqual(memberDenial, [403, 403, 403, 403]);

  assert.deepEqual(JSON.parse(fixture({ action: 'change-membership', user: users[1], organizationId: owned.organizationId, role: 'admin', status: 'active' })), { ok: true });
  await foreign.getByRole('button', { name: 'Refresh', exact: true }).click();
  await foreign.getByText('admin · Server-authorized workspace').waitFor();
  await foreign.getByRole('button', { name: 'Manage API access', exact: true }).click();
  await foreign.getByText(`${integrationName} · active`, { exact: true }).waitFor();
  const adminBoundary = await foreign.evaluate(async name => {
    const noCsrf = await fetch('/api/control-plane/service-tokens', { method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ name, scopes: ['forms:read'], expiresInSeconds: 86400 }) });
    const tokens = await fetch('/api/control-plane/service-tokens');
    await noCsrf.text(); await tokens.text(); return [noCsrf.status, tokens.status];
  }, `csrf-token-${randomUUID()}`);
  assert.deepEqual(adminBoundary, [403, 200]);

  assert.deepEqual(JSON.parse(fixture({ action: 'change-membership', user: users[1], organizationId: owned.organizationId, role: 'member', status: 'active' })), { ok: true });
  await foreign.getByRole('button', { name: 'Refresh', exact: true }).click();
  await foreign.getByText('member · Server-authorized workspace').waitFor();
  await foreign.waitForFunction(() => {
    const button = document.querySelector('#manage-tokens');
    const panel = document.querySelector('#tokens-panel');
    const list = document.querySelector('#token-list');
    return button?.disabled === true && panel?.hidden === true && list?.textContent === '';
  });
  assert.equal(await foreign.getByRole('button', { name: 'Manage API access', exact: true }).isDisabled(), true);
  assert.equal(await foreign.locator('#token-list').textContent(), '');
  assert.equal(await foreign.locator('#tokens-panel').isVisible(), false);

  const beforeRevocation = await foreign.evaluate(async () => {
    const response = await fetch('/api/control-plane/context'); await response.text(); return response.status;
  });
  assert.equal(beforeRevocation, 200);
  assert.deepEqual(JSON.parse(fixture({ action: 'change-membership', user: users[1], organizationId: owned.organizationId, role: 'member', status: 'revoked' })), { ok: true });
  const afterRevocation = await foreign.evaluate(async () => {
    const response = await fetch('/api/control-plane/context'); await response.text(); return response.status;
  });
  assert.equal(afterRevocation, 403);

  assert.deepEqual(JSON.parse(fixture({ action: 'delete-account', user: users[1], organizationId: owned.organizationId, confirm: 'delete-disposable-browser-account' })), { ok: true });
  const afterAccountDeletion = await foreign.evaluate(async () => {
    const response = await fetch('/api/control-plane/context'); await response.text(); return response.status;
  });
  assert.equal(afterAccountDeletion, 401);

  const afterDenied = evidence({ organizationId: owned.organizationId, formId: owned.id, tokenName: integrationName });
  assert.deepEqual(afterDenied, beforeDenied, 'Denied, stale-role, revoked-membership and deleted-account requests cannot change data-plane state or audits.');
  assert.deepEqual(await owner.evaluate(() => [localStorage.length, sessionStorage.length]), [0, 0]);
  assert.deepEqual(await foreign.evaluate(() => [localStorage.length, sessionStorage.length]), [0, 0]);
  assert.deepEqual(leaked, []); assert.deepEqual(errors, []);
  await foreignSession.context.close(); await ownerSession.context.close();
});
