import { test } from 'node:test';
import assert from 'node:assert/strict';
import { addField, integrationExample, parseOrigins, parseSchema, parseJSON, publicEndpoints, starterSchema, stringify } from '../../ui/forms-model.js';
import { requireJsonSupport } from '../../ui/schema-json.js';

test('starter is portable JSON Schema, not a renderer schema', () => {
  const schema = starterSchema();
  assert.equal(schema.$schema, 'https://json-schema.org/draft/2020-12/schema');
  assert.equal(schema.type, 'object'); assert.equal(schema.properties.email.format, 'email');
  assert.deepEqual(schema.required, ['email', 'message']);
});
test('schema parsing rejects syntax, duplicate keys, oversized bodies and non-objects', () => {
  for (const value of ['{', 'null', '[]', 'true', '{"type":"object","type":"array"}', '{"description":"' + 'x'.repeat(900001) + '"}']) assert.throws(() => parseSchema(value));
  assert.deepEqual(parseSchema('{"properties":{"anything":{},"deny":false}}'), { properties: { anything: {}, deny: false } });
});
test('large and high-precision numbers survive format, field assistance and API round trips', () => {
  assert.equal(parseJSON('{"version":2}').version, 2, 'ordinary API version/count values remain native numbers');
  const source = '{"type":"object","properties":{"value":{"minimum":9007199254740993,"multipleOf":0.1234567890123456789}}}';
  const edited = addField(source, 'company', 'string', true);
  assert.match(edited, /9007199254740993/); assert.match(edited, /0\.1234567890123456789/);
  assert.match(stringify(parseJSON(edited)), /9007199254740993/);
});

test('special keys and number-like objects remain data, without lossy or injectable round trips', () => {
  const source = String.raw`{"type":"object","properties":{"__proto__":{"type":"string"},"constructor":{},"prototype":{}},"default":{"isLosslessNumber":true,"value":"0,\"injected\":true","rawJSON":"123"},"minimum":9007199254740993}`;
  const parsed = parseJSON(source);
  assert.equal(Object.hasOwn(parsed.properties, '__proto__'), true);
  assert.equal(Object.getPrototypeOf(parsed.properties), Object.prototype);
  assert.equal(stringify(parsed), source);
  assert.equal(Object.hasOwn(JSON.parse(stringify(parsed)), 'injected'), false);
  assert.match(addField(source, 'extra', 'boolean', false), /"__proto__"/);
  assert.match(stringify(parseJSON('{"minimum":1e500,"maximum":1e-500}')), /1e500/);
  for (const duplicate of ['{"a":1,"a":1}', '{"a":{},"a":{}}', '{"a":1,"\\u0061":1}', '{"__proto__":null,"__proto__":null}']) assert.throws(() => parseJSON(duplicate));
  assert.deepEqual(parseJSON('[{"same":1},{"same":2}]'), [{ same: 1 }, { same: 2 }]);
});

test('unsupported JSON runtimes fail closed instead of rounding schemas', t => {
  const nativeParse = JSON.parse;
  t.mock.method(JSON, 'parse', (text, reviver) => nativeParse(text, (key, value) => reviver ? reviver(key, value) : value));
  assert.throws(requireJsonSupport, /current browser/);
});
test('guided fields preserve advanced constructs, reject overwriting, and do not pollute prototypes', () => {
  const source = JSON.stringify({ ...starterSchema(), $defs: { arbitrary: {} }, allOf: [{ required: ['email'] }] });
  const added = parseSchema(addField(source, 'company', 'string', true));
  assert.deepEqual(added.$defs, { arbitrary: {} }); assert.deepEqual(added.allOf, [{ required: ['email'] }]);
  assert.deepEqual(added.properties.company, { type: 'string' });
  assert.throws(() => addField(source, 'email', 'number', false));
  assert.throws(() => addField(source, '__proto__', 'string', false));
  assert.equal({}.polluted, undefined);
});
test('origins are canonical and empty means empty, never wildcard', () => {
  assert.deepEqual(parseOrigins('  https://EXAMPLE.com/\nhttp://localhost:5173\n'), ['https://example.com', 'http://localhost:5173']);
  assert.deepEqual(parseOrigins(' \n'), []);
  for (const value of ['*', 'https://example.com/contact', 'https://user:pass@example.com', 'javascript:alert(1)', 'https://example.com\nhttps://example.com/']) assert.throws(() => parseOrigins(value));
});
test('public examples use documented paths, version and idempotency without management credentials', () => {
  const endpoints = publicEndpoints('https://api.goformx.com', 'gfpk_example');
  assert.equal(endpoints.submissions, 'https://api.goformx.com/v1/public/forms/gfpk_example/submissions');
  const example = integrationExample('https://api.goformx.com', 'gfpk_example', 2);
  assert.match(example, /"Idempotency-Key": idempotencyKey/); assert.match(example, /"X-GoFormX-Schema-Version": "2"/);
  assert.doesNotMatch(example, /Authorization|Bearer|gfst_/);
  for (const origin of ['https://user:secret@example.com', 'http://example.com', 'javascript:alert(1)', 'https://example.com/private']) assert.throws(() => publicEndpoints(origin, 'gfpk_example'));
});
