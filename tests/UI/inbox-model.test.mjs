import { test } from 'node:test';
import assert from 'node:assert/strict';
import { workspaceInboxFilters } from '../../ui/inbox-app.js';

test('workspace inbox selectors accept only UUIDs and never accept caller organization scope', () => {
  const siteId = '55555555-5555-4555-8555-555555555555';
  const formId = '33333333-3333-4333-8333-333333333333';
  assert.deepEqual(workspaceInboxFilters({ siteId: ` ${siteId} `, formId, schemaVersion: '2', organizationId: 'foreign' }),
    { siteId, formId, schemaVersion: 2 });
  assert.deepEqual(workspaceInboxFilters({ siteId: siteId.toUpperCase(), formId: formId.toUpperCase() }), { siteId, formId });
  for (const value of ['foreign', '00000000-0000-0000-0000-000000000000&organizationId=foreign', '']) {
    if (value) assert.throws(() => workspaceInboxFilters({ siteId: value }));
  }
});
