import { parseJSON, stringify } from './schema-json.js';

const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;
const MAX_BYTES = 8 * 1024 * 1024;
const PAGE_SIZE = 25;

export function submissionFilters(values) {
  const filters = {};
  for (const key of ['receivedFrom', 'receivedBefore', 'status']) {
    const value = values[key]?.trim();
    if (value) filters[key] = value;
  }
  if (values.schemaVersion?.trim()) {
    if (!/^[1-9][0-9]{0,9}$/.test(values.schemaVersion.trim())) throw new Error('Schema version must be a positive integer.');
    const version = Number(values.schemaVersion);
    if (version > 2147483647) throw new Error('Schema version is outside the supported range.');
    filters.schemaVersion = version;
  }
  return filters;
}

export function submissionFields(detail) {
  if (!detail || !UUID.test(detail.id) || !UUID.test(detail.formId) || !Number.isSafeInteger(detail.schemaVersion)
      || !detail.schema || typeof detail.schema !== 'object' || Array.isArray(detail.schema)
      || !detail.data || typeof detail.data !== 'object' || Array.isArray(detail.data)) throw new Error('The accepted submission snapshot is unavailable.');
  const properties = detail.schema.properties;
  return Object.entries(detail.data).map(([key, value]) => {
    const definition = properties && Object.hasOwn(properties, key) ? properties[key] : null;
    return { key, label: typeof definition?.title === 'string' ? definition.title : key,
      type: typeof definition?.type === 'string' ? definition.type : 'JSON',
      value: typeof value === 'string' ? value : stringify(value, null, 2) };
  });
}

export function initSubmissions({ context, verifyWorkspace }) {
  const $ = id => document.getElementById(id);
  let selected = null, generation = 0, controller = null, busy = false;
  let filters = {}, cursor = '', nextCursor = '', previous = [];
  const urls = new Set();
  const allowed = () => ['owner', 'admin'].includes(context().role) && !context().sessionExpired;
  const clearDetail = () => { $('submission-detail').hidden = true; $('submission-values').replaceChildren(); $('submission-metadata').replaceChildren(); $('submission-schema').textContent = ''; $('submission-redactions').textContent = ''; $('submission-deliveries').replaceChildren(); };
  function reset() {
    generation++; controller?.abort(); controller = null; busy = false;
    selected = null; filters = {}; cursor = ''; nextCursor = ''; previous = [];
    $('submission-list').replaceChildren(); clearDetail(); $('submission-message').textContent = '';
    $('submission-error').hidden = true; $('submission-error').textContent = '';
    $('submission-filters').reset(); $('submission-state').textContent = 'Load submissions to review received data.';
    for (const url of urls) URL.revokeObjectURL(url); urls.clear();
    controls();
  }
  function controls() {
    $('submissions-panel').hidden = !context().form;
    $('submission-access').hidden = allowed();
    $('submission-filter-fields').disabled = !allowed() || busy || context().busy;
    $('submission-load').disabled = !allowed() || busy || context().busy;
    $('submission-previous').disabled = !allowed() || busy || context().busy || previous.length === 0;
    $('submission-next').disabled = !allowed() || busy || context().busy || !nextCursor;
    for (const format of ['json', 'csv']) $('export-' + format).disabled = !allowed() || busy || context().busy || !selected;
    for (const button of $('submission-list').querySelectorAll('button')) button.disabled = busy || context().busy || !allowed();
    if (!allowed()) { $('submission-list').replaceChildren(); clearDetail(); }
    $('submissions-panel').setAttribute('aria-busy', String(busy));
  }
  async function request(path, { method = 'GET', body, download = false } = {}) {
    const headers = { Accept: download ? '*/*' : 'application/json' };
    if (method === 'POST') {
      headers['Content-Type'] = 'application/json';
      headers['X-XSRF-TOKEN'] = decodeURIComponent(document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/)?.[1] ?? '');
    }
    const response = await fetch(path, { method, headers, body: body === undefined ? undefined : stringify(body),
      credentials: 'same-origin', cache: 'no-store', redirect: 'error', signal: AbortSignal.any([controller.signal, AbortSignal.timeout(20_000)]) });
    if (!response.ok) {
      const messages = { 400: 'Check the timestamp, version and pagination filters.', 401: 'Your session expired. Sign in again.',
        403: 'Submission access denied. Check your current workspace membership.', 404: 'This form or submission is no longer available in your workspace.',
        413: 'Export exceeds its size or row limit. Narrow the filters.', 429: 'Another export is being prepared. Retry shortly.',
        503: 'Submission operations are unavailable. No download was released.', 504: 'Export timed out. Narrow the filters and retry.' };
      throw new Error(messages[response.status] ?? 'Could not load submissions. Retry when the service is available.');
    }
    const blob = await response.blob();
    if (blob.size > MAX_BYTES) throw new Error('The response exceeds the supported size. Narrow the filters.');
    if (download) {
      const id = response.headers.get('X-GoFormX-Export-ID'), length = response.headers.get('Content-Length');
      const type = response.headers.get('Content-Type')?.split(';')[0].trim().toLowerCase();
      if (!UUID.test(id ?? '') || !/^[1-9][0-9]{0,7}$/.test(length ?? '') || Number(length) !== blob.size
          || type !== (body.format === 'csv' ? 'text/csv' : 'application/json')) throw new Error('Download integrity could not be verified. No file was offered.');
      return { blob, id };
    }
    return parseJSON(await blob.text());
  }
  async function act(work) {
    if (busy || context().busy || !allowed() || !context().form) return;
    const current = ++generation;
    controller?.abort(); controller = new AbortController(); busy = true; controls();
    $('submission-error').hidden = true; $('submission-message').textContent = '';
    try {
      await verifyWorkspace();
      if (!allowed()) throw new Error('Your current workspace role does not allow submission access.');
      if (generation !== current) return;
      await work(() => generation === current);
    } catch (error) {
      if (generation !== current) return;
      $('submission-list').replaceChildren(); clearDetail(); selected = null; nextCursor = '';
      $('submission-state').textContent = 'Submissions could not be loaded.';
      $('submission-error').textContent = error instanceof TypeError || error instanceof SyntaxError ? 'The submission response was interrupted or invalid. Retry to reload.' : error.message;
      $('submission-error').hidden = false; $('submission-error').focus();
    } finally {
      if (generation === current) { busy = false; controller = null; controls(); }
    }
  }
  const path = () => `/api/control-plane/forms/${encodeURIComponent(context().form.id)}/submissions`;
  async function list(active) {
    $('submission-state').textContent = 'Loading submissions…'; $('submission-list').replaceChildren(); clearDetail();
    const result = await request(`${path()}?${new URLSearchParams({ ...filters, limit: String(PAGE_SIZE), ...(cursor ? { cursor } : {}) })}`);
    if (!active()) return;
    if (!Array.isArray(result.data) || !result.meta || (result.meta.nextCursor !== null && typeof result.meta.nextCursor !== 'string')) throw new Error('Submission pagination metadata is unavailable.');
    nextCursor = result.meta.nextCursor ?? ''; selected = context().form.id;
    for (const row of result.data) {
      if (!UUID.test(row.id) || row.formId !== selected) throw new Error('The submission response does not match this form.');
      const button = document.createElement('button'); button.type = 'button';
      button.textContent = `${row.submittedAt} · ${row.status} · schema v${row.schemaVersion} · ${row.id}`;
      button.onclick = () => act(active => detail(row.id, active)); $('submission-list').append(button);
    }
    $('submission-state').textContent = result.data.length ? `${result.data.length} submissions · page ${previous.length + 1}` : 'No submissions match these filters.';
  }
  async function detail(id, active) {
    clearDetail();
    const { data } = await request(`${path()}/${encodeURIComponent(id)}`);
    if (!active()) return;
    if (data.id !== id || data.formId !== context().form.id) throw new Error('The submission response does not match this form.');
    const fields = submissionFields(data);
    const metadata = [['Submission ID', data.id], ['Received', data.submittedAt], ['Accepted schema version', String(data.schemaVersion)], ['Acceptance status', data.status], ['Acceptance request ID', data.requestId]];
    for (const [label, value] of metadata) {
      const term = document.createElement('dt'), definition = document.createElement('dd');
      term.textContent = label; definition.textContent = value; $('submission-metadata').append(term, definition);
    }
    for (const field of fields) {
      const term = document.createElement('dt'), definition = document.createElement('dd');
      term.textContent = `${field.label} (${field.key} · ${field.type})`; definition.textContent = field.value;
      $('submission-values').append(term, definition);
    }
    $('submission-redactions').textContent = data.redactedPaths?.length ? `Redacted by accepted schema policy: ${data.redactedPaths.join(', ')}` : 'No sensitive-field annotations apply to this accepted version.';
    $('submission-schema').textContent = stringify(data.schema, null, 2);
    $('submission-detail').hidden = false; $('submission-detail-heading').focus();
    $('submission-deliveries').textContent = 'Loading recent delivery history…';
    try {
      const deliveries = await request(`/api/control-plane/forms/${encodeURIComponent(data.formId)}/deliveries`);
      if (!active()) return;
      if (!Array.isArray(deliveries.data)) throw new Error('Delivery history unavailable.');
      const matching = deliveries.data.filter(delivery => delivery.submissionId === id);
      $('submission-deliveries').replaceChildren();
      if (!matching.length) $('submission-deliveries').textContent = 'No delivery for this submission appears in the recent form-level window. This does not prove it was never delivered.';
      for (const delivery of matching) {
        const item = document.createElement('p');
        item.textContent = `${delivery.status} · ${delivery.attemptCount} attempts · delivery ${delivery.id} · updated ${delivery.updatedAt}${delivery.deliveredAt ? ` · delivered ${delivery.deliveredAt}` : ''}${delivery.lastHttpStatus ? ` · HTTP ${delivery.lastHttpStatus}` : ''}${delivery.lastErrorCategory ? ` · ${delivery.lastErrorCategory}` : ''}`;
        $('submission-deliveries').append(item);
      }
    } catch {
      if (active()) $('submission-deliveries').textContent = 'Recent delivery history is unavailable. Acceptance does not prove delivery.';
    }
  }
  $('submission-filters').onsubmit = event => {
    event.preventDefault();
    const values = Object.fromEntries(new FormData($('submission-filters')));
    act(async active => {
      filters = submissionFilters(values);
      cursor = ''; nextCursor = ''; previous = []; await list(active);
    });
  };
  $('submission-load').onclick = () => $('submission-filters').requestSubmit();
  $('submission-next').onclick = () => act(async active => { previous.push(cursor); cursor = nextCursor; await list(active); });
  $('submission-previous').onclick = () => act(async active => { cursor = previous.pop() ?? ''; await list(active); });
  for (const format of ['json', 'csv']) $('export-' + format).onclick = () => act(async active => {
    const { blob, id } = await request(`${path()}/export`, { method: 'POST', body: { ...filters, format }, download: true });
    if (!active()) return;
    const url = URL.createObjectURL(blob); urls.add(url);
    const anchor = document.createElement('a'); anchor.href = url; anchor.download = `goformx-submissions-${id}.${format}`; anchor.click();
    setTimeout(() => { URL.revokeObjectURL(url); urls.delete(url); }, 1000);
    $('submission-message').textContent = `Download prepared and audited: ${id}. Keep exported personal data secure.`;
  });
  window.addEventListener('pagehide', reset);
  document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'hidden') reset(); });
  reset();
  return { reset, controls };
}
