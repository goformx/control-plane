import { createServer } from 'node:http';

if (process.env.GOFORMX_BROWSER_REHEARSAL !== '1' || process.env.APP_ENV !== 'local') process.exit(64);
const upstreamOrigin = process.env.GOFORMX_FAULT_PROXY_UPSTREAM;
if (upstreamOrigin !== 'http://127.0.0.1:18090') process.exit(64);

let armed = null;
const state = { forwarded: 0, dropped: 0, lastDrop: null };
const body = request => new Promise((resolve, reject) => {
  const chunks = []; let length = 0;
  request.on('data', chunk => {
    length += chunk.length;
    if (length > 65536) { reject(new Error('request too large')); request.destroy(); return; }
    chunks.push(chunk);
  });
  request.on('end', () => resolve(Buffer.concat(chunks)));
  request.on('error', reject);
});
const json = (response, status, value) => {
  const encoded = JSON.stringify(value);
  response.writeHead(status, { 'Content-Type': 'application/json', 'Content-Length': Buffer.byteLength(encoded), 'Cache-Control': 'no-store' });
  response.end(encoded);
};

const proxy = createServer(async (request, response) => {
  try {
    const raw = await body(request);
    const headers = { ...request.headers };
    delete headers.host; delete headers.connection; delete headers['content-length'];
    const upstream = await fetch(upstreamOrigin + request.url, {
      method: request.method, headers, body: ['GET', 'HEAD'].includes(request.method) ? undefined : raw,
      redirect: 'manual', signal: AbortSignal.timeout(20000),
    });
    const upstreamBody = Buffer.from(await upstream.arrayBuffer());
    state.forwarded++;
    if (armed && request.method === armed.method && new URL(request.url, upstreamOrigin).pathname === armed.path) {
      state.dropped++;
      state.lastDrop = { method: request.method, path: armed.path, upstreamStatus: upstream.status };
      armed = null;
      response.destroy();
      return;
    }
    const responseHeaders = {};
    for (const name of ['content-type', 'cache-control', 'pragma', 'x-trace-id', 'retry-after']) {
      const value = upstream.headers.get(name); if (value !== null) responseHeaders[name] = value;
    }
    responseHeaders['content-length'] = String(upstreamBody.length);
    response.writeHead(upstream.status, responseHeaders); response.end(upstreamBody);
  } catch { response.destroy(); }
});

const control = createServer(async (request, response) => {
  if (request.socket.remoteAddress !== '127.0.0.1' && request.socket.remoteAddress !== '::ffff:127.0.0.1') {
    json(response, 403, { error: 'forbidden' }); return;
  }
  if (request.method === 'GET' && request.url === '/health') { json(response, 200, { ok: true }); return; }
  if (request.method === 'GET' && request.url === '/state') { json(response, 200, { armed, ...state }); return; }
  if (request.method === 'POST' && request.url === '/arm') {
    try {
      const input = JSON.parse((await body(request)).toString('utf8'));
      const allowedMethod = ['POST', 'PUT'].includes(input.method);
      const allowedPath = input.path === '/v1/service-tokens' || /^\/v1\/forms\/[0-9a-f-]{36}\/webhook$/i.test(input.path);
      if (!allowedMethod || !allowedPath) { json(response, 400, { error: 'invalid_target' }); return; }
      armed = { method: input.method, path: input.path };
      json(response, 200, { armed: true }); return;
    } catch { json(response, 400, { error: 'invalid_json' }); return; }
  }
  json(response, 404, { error: 'not_found' });
});

proxy.listen(18093, '127.0.0.1');
control.listen(18094, '127.0.0.1');

