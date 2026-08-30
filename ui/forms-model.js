import { parseJSON, stringify } from './schema-json.js';
export { parseJSON, stringify };
export const SCHEMA_DIALECT = 'https://json-schema.org/draft/2020-12/schema';
export const MAX_SCHEMA_BYTES = 900_000; // Leave room for metadata in the 1 MiB request envelope.
export const PAGE_SIZE = 25;
export const starterSchema = () => ({
  $schema: SCHEMA_DIALECT, type: 'object',
  properties: { email: { type: 'string', format: 'email', title: 'Email address' }, message: { type: 'string', minLength: 1, title: 'Message' } },
  required: ['email', 'message'], additionalProperties: false,
});

export function parseSchema(text) {
  if (new TextEncoder().encode(text).length > MAX_SCHEMA_BYTES) throw new Error('Schema is too large for the editor (900 KB limit).');
  let schema;
  try { schema = parseJSON(text); } catch (error) {
    if (!(error instanceof SyntaxError)) throw error;
    throw new Error('JSON syntax is invalid or keys are duplicated. Check the highlighted editor and try again.');
  }
  if (schema === null || typeof schema !== 'object' || Array.isArray(schema)) throw new Error('A GoFormX form definition must be a JSON object.');
  return schema; // Go, not this helper, validates the schema vocabulary and policy budgets.
}

export function addField(text, name, type, required) {
  const schema = parseSchema(text);
  if (!/^[A-Za-z][A-Za-z0-9_]{0,63}$/.test(name)) throw new Error('Field name must start with a letter and contain only letters, digits or underscores (64 characters maximum).');
  if (!['string', 'number', 'integer', 'boolean'].includes(type)) throw new Error('Choose a supported helper field type. Other schemas can be edited directly.');
  if (schema.type !== 'object' || schema.properties === null || typeof schema.properties !== 'object' || Array.isArray(schema.properties)) throw new Error('Guided fields require an object schema with a properties object. Edit this schema directly.');
  if (Object.hasOwn(schema.properties, name)) throw new Error('That field already exists. Edit it directly to preserve its constraints.');
  Object.defineProperty(schema.properties, name, { value: { type }, enumerable: true, writable: true, configurable: true });
  if (required) {
    if (schema.required !== undefined && !Array.isArray(schema.required)) throw new Error('Fix the required array before adding a required field.');
    schema.required = [...new Set([...(schema.required ?? []), name])];
  }
  return stringify(schema, null, 2);
}

export function parseOrigins(text) {
  const origins = text.split(/\r?\n/).map(value => value.trim()).filter(Boolean);
  const seen = new Set();
  return origins.map(value => {
    let url;
    try { url = new URL(value); } catch { throw new Error('Each allowed origin must be an http:// or https:// origin, one per line.'); }
    if (!['https:', 'http:'].includes(url.protocol) || url.username || url.password || url.search || url.hash || url.pathname !== '/') throw new Error('Allowed origins cannot contain credentials, paths, queries, or fragments.');
    if (seen.has(url.origin)) throw new Error('Allowed origins must not repeat.');
    seen.add(url.origin);
    return url.origin;
  });
}

export function publicEndpoints(origin, publicKey) {
  const url = new URL(origin);
  if (!(url.protocol === 'https:' || (url.protocol === 'http:' && ['127.0.0.1', 'localhost', '[::1]'].includes(url.hostname))) || url.username || url.password || url.search || url.hash || url.pathname !== '/') throw new Error('Public API origin is not configured safely.');
  if (!/^gfpk_[A-Za-z0-9_-]+$/.test(publicKey)) throw new Error('Public form key is not available.');
  const base = `${url.origin}/v1/public/forms/${encodeURIComponent(publicKey)}`;
  return { schema: `${base}/schema`, submissions: `${base}/submissions` };
}

export function integrationExample(origin, publicKey, version) {
  const endpoints = publicEndpoints(origin, publicKey);
  if (!Number.isSafeInteger(version) || version < 1) throw new Error('Choose a published version before copying an integration.');
  return `// Public browser API: no service token or first-party assertion.\n// Replace data with values that satisfy your published schema.\n// Keep this key and body together when retrying the SAME submission.\nconst idempotencyKey = crypto.randomUUID();\nconst data = { /* your field values */ };\nconst response = await fetch(${JSON.stringify(endpoints.submissions)}, {\n  method: "POST",\n  headers: {\n    "Content-Type": "application/json",\n    "Idempotency-Key": idempotencyKey,\n    "X-GoFormX-Schema-Version": "${version}"\n  },\n  body: JSON.stringify({ data })\n});\nconst result = await response.json();\nif (!response.ok) {\n  // Show result.error.fields (JSON Pointers); do not log personal data.\n  throw new Error(result.error?.message || "Submission failed");\n}\n// 202 means accepted; retries with the same key/body are deduplicated.`;
}

export function errorMessage(status, body) {
  if (status === 401) return 'Your session expired. Keep or download your changes, then sign in again.';
  if (status === 412) return 'This form changed elsewhere. Your edits are kept. Copy or download them, then reload the server version to reconcile; nothing was overwritten.';
  return body?.error?.message ?? body?.errors?.[0]?.detail ?? `Request failed (${status}). Your edits are kept.`;
}
