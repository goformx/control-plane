import assert from 'node:assert/strict';
import { readFile, readdir } from 'node:fs/promises';

const files = (await readdir(new URL('../tests/BrowserLive/', import.meta.url)))
  .filter(file => file.endsWith('.test.mjs'));
assert.ok(files.length > 0, 'No live browser tests were found.');
const suites = new Map([
  ['forms-', { script: 'test:live', matrix: 'browser' }],
  ['integrations-', { script: 'test:live:integrations', matrix: 'integrations' }],
  ['authorization-', { script: 'test:live:authorization', matrix: 'authorization' }],
  ['failures-', { script: 'test:live:failures', matrix: 'failures' }],
]);
for (const file of files) {
  const matches = [...suites.keys()].filter(prefix => file.startsWith(prefix));
  assert.equal(matches.length, 1, `${file} must belong to exactly one isolated live-browser suite.`);
}

const packageJson = JSON.parse(await readFile(new URL('../package.json', import.meta.url), 'utf8'));
const workflow = await readFile(new URL('../.github/workflows/cross-service-boundary.yml', import.meta.url), 'utf8');
const matrix = ['http', ...[...suites.values()].map(suite => suite.matrix)];
assert.ok(workflow.includes(`suite: [${matrix.join(', ')}]`), 'The live-browser matrix must enumerate every isolated suite.');
const workflowSteps = workflow.split(/(?=^ {6}- name: )/m);
for (const [prefix, suite] of suites) {
  assert.equal(typeof packageJson.scripts?.[suite.script], 'string', `${prefix} needs the ${suite.script} package script.`);
  const runLine = new RegExp(`^\\s+run: npm run ${suite.script.replaceAll(':', '\\:')}\\s*$`, 'm');
  assert.ok(workflowSteps.some(step => step.includes(`if: matrix.suite == '${suite.matrix}'`) && runLine.test(step)),
    `${suite.script} must be invoked by its dedicated ${suite.matrix} workflow step.`);
}
