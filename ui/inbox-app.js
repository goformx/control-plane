import { parseJSON, stringify } from './schema-json.js';
import { readBoundedResponse } from './bounded-response.js';
import { submissionFields, submissionFilters } from './submissions-app.js';

const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;
const PAGE_SIZE = 25;

function safeSiteOrigin(value) {
  try {
    const url = new URL(value);
    const loopback = ['localhost', '127.0.0.1', '[::1]'].includes(url.hostname);
    if ((url.protocol !== 'https:' && !(url.protocol === 'http:' && loopback)) || url.username || url.password
        || url.pathname !== '/' || url.search || url.hash || url.origin !== value) return null;
    return url.origin;
  } catch { return null; }
}

export function workspaceInboxFilters(values) {
  const filters = submissionFilters(values);
  for (const key of ['siteId', 'formId']) {
    const value = values[key]?.trim();
    if (value) {
      if (!UUID.test(value)) throw new Error(`${key === 'siteId' ? 'Site' : 'Form'} ID must be a UUID.`);
      filters[key] = value.toLowerCase();
    }
  }
  return filters;
}

export function initWorkspaceInbox({ context, verifyWorkspace, openForm }) {
  const $ = id => document.getElementById(id);
  let generation = 0, controller = null, busy = false, filters = {}, cursor = '', nextCursor = '', previous = [];
  let selected = null, sitesByID = new Map();
  const allowed = () => ['owner', 'admin'].includes(context().role) && !context().sessionExpired;
  const clearDetail = () => {
    $('inbox-detail').hidden = true;
    $('inbox-visit-site').hidden = true; $('inbox-visit-site').removeAttribute('href');
    $('inbox-metadata').replaceChildren(); $('inbox-values').replaceChildren();
    $('inbox-redactions').textContent = ''; $('inbox-schema').textContent = '';
  };
  function controls() {
    const disabled = !allowed() || busy || context().busy;
    $('inbox-access').hidden = allowed();
    $('inbox-filter-fields').disabled = disabled;
    $('inbox-refresh').disabled = disabled;
    $('inbox-open-form').disabled = disabled || !selected;
    $('inbox-previous').disabled = disabled || previous.length === 0;
    $('inbox-next').disabled = disabled || !nextCursor;
    for (const button of $('inbox-list').querySelectorAll('button')) button.disabled = disabled;
    if (!allowed()) { $('inbox-list').replaceChildren(); clearDetail(); selected = null; }
    $('workspace-inbox').setAttribute('aria-busy', String(busy));
  }
  function reset() {
    generation++; controller?.abort(); controller = null; busy = false;
    filters = {}; cursor = ''; nextCursor = ''; previous = []; selected = null; sitesByID = new Map();
    $('inbox-list').replaceChildren(); clearDetail(); $('inbox-filters').reset();
    $('inbox-site').replaceChildren(new Option('All sites', ''));
    $('inbox-error').textContent = ''; $('inbox-error').hidden = true;
    $('inbox-state').textContent = 'Open the inbox to load submissions.';
    controls();
  }
  async function request(path) {
    const response = await fetch(path, { headers: { Accept: 'application/json' }, credentials: 'same-origin',
      cache: 'no-store', redirect: 'error', signal: AbortSignal.any([controller.signal, AbortSignal.timeout(20_000)]) });
    if (!response.ok) {
      const messages = { 400: 'Check the filters and pagination.', 401: 'Your session expired. Sign in again.',
        403: 'Submission access denied. Check your current workspace membership.',
        404: 'The selected site or form is unavailable in this workspace.',
        503: 'The inbox is unavailable. Retry when the service is ready.' };
      throw new Error(messages[response.status] ?? 'Could not load the inbox. Retry when the service is available.');
    }
    return parseJSON(await (await readBoundedResponse(response, 1024 * 1024)).text());
  }
  async function act(work) {
    if (busy || context().busy || !allowed() || $('workspace-inbox').hidden) return;
    const current = ++generation;
    controller?.abort(); controller = new AbortController(); busy = true; controls();
    $('inbox-error').hidden = true; $('inbox-error').textContent = '';
    try {
      const organization = context().organization;
      await verifyWorkspace();
      if (organization !== context().organization || !allowed()) throw new Error('Workspace access changed. Reload before reviewing submissions.');
      if (generation !== current) return;
      await work(() => generation === current && organization === context().organization && allowed());
    } catch (error) {
      if (generation !== current) return;
      $('inbox-list').replaceChildren(); clearDetail(); selected = null; nextCursor = '';
      $('inbox-state').textContent = 'Submissions could not be loaded.';
      $('inbox-error').textContent = error instanceof TypeError || error instanceof SyntaxError ? 'The inbox response was interrupted or invalid. Retry to reload.' : error.message;
      $('inbox-error').hidden = false; $('inbox-error').focus();
    } finally {
      if (generation === current) { busy = false; controller = null; controls(); }
    }
  }
  async function loadSites(active) {
    let offset = 0, total = 0;
    const sites = [];
    do {
      const result = await request(`/api/control-plane/sites?limit=100&offset=${offset}`);
      if (!active()) return;
      if (!Array.isArray(result.data) || result.meta?.organizationId !== context().organization || !Number.isSafeInteger(result.meta.total)) throw new Error('The site list does not match this workspace.');
      total = result.meta.total;
      for (const site of result.data) {
        if (!UUID.test(site.id) || site.organizationId !== context().organization || typeof site.name !== 'string' || typeof site.origin !== 'string') throw new Error('The site list does not match this workspace.');
        sites.push(site);
      }
      if (result.data.length === 0 && offset < total) throw new Error('The site list is incomplete.');
      offset += result.data.length;
      if (offset > 10000 && offset < total) throw new Error('The site list is too large to filter safely.');
    } while (offset < total);
    const selectedSite = filters.siteId ?? '';
    sitesByID = new Map(sites.map(site => [site.id, site]));
    $('inbox-site').replaceChildren(new Option('All sites', ''));
    for (const site of sites) $('inbox-site').add(new Option(`${site.name} · ${site.origin}`, site.id));
    if (sites.some(site => site.id === selectedSite)) $('inbox-site').value = selectedSite;
    else if (selectedSite) { delete filters.siteId; cursor = ''; nextCursor = ''; previous = []; }
  }
  async function list(active) {
    $('inbox-state').textContent = 'Loading submissions…'; $('inbox-list').replaceChildren(); clearDetail(); selected = null;
    const query = new URLSearchParams({ ...filters, limit: String(PAGE_SIZE), ...(cursor ? { cursor } : {}) });
    const result = await request(`/api/control-plane/submissions?${query}`);
    if (!active()) return;
    if (!Array.isArray(result.data) || !result.meta || (result.meta.nextCursor !== null && typeof result.meta.nextCursor !== 'string')) throw new Error('Inbox pagination metadata is unavailable.');
    nextCursor = result.meta.nextCursor ?? '';
    for (const row of result.data) {
      if (!UUID.test(row.id) || !UUID.test(row.formId) || (row.siteId !== null && !UUID.test(row.siteId))
          || typeof row.formName !== 'string' || typeof row.formTitle !== 'string'
          || (filters.siteId && row.siteId !== filters.siteId) || (filters.formId && row.formId !== filters.formId)) throw new Error('The inbox response does not match the selected workspace filters.');
      const button = document.createElement('button'); button.type = 'button';
      button.textContent = `${row.submittedAt} · ${row.formTitle} (${row.formName}) · ${row.siteId ?? 'Unassigned site'} · schema v${row.schemaVersion} · ${row.id}`;
      button.onclick = () => act(active => detail(row, active)); $('inbox-list').append(button);
    }
    $('inbox-state').textContent = result.data.length ? `${result.data.length} ${result.data.length === 1 ? 'submission' : 'submissions'} · page ${previous.length + 1}` : 'No submissions match these filters.';
  }
  async function detail(row, active) {
    clearDetail(); selected = row;
    $('inbox-state').textContent = 'Loading accepted submission snapshot…';
    const result = await request(`/api/control-plane/forms/${encodeURIComponent(row.formId)}/submissions/${encodeURIComponent(row.id)}`);
    if (!active() || selected !== row) return;
    const data = result.data;
    if (data?.id !== row.id || data.formId !== row.formId || data.schemaVersion !== row.schemaVersion) throw new Error('The accepted snapshot does not match this inbox row.');
    const fields = submissionFields(data);
    const site = sitesByID.get(row.siteId);
    const siteOrigin = site ? safeSiteOrigin(site.origin) : null;
    if (siteOrigin) { $('inbox-visit-site').href = siteOrigin; $('inbox-visit-site').hidden = false; }
    for (const [label, value] of [['Form', `${row.formTitle} (${row.formName})`], ['Site ID', row.siteId ?? 'Unassigned'],
      ['Submission ID', data.id], ['Received', data.submittedAt], ['Accepted schema version', String(data.schemaVersion)],
      ['Acceptance status', data.status], ['Acceptance request ID', data.requestId]]) {
      const term = document.createElement('dt'), definition = document.createElement('dd');
      term.textContent = label; definition.textContent = value; $('inbox-metadata').append(term, definition);
    }
    for (const field of fields) {
      const term = document.createElement('dt'), definition = document.createElement('dd');
      term.textContent = `${field.label} (${field.key} · ${field.type})`; definition.textContent = field.value;
      $('inbox-values').append(term, definition);
    }
    $('inbox-redactions').textContent = data.redactedPaths?.length ? `Redacted by accepted schema policy: ${data.redactedPaths.join(', ')}` : 'No sensitive-field annotations apply to this accepted version.';
    $('inbox-schema').textContent = stringify(data.schema, null, 2);
    $('inbox-detail').hidden = false; $('inbox-detail-heading').focus();
    $('inbox-state').textContent = `Viewing accepted schema version ${data.schemaVersion}.`;
  }
  $('inbox-filters').onsubmit = event => {
    event.preventDefault();
    const values = Object.fromEntries(new FormData($('inbox-filters')));
    act(async active => { filters = workspaceInboxFilters(values); cursor = ''; nextCursor = ''; previous = []; await list(active); });
  };
  $('inbox-refresh').onclick = () => act(async active => {
    filters = workspaceInboxFilters(Object.fromEntries(new FormData($('inbox-filters'))));
    cursor = ''; nextCursor = ''; previous = [];
    await loadSites(active); if (active()) await list(active);
  });
  $('inbox-next').onclick = () => act(async active => { previous.push(cursor); cursor = nextCursor; await list(active); });
  $('inbox-previous').onclick = () => act(async active => { cursor = previous.pop() ?? ''; await list(active); });
  $('inbox-open-form').onclick = () => { if (selected && allowed()) openForm(selected.formId); };
  window.addEventListener('pagehide', reset);
  document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'hidden') reset(); });
  reset();
  return { reset, controls, open: () => act(async active => { await loadSites(active); if (active()) await list(active); }) };
}
