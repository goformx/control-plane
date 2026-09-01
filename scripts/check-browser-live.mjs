import assert from 'node:assert/strict';
import { readdir } from 'node:fs/promises';

const files = (await readdir(new URL('../tests/BrowserLive/', import.meta.url)))
  .filter(file => file.endsWith('.test.mjs'));
assert.ok(files.length > 0, 'No live browser tests were found.');
for (const file of files) {
  const suites = ['forms-', 'integrations-'].filter(prefix => file.startsWith(prefix));
  assert.equal(suites.length, 1, `${file} must belong to exactly one isolated live-browser suite.`);
}
