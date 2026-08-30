import { test } from 'node:test';
import assert from 'node:assert/strict';
import { submissionFields, submissionFilters } from '../../ui/submissions-app.js';
import { parseJSON } from '../../ui/schema-json.js';

test('submission filters contain only supported selectors and bounded exact schema versions', () => {
  assert.deepEqual(submissionFilters({ organizationId: 'foreign', schemaVersion: '2', receivedFrom: ' 2026-08-30T00:00:00Z ' }), { schemaVersion: 2, receivedFrom: '2026-08-30T00:00:00Z' });
  for (const schemaVersion of ['1.5', '1e0', '-1', '0', '2147483648', '9007199254740993']) assert.throws(() => submissionFilters({ schemaVersion }));
  assert.deepEqual(submissionFilters({ schemaVersion: '', status: '' }), {});
});

test('field presentation uses the accepted schema and preserves exact numbers without applying defaults', () => {
  const detail = { id: '11111111-1111-4111-8111-111111111111', formId: '22222222-2222-4222-8222-222222222222', schemaVersion: 1,
    schema: { properties: { amount: { title: 'Accepted amount', type: 'integer' }, absent: { default: 'must-not-appear' } } },
    data: parseJSON('{"amount":9007199254740993,"nested":{"decimal":0.1234567890123456789},"__proto__":"ordinary data"}') };
  const fields = submissionFields(detail);
  assert.equal(fields[0].label, 'Accepted amount'); assert.equal(fields[0].value, '9007199254740993');
  assert.match(fields[1].value, /0\.1234567890123456789/); assert.equal(fields[2].value, 'ordinary data');
  assert.equal(fields.some(field => field.key === 'absent'), false);
  assert.throws(() => submissionFields({ ...detail, schema: null }));
});
