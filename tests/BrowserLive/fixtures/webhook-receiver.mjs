import { createHmac } from 'node:crypto';
import { readFile } from 'node:fs/promises';
import { createServer as createHttpServer } from 'node:http';
import { createServer as createHttpsServer } from 'node:https';
import { pathToFileURL } from 'node:url';

if (process.env.GOFORMX_BROWSER_REHEARSAL !== '1') throw new Error('Browser rehearsal guard is required.');

const receiverHost = process.env.GOFORMX_WEBHOOK_RECEIVER_HOST;
const verifierPath = process.env.GOFORMX_WEBHOOK_VERIFIER;
const certificatePath = process.env.GOFORMX_WEBHOOK_RECEIVER_CERT;
const keyPath = process.env.GOFORMX_WEBHOOK_RECEIVER_KEY;
const currentSecret = process.env.GOFORMX_WEBHOOK_CURRENT_SECRET;
const previousSecret = process.env.GOFORMX_WEBHOOK_PREVIOUS_SECRET;
if (!receiverHost || !verifierPath || !certificatePath || !keyPath || !currentSecret || !previousSecret) {
  throw new Error('Receiver configuration is incomplete.');
}

const { verifyWebhook, WebhookVerificationError } = await import(pathToFileURL(verifierPath).href);
const deliveries = new Map();
const effects = new Set();
let mode = 'retry-once';

function verificationCode(callback) {
  try { callback(); return 'accepted'; }
  catch (error) {
    if (error instanceof WebhookVerificationError) return error.code;
    throw error;
  }
}

function signature(secret, deliveryId, timestamp, body) {
  return 'v1=' + createHmac('sha256', secret).update(`${deliveryId}.${timestamp}.`).update(body).digest('hex');
}

function verify(input) {
  return verifyWebhook({ ...input, signingSecrets: [currentSecret, previousSecret] });
}

function keyLabel(input) {
  if (verificationCode(() => verifyWebhook({ ...input, signingSecrets: [currentSecret] })) === 'accepted') return 'current';
  if (verificationCode(() => verifyWebhook({ ...input, signingSecrets: [previousSecret] })) === 'accepted') return 'previous';
  return 'none';
}

function negativeChecks(input, event) {
  const stale = String(BigInt(input.timestamp) - 301n);
  const differentId = '00000000-0000-4000-8000-000000000001';
  return {
    tamperedBody: verificationCode(() => verify({ ...input, rawBody: Buffer.concat([input.rawBody, Buffer.from(' ')]) })),
    staleTimestamp: verificationCode(() => verify({ ...input, timestamp: stale })),
    wrongKey: verificationCode(() => verifyWebhook({ ...input, signingSecrets: ['wrong-receiver-signing-secret-123456'] })),
    deliveryIdMismatch: verificationCode(() => verify({ ...input, deliveryId: differentId,
      signature: signature(keyLabel(input) === 'current' ? currentSecret : previousSecret, differentId, input.timestamp, input.rawBody) })),
    eventBinding: event.id === input.deliveryId ? 'accepted' : 'rejected',
  };
}

async function body(request) {
  const chunks = [];
  let size = 0;
  for await (const chunk of request) {
    size += chunk.length;
    if (size > 1024 * 1024) throw new Error('Request body exceeded the rehearsal bound.');
    chunks.push(chunk);
  }
  return Buffer.concat(chunks);
}

function publicState() {
  return {
    mode,
    effects: effects.size,
    deliveries: [...deliveries.values()].map(record => ({
      id: record.id,
      submissionId: record.submissionId,
      attempts: record.attempts.length,
      effectCount: record.effectCount,
      duplicateCount: record.duplicateCount,
      keys: record.attempts.map(attempt => attempt.key),
      timestampsDistinct: new Set(record.attempts.map(attempt => attempt.timestamp)).size === record.attempts.length,
      signaturesDistinct: new Set(record.attempts.map(attempt => attempt.signature)).size === record.attempts.length,
      bodiesIdentical: new Set(record.attempts.map(attempt => attempt.bodyDigest)).size === 1,
      negativeChecks: record.negativeChecks,
    })),
  };
}

const receiver = createHttpsServer({ cert: await readFile(certificatePath), key: await readFile(keyPath) }, async (request, response) => {
  if (request.method !== 'POST' || request.url !== '/hooks/goformx') { response.writeHead(404).end(); return; }
  try {
    const rawBody = await body(request);
    const input = {
      deliveryId: request.headers['x-goformx-delivery-id'],
      timestamp: request.headers['x-goformx-timestamp'],
      signature: request.headers['x-goformx-signature'],
      rawBody,
    };
    const event = verify(input);
    const record = deliveries.get(event.id) ?? { id: event.id, submissionId: event.submissionId, attempts: [], effectCount: 0, duplicateCount: 0 };
    record.attempts.push({
      timestamp: input.timestamp,
      signature: input.signature,
      bodyDigest: createHmac('sha256', 'non-secret-rehearsal-digest').update(rawBody).digest('hex'),
      key: keyLabel(input),
    });
    record.negativeChecks ??= negativeChecks(input, event);
    deliveries.set(event.id, record);

    if (mode === 'reject') { response.writeHead(400).end(); return; }
    if (effects.has(event.id)) record.duplicateCount++;
    else { effects.add(event.id); record.effectCount++; }
    if (mode === 'retry-once' && record.attempts.length === 1) { response.writeHead(503).end(); return; }
    response.writeHead(204).end();
  } catch (error) {
    response.writeHead(error instanceof WebhookVerificationError ? 400 : 500).end();
  }
});

const control = createHttpServer(async (request, response) => {
  response.setHeader('Content-Type', 'application/json');
  if (request.method === 'GET' && request.url === '/health') { response.end('{"ok":true}'); return; }
  if (request.method === 'GET' && request.url === '/state') { response.end(JSON.stringify(publicState())); return; }
  if (request.method === 'POST' && request.url === '/mode') {
    try {
      const payload = JSON.parse((await body(request)).toString('utf8'));
      if (!['retry-once', 'reject', 'accept'].includes(payload.mode)) throw new Error('Invalid mode.');
      mode = payload.mode;
      response.end(JSON.stringify({ mode }));
    } catch { response.writeHead(400).end('{"error":"invalid_mode"}'); }
    return;
  }
  response.writeHead(404).end('{"error":"not_found"}');
});

receiver.listen(443, receiverHost);
control.listen(18092, '127.0.0.1');

function shutdown() {
  receiver.close(() => control.close(() => process.exit(0)));
}
process.on('SIGTERM', shutdown);
process.on('SIGINT', shutdown);
