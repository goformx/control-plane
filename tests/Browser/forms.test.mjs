import { test } from 'node:test';
import assert from 'node:assert/strict';
import { chromium } from 'playwright';
import { startServer, FORM_ID } from './fixtures/server.mjs';
import { readSchemaEditor } from '../helpers/editor.mjs';

async function fixture(t, options) {
  const server = await startServer(options); const browser = await chromium.launch();
  const page = await browser.newPage({ viewport: { width: 1280, height: 900 } });
  page.setDefaultTimeout(5000);
  const errors = []; page.on('pageerror', error => errors.push(error.message));
  t.after(async () => { if (t.error) t.diagnostic(await page.locator('#error-message').textContent()); await browser.close(); await server.close(); assert.deepEqual(errors, [], 'No browser runtime errors'); });
  await page.goto(server.url + '/app'); await page.getByRole('heading', { name: 'Test workspace' }).waitFor();
  return { page, data: server.data };
}
async function openForm(page) { await page.getByRole('button', { name: /Contact us/ }).click(); await page.getByText('Viewing saved version 1', { exact: false }).waitFor(); }
async function schemaText(page, value) { await page.getByRole('textbox', { name: 'JSON Schema editor' }).fill(value); }

test('editor test reader includes off-screen lines without using the system clipboard', async t => {
  const { page } = await fixture(t, { populated: true }); await openForm(page);
  const source = JSON.stringify({ type: 'object', properties: Object.fromEntries(Array.from({ length: 400 }, (_, i) => [`field${i}`, { type: 'string' }])) }, null, 2);
  await schemaText(page, source);
  assert.equal(await readSchemaEditor(page), source);
  assert.equal(await page.getByRole('textbox', { name: 'JSON Schema editor' }).innerText() === source, false, 'Fixture must actually exceed the virtual DOM viewport');
});

test('rendered create, guided field, save, immutable versions, explicit publication and public example', async t => {
  const { page, data } = await fixture(t);
  await page.getByText('No forms here yet.', { exact: false }).waitFor();
  await page.getByRole('button', { name: '+ New form', exact: true }).click();
  await page.getByRole('textbox', { name: 'Name', exact: true }).fill('contact');
  await page.getByRole('textbox', { name: 'Title', exact: true }).fill('Contact us');
  await page.getByRole('textbox', { name: 'Allowed browser origins' }).fill('https://example.test');
  await page.getByText('Add a field', { exact: true }).click();
  await page.getByRole('textbox', { name: 'Field name', exact: true }).fill('company');
  await page.getByRole('button', { name: 'Add to schema', exact: true }).click();
  await page.getByRole('button', { name: 'Validate & create form', exact: true }).click();
  await page.getByText('Form created as a draft.', { exact: false }).waitFor();
  assert.equal(data.forms[0].status, 'draft'); assert.deepEqual(data.versions[0].schema.properties.company, { type: 'string' });
  const original = structuredClone(data.versions[0].schema);
  await schemaText(page, JSON.stringify({ ...original, properties: { ...original.properties, extra: { type: 'boolean' } } }));
  await page.getByRole('button', { name: 'Validate & save new draft' }).click();
  await page.getByText('Saved draft version 2.', { exact: false }).waitFor();
  assert.deepEqual(data.versions[0].schema, original); assert.equal(data.forms[0].status, 'draft');
  await page.getByRole('button', { name: 'Review publication' }).click();
  assert.equal(data.requests.filter(r => r.path.endsWith('/publish')).length, 0);
  await page.getByRole('button', { name: 'Cancel', exact: true }).click();
  await page.getByRole('button', { name: 'Review publication' }).click();
  await page.getByRole('button', { name: 'Publish version', exact: true }).click();
  await page.getByText('Version 2 published.', { exact: false }).waitFor();
  assert.equal(data.forms[0].currentVersion, 2);
  assert.match(await page.locator('#integration-example').textContent(), /"X-GoFormX-Schema-Version": "2"/);
  assert.equal(await page.locator('#submission-endpoint').inputValue(), 'https://api.goformx.com/v1/public/forms/gfpk_example/submissions');
  assert.ok(data.requests.filter(r => r.method !== 'GET').every(r => r.headers['x-xsrf-token'] === 'test-csrf' && !r.headers.authorization));
});

test('schema errors retain text and render untrusted messages safely', async t => {
  const { page, data } = await fixture(t, { populated: true }); await openForm(page);
  const source = '{"type":"object","properties":{"anything":{},"payload":{"title":"<img src=x onerror=alert(1)>"}}}';
  await schemaText(page, source);
  data.failure = { method: 'POST', path: `/api/control-plane/forms/${FORM_ID}/versions`, status: 422, body: { error: { message: 'Schema invalid', fields: [{ pointer: '/schema/properties/payload', message: '<script>alert(1)</script>' }] } } };
  await page.getByRole('button', { name: 'Validate & save new draft' }).click();
  await page.getByText('/schema/properties/payload: <script>alert(1)</script>', { exact: true }).waitFor();
  assert.equal(await page.getByRole('textbox', { name: 'JSON Schema editor' }).innerText(), source);
  assert.equal(await page.locator('#error script, #preview img').count(), 0);
  assert.equal(await page.getByRole('button', { name: 'Review publication' }).isDisabled(), true);
});

test('ETag conflict preserves details and draft; read-only roles cannot mutate', async t => {
  const { page, data } = await fixture(t, { populated: true }); await openForm(page);
  await page.getByRole('textbox', { name: 'Title', exact: true }).fill('Local title'); data.etag = '"elsewhere"';
  await page.getByRole('button', { name: 'Save details', exact: true }).click();
  await page.getByText('This form changed elsewhere.', { exact: false }).waitFor();
  assert.equal(await page.locator('#form-title').inputValue(), 'Local title'); assert.equal(data.forms[0].title, 'Contact us');
  page.once('dialog', dialog => dialog.dismiss()); await page.getByRole('button', { name: 'Reload server version' }).click();
  assert.equal(await page.locator('#form-title').inputValue(), 'Local title');
  data.role = 'member'; await page.getByRole('button', { name: 'Refresh', exact: true }).click();
  await page.getByText('member · Server-authorized workspace').waitFor();
  assert.equal(await page.getByRole('button', { name: 'Save details', exact: true }).isDisabled(), true);
  assert.equal(await page.getByRole('button', { name: '+ New form', exact: true }).isDisabled(), true);
});

test('network-uncertain mutations disable retries until reconciliation; refresh errors remain actionable', async t => {
  const { page, data } = await fixture(t, { populated: true }); await openForm(page);
  await schemaText(page, '{"type":"object","properties":{"newField":{}}}');
  await page.route(`**/api/control-plane/forms/${FORM_ID}/versions`, route => route.abort('failed'));
  await page.getByRole('button', { name: 'Validate & save new draft' }).click();
  await page.getByText('The request may have succeeded.', { exact: false }).waitFor();
  assert.equal(await page.getByRole('button', { name: 'Validate & save new draft' }).isDisabled(), true);
  data.failure = { method: 'GET', path: '/api/control-plane/forms', status: 503, body: { error: { message: 'Temporarily unavailable' } } };
  await page.getByRole('button', { name: 'Refresh', exact: true }).click();
  await page.getByText('Forms could not be loaded.', { exact: false }).waitFor();
});

test('mobile layout, focus, unsaved navigation and session expiry', async t => {
  const { page, data } = await fixture(t, { populated: true }); await page.setViewportSize({ width: 390, height: 844 }); await openForm(page);
  assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth));
  const content = page.getByRole('textbox', { name: 'JSON Schema editor' }); await content.focus(); await page.keyboard.press('Tab');
  assert.equal(await content.evaluate(el => el.contains(document.activeElement)), false, 'Tab must leave the code editor');
  await schemaText(page, '{"type":"object","properties":{"changed":{}}}');
  page.once('dialog', dialog => dialog.dismiss()); await page.getByRole('button', { name: '+ New form', exact: true }).click();
  assert.equal(await page.locator('#editor-heading').textContent(), 'Contact us');
  data.failure = { method: 'GET', path: '/api/control-plane/context', status: 401, body: {} };
  await page.getByRole('button', { name: 'Validate & save new draft' }).click();
  await page.getByRole('link', { name: 'Sign in again' }).waitFor();
  assert.match(await content.innerText(), /changed/);
  assert.equal(await page.getByRole('button', { name: 'Validate & save new draft' }).isDisabled(), true);
});

test('loading and pagination use bounded server pages', async t => {
  const { page, data } = await fixture(t, { populated: true });
  data.forms = Array.from({ length: 26 }, (_, index) => ({ ...data.forms[0], id: String(index), title: `Form ${index}`, name: `form-${index}` }));
  data.delayList = 300;
  await page.getByRole('button', { name: 'Refresh', exact: true }).click();
  await page.getByText('Loading forms…', { exact: true }).waitFor();
  await page.getByText('26 forms', { exact: true }).waitFor();
  assert.equal(await page.locator('#forms-list button').count(), 25);
  await page.getByRole('button', { name: 'Next', exact: true }).click();
  await page.getByText('Page 2', { exact: true }).waitFor();
  assert.equal(await page.locator('#forms-list button').count(), 1);
  assert.equal(await page.getByRole('button', { name: 'Next', exact: true }).isDisabled(), true);
  await page.getByRole('button', { name: 'Previous', exact: true }).click();
  await page.getByText('Page 1', { exact: true }).waitFor();
});

test('acknowledged creation followed by failed reload cannot repeat form creation', async t => {
  const { page, data } = await fixture(t);
  await page.getByRole('button', { name: '+ New form', exact: true }).click();
  await page.getByRole('textbox', { name: 'Name', exact: true }).fill('contact');
  await page.getByRole('textbox', { name: 'Title', exact: true }).fill('Contact us');
  data.failure = { method: 'GET', path: `/api/control-plane/forms/${FORM_ID}`, status: 503, body: { error: { message: 'Readback unavailable' } } };
  await page.getByRole('button', { name: 'Validate & create form' }).click();
  await page.getByText('Readback unavailable', { exact: true }).waitFor();
  assert.equal(data.requests.filter(r => r.method === 'POST' && r.path === '/api/control-plane/forms').length, 1);
  assert.equal(await page.locator('#save-schema').isDisabled(), true);
  await page.getByRole('button', { name: 'Reload server version', exact: true }).click();
  await page.getByText('Viewing saved version 1', { exact: false }).waitFor();
  await schemaText(page, '{"type":"object","properties":{"privateDraft":{}}}');
  page.once('dialog', dialog => dialog.accept());
  await page.getByRole('button', { name: '+ New form', exact: true }).click();
  await page.getByRole('textbox', { name: 'JSON Schema editor' }).focus(); await page.keyboard.press('ControlOrMeta+z');
  assert.doesNotMatch(await page.getByRole('textbox', { name: 'JSON Schema editor' }).innerText(), /privateDraft/);
});

test('failed form switch preserves one coherent identity, metadata and schema snapshot', async t => {
  const { page, data } = await fixture(t, { populated: true }); await openForm(page);
  const secondId = '33333333-3333-4333-8333-333333333333';
  const second = { ...data.forms[0], id: secondId, name: 'second', title: 'Second form' };
  data.forms.push(second);
  await page.getByRole('button', { name: 'Refresh', exact: true }).click();
  await page.getByRole('button', { name: /Second form/ }).waitFor();
  const draft = '{"type":"object","properties":{"privateDraft":{}}}';
  await schemaText(page, draft);
  await page.route(`**/api/control-plane/forms/${secondId}`, route => route.fulfill({ json: { data: second }, headers: { ETag: '"second"' } }));
  await page.route(`**/api/control-plane/forms/${secondId}/versions?*`, route => route.fulfill({ status: 503, json: { error: { message: 'Second schema unavailable' } } }));
  page.once('dialog', dialog => dialog.accept());
  await page.getByRole('button', { name: /Second form/ }).click();
  await page.getByText('Second schema unavailable', { exact: true }).waitFor();
  assert.equal(await page.locator('#editor-heading').textContent(), 'Contact us');
  assert.equal(await page.locator('#form-name').inputValue(), 'contact');
  assert.equal(await page.getByRole('textbox', { name: 'JSON Schema editor' }).innerText(), draft);
  await page.getByRole('button', { name: 'Validate & save new draft' }).click();
  await page.getByText('Saved draft version 2.', { exact: false }).waitFor();
  const writes = data.requests.filter(request => request.method === 'POST');
  assert.deepEqual(writes.map(request => request.path), [`/api/control-plane/forms/${FORM_ID}/versions`]);
  assert.deepEqual(data.versions[1].schema, JSON.parse(draft));
});

test('format and save preserve special property names, object annotations and precise constraints', async t => {
  const { page, data } = await fixture(t, { populated: true }); await openForm(page);
  const source = '{"type":"object","properties":{"__proto__":{"type":"string"},"value":{"minimum":9007199254740993}},"default":{"isLosslessNumber":true,"value":"not a number"}}';
  await schemaText(page, source);
  await page.getByRole('button', { name: 'Format JSON', exact: true }).click();
  const formatted = await page.getByRole('textbox', { name: 'JSON Schema editor' }).innerText();
  assert.match(formatted, /9007199254740993/);
  assert.equal(Object.hasOwn(JSON.parse(formatted).properties, '__proto__'), true);
  await page.getByRole('button', { name: 'Validate & save new draft' }).click();
  await page.getByText('Saved draft version 2.', { exact: false }).waitFor();
  const write = data.requests.find(request => request.method === 'POST' && request.path.endsWith('/versions'));
  assert.match(write.raw, /9007199254740993/);
  assert.deepEqual(write.body.schema.default, { isLosslessNumber: true, value: 'not a number' });
  assert.deepEqual(write.body.schema.properties.__proto__, { type: 'string' });
});
