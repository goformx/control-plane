import { test } from 'node:test';
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { randomUUID } from 'node:crypto';
import { chromium } from 'playwright';

test('real browser login → create → version → publish → public submission, with foreign workspace denial', { timeout: 90000 }, async t => {
  assert.equal(process.env.GOFORMX_BROWSER_REHEARSAL, '1');
  const url = process.env.GOFORMX_CROSS_SERVICE_UI_URL;
  assert.equal(url, 'http://127.0.0.1:18091');
  assert.equal(process.env.GOFORMX_PUBLIC_API_URL, 'http://127.0.0.1:18090');
  const fixture = input => {
    try { return execFileSync('php', ['tests/BrowserLive/fixtures/users.php'], { input: JSON.stringify(input), encoding: 'utf8', stdio: ['pipe', 'pipe', 'pipe'] }); }
    catch { throw new Error('Disposable browser fixture failed; credentials and logs withheld.'); }
  };
  const users = JSON.parse(fixture({ action: 'create' }));
  t.after(() => fixture({ action: 'cleanup', users: users.map(({ id, subject }) => ({ id, subject })) }));
  const browser = await chromium.launch(); t.after(() => browser.close());
  const errors = [], leaked = [];
  async function login(user) {
    const context = await browser.newContext(); const page = await context.newPage(); page.setDefaultTimeout(15000);
    page.on('pageerror', error => errors.push(error.message));
    page.on('request', request => { if (request.headers().authorization) leaked.push(request.url()); });
    await page.goto(url + '/login');
    await page.getByLabel('Email', { exact: true }).fill(user.email);
    await page.getByLabel('Password', { exact: true }).fill(user.password);
    await page.getByRole('button', { name: 'Sign in', exact: true }).click();
    await page.waitForURL(url + '/app'); await page.getByText('owner · Server-authorized workspace').waitFor();
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
  const original = JSON.parse(await schema.innerText());
  const invalid = JSON.stringify({ ...original, type: 'array' });
  await schema.fill(invalid);
  await owner.getByRole('button', { name: 'Validate & save new draft' }).click();
  await owner.locator('#error-fields li').first().waitFor();
  assert.equal(await schema.innerText(), invalid, 'Go validation failure retains the editable schema');
  await schema.fill(JSON.stringify({ ...original, properties: { ...original.properties, unconstrained: {} } }));
  await owner.getByRole('button', { name: 'Validate & save new draft' }).click();
  await owner.getByText('Saved draft version 2.', { exact: false }).waitFor();
  await owner.getByLabel('Saved versions').selectOption('1');
  await owner.getByText('Viewing saved version 1', { exact: false }).waitFor();
  assert.deepEqual(JSON.parse(await schema.innerText()), original);
  await owner.getByLabel('Saved versions').selectOption('2');
  await owner.getByText('Viewing saved version 2', { exact: false }).waitFor();
  await owner.getByRole('button', { name: 'Review publication' }).click();
  await owner.getByRole('button', { name: 'Publish version', exact: true }).click();
  await owner.getByText('Version 2 published.', { exact: false }).waitFor();
  const endpoint = await owner.getByLabel('Submission endpoint').inputValue();
  assert.match(endpoint, /^http:\/\/127\.0\.0\.1:18090\/v1\/public\/forms\/gfpk_[A-Za-z0-9_-]+\/submissions$/);
  const submission = await owner.evaluate(async ({ endpoint, key }) => {
    const options = { method: 'POST', headers: { 'Content-Type': 'application/json', 'Idempotency-Key': key, 'X-GoFormX-Schema-Version': '2' }, body: JSON.stringify({ data: { email: 'browser@example.test', message: 'Synthetic browser gate' } }) };
    const first = await fetch(endpoint, options), second = await fetch(endpoint, options);
    return { statuses: [first.status, second.status], first: await first.json(), second: await second.json() };
  }, { endpoint, key: randomUUID() });
  assert.deepEqual(submission.statuses, [202, 202]); assert.equal(submission.first.data.id, submission.second.data.id);
  const contextData = await owner.evaluate(async () => (await fetch('/api/control-plane/forms')).json());
  const owned = contextData.data.find(form => form.name === name); assert.ok(owned);
  const foreign = await login(users[1]);
  await foreign.getByText('No forms here yet.', { exact: false }).waitFor();
  assert.equal(await foreign.getByRole('button', { name: /Browser release gate/ }).count(), 0);
  const denied = await foreign.evaluate(async id => (await fetch(`/api/control-plane/forms/${id}`)).status, owned.id);
  assert.equal(denied, 404); assert.deepEqual(leaked, []); assert.deepEqual(errors, []);
});
