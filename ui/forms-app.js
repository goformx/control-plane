import { EditorView, basicSetup } from 'codemirror';
import { Compartment, EditorState } from '@codemirror/state';
import { json } from '@codemirror/lang-json';
import { requireJsonSupport } from './schema-json.js';
import { initSubmissions } from './submissions-app.js';
import { addField, errorMessage, integrationExample, PAGE_SIZE, parseOrigins, parseSchema, parseJSON, stringify, publicEndpoints, starterSchema } from './forms-model.js';

const $ = id => document.getElementById(id);
const text = (id, value) => { $(id).textContent = value; };
const state = { organization: null, role: null, form: null, version: null, versions: [], totalVersions: 0, offset: 0, total: 0, busy: false, uncertain: false, sessionExpired: false, schemaBaseline: '', metadataBaseline: '' };
const writable = () => ['owner', 'admin'].includes(state.role) && !state.sessionExpired;
const editable = new Compartment();
let previewTimer;
const editorExtensions = () => [
    basicSetup, json(), EditorView.lineWrapping,
    EditorView.contentAttributes.of({ 'aria-label': 'JSON Schema editor', 'aria-describedby': 'version-note', spellcheck: 'false' }),
    editable.of(EditorState.readOnly.of(true)),
    EditorView.updateListener.of(update => { if (update.docChanged) { queueMicrotask(controls); clearTimeout(previewTimer); previewTimer = setTimeout(renderPreview, 150); } }),
];
const editor = new EditorView({
  parent: $('schema-editor'),
  state: EditorState.create({ doc: stringify(starterSchema(), null, 2), extensions: editorExtensions() }),
});
const schemaText = () => editor.state.doc.toString();
const metadataSnapshot = () => JSON.stringify(['form-name', 'form-title', 'form-description', 'form-origins'].map(id => $(id).value));
const schemaDirty = () => schemaText() !== state.schemaBaseline;
const metadataDirty = () => metadataSnapshot() !== state.metadataBaseline;
const dirty = () => !$('editor-panel').hidden && (schemaDirty() || metadataDirty());
const csrf = () => decodeURIComponent(document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/)?.[1] ?? '');
const publicOrigin = document.querySelector('meta[name="goformx-api-origin"]').content;
const formPath = () => `/api/control-plane/forms/${encodeURIComponent(state.form.id)}`;
const submissions = initSubmissions({ context: () => state, verifyWorkspace });

function clearError() { $('error').hidden = true; text('error-message', ''); $('error-fields').replaceChildren(); $('sign-in').hidden = true; $('verify-email').hidden = true; }
function showError(error) {
  text('notice', ''); text('error-message', error.message); $('error').hidden = false;
  $('error-fields').replaceChildren();
  for (const field of error.fields ?? []) {
    const item = document.createElement('li'); item.textContent = `${String(field.pointer ?? '/')}: ${String(field.message ?? field.code ?? 'Invalid value')}`; $('error-fields').append(item);
  }
  $('sign-in').hidden = error.status !== 401;
  $('verify-email').hidden = !(error.status === 403 && !state.organization);
  $('error').focus();
}
async function api(path, { method = 'GET', body, etag } = {}) {
  const headers = { Accept: 'application/json' };
  if (method !== 'GET') headers['X-XSRF-TOKEN'] = csrf();
  if (body !== undefined) headers['Content-Type'] = method === 'PATCH' ? 'application/merge-patch+json' : 'application/json';
  if (etag) headers['If-Match'] = etag;
  let response, payload;
  try {
    response = await fetch(path, { method, headers, credentials: 'same-origin', cache: 'no-store', redirect: 'error', signal: AbortSignal.timeout(20_000), body: body === undefined ? undefined : stringify(body) });
    payload = parseJSON(await response.text());
  } catch {
    if (method !== 'GET') state.uncertain = true;
    throw new Error(method === 'GET' ? 'Could not load the server response. Try Refresh; your edits are kept.' : 'The request may have succeeded. Your edits are kept. Reload and reconcile the server state before retrying; do not create or publish twice.');
  }
  if (!response.ok) {
    if (response.status === 401) state.sessionExpired = true;
    if (response.status >= 500 && method !== 'GET') state.uncertain = true;
    const error = new Error(errorMessage(response.status, payload) + (state.uncertain ? ' The outcome may be uncertain; reload and reconcile before retrying.' : ''));
    error.status = response.status; error.fields = payload?.error?.fields ?? []; throw error;
  }
  return { ...payload, etag: response.headers.get('ETag') };
}
async function act(work) {
  if (state.busy) return;
  clearError(); text('notice', ''); state.busy = true; controls();
  try { await work(); } catch (error) { showError(error); }
  finally { state.busy = false; controls(); }
}
function controls() {
  submissions.controls();
  const canWrite = writable() && !state.busy && !state.uncertain;
  $('new-form').disabled = !canWrite;
  $('metadata-fields').disabled = !canWrite;
  $('form-name').readOnly = !!state.form;
  $('save-schema').disabled = !canWrite || (!!state.form && !schemaDirty());
  $('save-metadata').disabled = !canWrite || !metadataDirty() || !state.form?.etag || !Array.isArray(state.form?.allowedOrigins);
  $('save-metadata').hidden = !state.form;
  $('read-only').hidden = writable();
  $('format').disabled = !canWrite;
  $('add-field').disabled = !canWrite;
  $('versions').disabled = state.busy;
  $('more-versions').disabled = state.busy;
  $('reload-form').disabled = state.busy;
  $('refresh').disabled = state.busy;
  $('previous').disabled = state.busy || state.offset === 0;
  $('next').disabled = state.busy || state.offset + PAGE_SIZE >= state.total || state.offset >= 10000;
  $('logout').disabled = state.busy;
  $('publish').disabled = !canWrite || !state.version || state.version.state !== 'draft' || dirty();
  $('confirm-publish').disabled = $('publish').disabled;
  text('dirty', dirty() ? 'Unsaved changes — kept in this tab only' : '');
  editor.dispatch({ effects: editable.reconfigure(EditorState.readOnly.of(!canWrite)) });
  for (const button of $('forms-list').querySelectorAll('button')) button.disabled = state.busy;
}
function confirmDiscard() { return !dirty() || window.confirm('Discard unsaved edits? Download or copy your schema first if you need to keep it.'); }
function setSchema(schema) {
  const value = stringify(schema, null, 2); state.schemaBaseline = value;
  // Replacing the state also clears undo history, so one form cannot undo into another.
  editor.setState(EditorState.create({ doc: value, extensions: editorExtensions() }));
  renderPreview();
}
function fillMetadata(form) {
  $('form-name').value = form.name ?? ''; $('form-title').value = form.title ?? '';
  $('form-description').value = form.description ?? '';
  $('form-origins').value = Array.isArray(form.allowedOrigins) ? form.allowedOrigins.join('\n') : '';
  state.metadataBaseline = metadataSnapshot();
}
function metadata() {
  return { title: $('form-title').value, description: $('form-description').value, allowedOrigins: parseOrigins($('form-origins').value) };
}
function reveal() { $('welcome').hidden = true; $('editor-panel').hidden = false; $('editor-heading').focus(); }
function renderPreview() {
  const container = $('preview'); container.replaceChildren();
  let schema;
  try { schema = parseSchema(schemaText()); } catch (error) { container.textContent = error.message; return; }
  if (!schema.properties || typeof schema.properties !== 'object' || Array.isArray(schema.properties)) { container.textContent = 'No top-level properties to preview. Go validates the complete definition when you save.'; return; }
  const fields = Object.entries(schema.properties);
  for (const [name, definition] of fields.slice(0, 30)) {
    const simple = definition && typeof definition === 'object' && ['string', 'number', 'integer', 'boolean'].includes(definition.type) && !definition.$ref && !definition.oneOf && !definition.anyOf && !definition.allOf;
    if (!simple) { const note = document.createElement('p'); note.className = 'preview-note'; note.textContent = `${name}: advanced or unconstrained schema — inspect JSON for its full meaning.`; container.append(note); continue; }
    const label = document.createElement('label'); label.textContent = `${typeof definition.title === 'string' ? definition.title : name}${Array.isArray(schema.required) && schema.required.includes(name) ? ' (required)' : ''}`;
    const input = document.createElement('input'); input.disabled = true;
    input.type = definition.type === 'boolean' ? 'checkbox' : ['number', 'integer'].includes(definition.type) ? 'number' : definition.format === 'email' ? 'email' : 'text';
    label.append(input); container.append(label);
  }
  if (fields.length > 30) { const note = document.createElement('p'); note.textContent = `${fields.length - 30} more fields are available in JSON. Preview is limited to 30 fields.`; container.append(note); }
}
async function listForms() {
  text('list-state', 'Loading forms…'); $('forms-list').replaceChildren();
  try {
    const result = await api(`/api/control-plane/forms?limit=${PAGE_SIZE}&offset=${state.offset}`);
    state.total = result.meta.total;
    for (const form of result.data) {
      const button = document.createElement('button'); button.type = 'button'; button.textContent = form.title;
      button.setAttribute('aria-current', String(state.form?.id === form.id));
      const status = document.createElement('small'); status.textContent = `${form.name} · ${form.status}`; button.append(status);
      button.onclick = () => { if (confirmDiscard()) act(() => openForm(form.id)); }; $('forms-list').append(button);
    }
    text('list-state', result.data.length ? `${state.total} form${state.total === 1 ? '' : 's'}` : 'No forms here yet. Create your first schema.');
    text('page-number', `Page ${state.offset / PAGE_SIZE + 1}`);
  } catch (error) { text('list-state', 'Forms could not be loaded. Use Refresh to retry.'); throw error; }
}
async function loadVersions(append = false) {
  const offset = append ? state.versions.length : 0;
  const result = await api(`${formPath()}/versions?limit=25&offset=${offset}`);
  state.versions = append ? [...state.versions, ...result.data] : result.data;
  state.totalVersions = result.meta.total;
  renderVersions();
}
function renderVersions() {
  $('versions').replaceChildren();
  for (const version of state.versions) { const option = document.createElement('option'); option.value = version.version; option.textContent = `Version ${version.version} · ${version.state}`; $('versions').append(option); }
  $('more-versions').hidden = state.versions.length >= state.totalVersions || state.versions.length > 10000;
  $('versions-label').hidden = false;
  if (state.version) $('versions').value = state.version.version;
}
async function selectVersion(number) {
  const result = await api(`${formPath()}/versions/${number}`); state.version = result.data;
  setSchema(state.version.schema); $('versions').value = number;
  text('version-note', `Viewing saved version ${number} (${state.version.state}). Every saved snapshot is immutable; editing and saving creates a new draft version.`);
  renderPublication();
}
function renderPublication() {
  $('publication').hidden = !state.form; $('integration').hidden = !state.form;
  if (!state.form) return;
  text('form-status', state.form.status === 'published' ? `LIVE · VERSION ${state.form.currentVersion}` : state.form.status.toUpperCase());
  text('publication-note', state.version ? `Selected version ${state.version.version} is ${state.version.state}. ${state.form.status === 'published' ? `Public submissions currently use version ${state.form.currentVersion}.` : 'This form is not accepting public submissions.'}` : 'Select a saved version to review.');
  $('public-key').value = state.form.publicKey;
  const endpoints = publicEndpoints(publicOrigin, state.form.publicKey); $('submission-endpoint').value = endpoints.submissions;
  const live = state.form.status === 'published';
  text('integration-state', live ? `Example pinned to live version ${state.form.currentVersion}.` : 'Publish a version before using this endpoint.');
  text('integration-example', live ? integrationExample(publicOrigin, state.form.publicKey, state.form.currentVersion) : '// Integration example becomes available after explicit publication.');
  $('copy-example').disabled = !live;
}
async function openForm(id) {
  submissions.reset();
  const path = `/api/control-plane/forms/${encodeURIComponent(id)}`;
  // Commit the selected form only after its complete editable snapshot loads.
  // A failed read must not attach the previous form's schema to a new identity.
  const result = await api(path);
  const versions = await api(`${path}/versions?limit=${PAGE_SIZE}&offset=0`);
  if (!versions.data.length) throw new Error('No schema versions returned. Reload before editing.');
  const number = Math.max(...versions.data.map(version => version.version));
  const selected = await api(`${path}/versions/${number}`);
  state.form = { ...result.data, etag: result.etag }; state.version = selected.data;
  state.versions = versions.data; state.totalVersions = versions.meta.total;
  state.uncertain = false; fillMetadata(state.form); setSchema(state.version.schema);
  text('editor-heading', state.form.title); text('save-schema', 'Validate & save new draft');
  $('reload-form').hidden = false; reveal();
  renderVersions();
  text('version-note', `Viewing saved version ${number} (${state.version.state}). Every saved snapshot is immutable; editing and saving creates a new draft version.`);
  renderPublication();
  if (!Array.isArray(state.form.allowedOrigins)) throw new Error('This server does not expose allowed origins (contract 1.1.0 required). Details editing is disabled; do not assume the allowlist is empty.');
  await listForms();
}
function newForm() {
  submissions.reset();
  state.form = null; state.version = null; state.versions = []; state.uncertain = false;
  fillMetadata({ allowedOrigins: [] }); setSchema(starterSchema());
  text('editor-heading', 'New form'); text('form-status', 'NOT SAVED'); text('save-schema', 'Validate & create form');
  text('version-note', 'Save creates a draft. Publishing is a separate, explicit step.');
  $('versions-label').hidden = true; $('more-versions').hidden = true; $('reload-form').hidden = true;
  renderPublication(); reveal(); controls(); $('form-name').focus();
}
async function verifyWorkspace() {
  const context = await api('/api/control-plane/context');
  if (state.organization && state.organization !== context.data.id) throw new Error('The active workspace changed in another tab. Download your edits, then reload this page before continuing.');
  state.organization = context.data.id; state.role = context.data.attributes.role;
  text('organization', context.data.attributes.name); text('role', `${state.role} · Server-authorized workspace`);
}
async function saveSchema() {
  const schema = parseSchema(schemaText());
  await verifyWorkspace();
  if (!state.form) {
    const result = await api('/api/control-plane/forms', { method: 'POST', body: { name: $('form-name').value, ...metadata(), schema } });
    state.form = result.data; setSchema(schema); state.metadataBaseline = metadataSnapshot();
    $('reload-form').hidden = false;
    await openForm(result.data.id); text('notice', 'Form created as a draft. Review it, then publish explicitly.');
  } else {
    const result = await api(`${formPath()}/versions`, { method: 'POST', body: { schema } });
    state.version = result.data; setSchema(result.data.schema);
    // Keep separately edited metadata intact; a schema save does not save details.
    await loadVersions(); await selectVersion(result.data.version);
    text('notice', `Saved draft version ${result.data.version}. Public submissions are unchanged.${metadataDirty() ? ' Your form details still have unsaved changes.' : ''}`);
  }
}
async function saveMetadata() {
  await verifyWorkspace();
  const result = await api(formPath(), { method: 'PATCH', body: metadata(), etag: state.form.etag });
  state.form = { ...result.data, etag: result.etag }; fillMetadata(state.form); text('editor-heading', state.form.title);
  renderPublication(); await listForms(); text('notice', 'Form details saved. Schema publication is unchanged.');
}

$('new-form').onclick = () => { if (confirmDiscard()) { clearError(); newForm(); } };
$('refresh').onclick = () => act(async () => { await verifyWorkspace(); await listForms(); });
$('previous').onclick = () => act(async () => { state.offset -= PAGE_SIZE; await listForms(); });
$('next').onclick = () => act(async () => { state.offset += PAGE_SIZE; await listForms(); });
$('reload-form').onclick = () => { if (confirmDiscard()) act(() => openForm(state.form.id)); };
$('versions').onchange = event => { const number = Number(event.target.value); if (confirmDiscard()) act(() => selectVersion(number)); else event.target.value = state.version.version; };
$('more-versions').onclick = () => act(() => loadVersions(true));
$('metadata-form').onsubmit = event => { event.preventDefault(); if (state.form) act(saveMetadata); else act(saveSchema); };
for (const id of ['form-name', 'form-title', 'form-description', 'form-origins']) $(id).oninput = controls;
$('save-schema').onclick = () => { if (state.form || $('metadata-form').reportValidity()) act(saveSchema); };
$('format').onclick = () => act(() => { const value = stringify(parseSchema(schemaText()), null, 2); editor.dispatch({ changes: { from: 0, to: editor.state.doc.length, insert: value } }); });
$('add-field').onclick = () => act(() => { const value = addField(schemaText(), $('field-name').value, $('field-type').value, $('field-required').checked); editor.dispatch({ changes: { from: 0, to: editor.state.doc.length, insert: value } }); text('notice', 'Field added locally. Validate and save when ready.'); });
$('download').onclick = () => { const url = URL.createObjectURL(new Blob([schemaText()], { type: 'application/schema+json' })); const anchor = document.createElement('a'); anchor.href = url; anchor.download = 'goformx-draft.schema.json'; anchor.click(); setTimeout(() => URL.revokeObjectURL(url), 1000); };
$('publish').onclick = () => { text('publish-summary', `Publish version ${state.version.version} of “${state.form.title}”?`); $('publish-dialog').showModal(); $('cancel-publish').focus(); };
$('cancel-publish').onclick = () => $('publish-dialog').close();
$('confirm-publish').onclick = () => act(async () => {
  await verifyWorkspace(); const number = state.version.version;
  const result = await api(`${formPath()}/versions/${number}/publish`, { method: 'POST' });
  state.version = result.data; state.form.status = 'published'; state.form.currentVersion = number;
  $('publish-dialog').close(); await openForm(state.form.id); await selectVersion(number);
  text('notice', `Version ${number} published. Integration example now targets the live version.`);
});
$('copy-example').onclick = () => act(async () => { await navigator.clipboard.writeText($('integration-example').textContent); text('notice', 'Public integration example copied. Replace the data with your schema fields.'); });
$('logout').onclick = () => { if (confirmDiscard()) act(async () => { await api('/api/auth/logout', { method: 'POST' }); state.schemaBaseline = schemaText(); state.metadataBaseline = metadataSnapshot(); location.assign('/'); }); };
window.addEventListener('beforeunload', event => { if (dirty()) { event.preventDefault(); event.returnValue = ''; } });
document.querySelector('.brand').onclick = event => { if (!confirmDiscard()) event.preventDefault(); };
await act(async () => { requireJsonSupport(); await verifyWorkspace(); await listForms(); });
