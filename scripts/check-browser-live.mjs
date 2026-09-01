import assert from 'node:assert/strict';
import { readFile, readdir } from 'node:fs/promises';

const files = (await readdir(new URL('../tests/BrowserLive/', import.meta.url)))
  .filter(file => file.endsWith('.test.mjs'));
assert.ok(files.length > 0, 'No live browser tests were found.');
const suites = new Map([
  ['forms-', { script: 'test:live', matrix: 'browser' }],
  ['integrations-', { script: 'test:live:integrations', matrix: 'integrations' }],
  ['authorization-', { script: 'test:live:authorization', matrix: 'authorization' }],
]);
for (const file of files) {
  const matches = [...suites.keys()].filter(prefix => file.startsWith(prefix));
  assert.equal(matches.length, 1, `${file} must belong to exactly one isolated live-browser suite.`);
}

const packageJson = JSON.parse(await readFile(new URL('../package.json', import.meta.url), 'utf8'));
const workflow = await readFile(new URL('../.github/workflows/cross-service-boundary.yml', import.meta.url), 'utf8');
for (const [prefix, suite] of suites) {
  assert.equal(typeof packageJson.scripts?.[suite.script], 'string', `${prefix} needs the ${suite.script} package script.`);
  assert.ok(workflow.includes(`suite: [http, browser, integrations, authorization]`), 'The live-browser matrix must enumerate every isolated suite.');
  assert.ok(workflow.includes(`run: npm run ${suite.script}`), `${suite.script} must be invoked by the cross-service workflow.`);
  assert.ok(workflow.includes(`matrix.suite == '${suite.matrix}'`), `${suite.matrix} must have a dedicated cross-service workflow condition.`);
}
