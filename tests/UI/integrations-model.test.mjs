import { test } from 'node:test';
import assert from 'node:assert/strict';
import { TOKEN_SCOPES, tokenRequest } from '../../ui/integrations-app.js';

test('token delegation is explicit, bounded and canonical', () => {
  assert.deepEqual(tokenRequest(' Reader ', ['forms:read'], '30'), { name: 'Reader', scopes: ['forms:read'], expiresInSeconds: 2592000 });
  assert.equal(tokenRequest('Full', TOKEN_SCOPES, '365').scopes.length, 8);
  for (const scopes of [[], ['admin:all'], ['forms:read', 'forms:read']]) assert.throws(() => tokenRequest('Test', scopes, 30));
  for (const days of [0, 366, 1.5, 'forever']) assert.throws(() => tokenRequest('Test', ['forms:read'], days));
});
