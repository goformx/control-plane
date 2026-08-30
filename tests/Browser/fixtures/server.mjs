import { createServer } from 'node:http';
import { readFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import { starterSchema, stringify } from '../../../ui/forms-model.js';

const root = fileURLToPath(new URL('../../../', import.meta.url));
export const FORM_ID = '11111111-1111-4111-8111-111111111111';
export const ORG_ID = '22222222-2222-4222-8222-222222222222';
export async function startServer({ populated = false, role = 'owner', port = 0 } = {}) {
  const data = { role, requests: [], failure: null, delayList: 0, etag: '"revision-1"', revision: 1, forms: populated ? [{ id: FORM_ID, organizationId: ORG_ID, name: 'contact', title: 'Contact us', description: '', publicKey: 'gfpk_example', allowedOrigins: ['https://example.test'], status: 'draft', currentVersion: 1 }] : [], versions: [{ formId: FORM_ID, version: 1, state: 'draft', schema: starterSchema() }] };
  data.submissions = []; data.deliveries = []; data.delaySubmissions = 0;
  const server = createServer(async (request, response) => {
    const url = new URL(request.url, 'http://localhost');
    if (url.pathname === '/app') {
      response.writeHead(200, { 'Content-Type': 'text/html', 'Set-Cookie': 'XSRF-TOKEN=test-csrf; Path=/; SameSite=Lax' });
      response.end((await readFile(`${root}/templates/app.html.twig`, 'utf8')).replace('{{ PUBLIC_API_ORIGIN }}', 'https://api.goformx.com')); return;
    }
    if (['/assets/forms.js', '/assets/forms.css', '/assets/favicon.svg'].includes(url.pathname)) {
      response.writeHead(200, { 'Content-Type': url.pathname.endsWith('.js') ? 'text/javascript' : url.pathname.endsWith('.svg') ? 'image/svg+xml' : 'text/css' }); response.end(await readFile(`${root}/public${url.pathname}`)); return;
    }
    const chunks = []; for await (const chunk of request) chunks.push(chunk);
    const raw = Buffer.concat(chunks).toString(); const body = raw ? JSON.parse(raw) : undefined;
    const record = { method: request.method, path: url.pathname, query: url.search, body, raw, headers: request.headers }; data.requests.push(record);
    const send = (status, payload, headers = {}) => { response.writeHead(status, { 'Content-Type': 'application/json', ...headers }); response.end(stringify(payload)); };
    if (data.failure && data.failure.path === url.pathname && data.failure.method === request.method) {
      const failure = data.failure; data.failure = null;
      if (failure.abort) { request.socket.destroy(); return; }
      send(failure.status, failure.body); return;
    }
    if (url.pathname === '/api/control-plane/context') { send(200, { data: { id: ORG_ID, attributes: { name: 'Test workspace', role: data.role } } }); return; }
    if (url.pathname === '/api/auth/logout') { send(200, { data: {} }); return; }
    if (request.method !== 'GET' && request.headers['x-xsrf-token'] !== 'test-csrf') { send(403, { errors: [{ detail: 'CSRF required' }] }); return; }
    if (url.pathname === '/api/control-plane/forms' && request.method === 'GET') {
      if (data.delayList) await new Promise(resolve => setTimeout(resolve, data.delayList));
      const offset = Number(url.searchParams.get('offset') ?? 0), limit = Number(url.searchParams.get('limit') ?? 25);
      send(200, { data: data.forms.slice(offset, offset + limit), meta: { total: data.forms.length } }); return;
    }
    if (url.pathname === '/api/control-plane/forms' && request.method === 'POST') {
      const form = { ...body, id: FORM_ID, organizationId: ORG_ID, publicKey: 'gfpk_example', status: 'draft', currentVersion: 1 }; delete form.schema;
      data.forms = [form]; data.versions = [{ formId: FORM_ID, version: 1, state: 'draft', schema: body.schema }]; send(201, { data: form }); return;
    }
    const path = `/api/control-plane/forms/${FORM_ID}`;
    if (url.pathname === `${path}/deliveries`) { send(data.role === 'member' ? 403 : 200, { data: data.deliveries, meta: { limit: 20 } }); return; }
    if (url.pathname.startsWith(`${path}/submissions`)) {
      if (data.role === 'member') { send(403, { error: { code: 'denied' } }); return; }
      if (data.delaySubmissions) await new Promise(resolve => setTimeout(resolve, data.delaySubmissions));
      const filters = request.method === 'POST' ? body : Object.fromEntries(url.searchParams);
      const rows = data.submissions.filter(row => !filters.schemaVersion || row.schemaVersion === Number(filters.schemaVersion));
      if (url.pathname.endsWith('/export')) {
        const exportId = '55555555-5555-4555-8555-555555555555';
        const output = body.format === 'json' ? stringify({ data: rows, meta: { exportId, rowCount: rows.length } }) : '"\'id"\r\n' + rows.map(row => `"\'${row.id}"\r\n`).join('');
        response.writeHead(200, { 'Content-Type': body.format === 'csv' ? 'text/csv' : 'application/json', 'Content-Length': Buffer.byteLength(output), 'X-GoFormX-Export-ID': exportId, 'Cache-Control': 'no-store' });
        response.end(output); return;
      }
      if (url.pathname === `${path}/submissions`) {
        const offset = Number(url.searchParams.get('cursor') ?? 0), limit = Number(url.searchParams.get('limit') ?? 25);
        send(200, { data: rows.slice(offset, offset + limit), meta: { limit, nextCursor: rows.length > offset + limit ? String(offset + limit) : null } }); return;
      }
      const row = data.submissions.find(row => row.id === url.pathname.split('/').pop());
      if (row) send(200, { data: row }); else send(404, { error: { code: 'not_found' } }); return;
    }
    if (url.pathname === path && data.forms.length) {
      if (request.method === 'PATCH') {
        if (request.headers['if-match'] !== data.etag) { send(412, { error: { message: 'Stale' } }); return; }
        Object.assign(data.forms[0], body); data.etag = `"revision-${++data.revision}"`;
      }
      send(200, { data: data.forms[0] }, { ETag: data.etag }); return;
    }
    if (url.pathname === `${path}/versions`) {
      if (request.method === 'POST') { const version = { formId: FORM_ID, version: data.versions.length + 1, state: 'draft', schema: body.schema }; data.versions.push(version); send(201, { data: version }); return; }
      send(200, { data: [...data.versions].reverse(), meta: { total: data.versions.length } }); return;
    }
    const versionPath = url.pathname.match(/\/versions\/(\d+)(\/publish)?$/);
    if (versionPath) {
      const version = data.versions.find(version => version.version === Number(versionPath[1]));
      if (!version) { send(404, { error: { message: 'Not found' } }); return; }
      if (versionPath[2]) { version.state = 'published'; data.forms[0].status = 'published'; data.forms[0].currentVersion = version.version; }
      send(200, { data: version }); return;
    }
    send(404, { error: { message: 'Not found' } });
  });
  await new Promise(resolve => server.listen(port, '127.0.0.1', resolve));
  return { data, url: `http://127.0.0.1:${server.address().port}`, close: () => new Promise(resolve => { server.close(resolve); server.closeAllConnections(); }) };
}
