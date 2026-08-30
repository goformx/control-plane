import { jsonLanguage } from '@codemirror/lang-json';
import { isSafeNumber } from 'lossless-json';

export const stringify = JSON.stringify;

export function requireJsonSupport() {
  let hasSource = false;
  JSON.parse('1', (_key, value, context) => { hasSource = context?.source === '1'; return value; });
  if (!hasSource || typeof JSON.rawJSON !== 'function') {
    throw new Error('This editor needs a current browser with lossless JSON support. Update your browser before loading or saving schemas.');
  }
}

export function parseJSON(text) {
  requireJsonSupport();
  // Native parsing creates own data properties, including __proto__. Native
  // raw numbers have an internal brand which untrusted JSON cannot impersonate.
  const result = JSON.parse(text, (_key, value, context) => typeof value === 'number'
    ? (isSafeNumber(context.source) ? value : JSON.rawJSON(context.source))
    : value);
  // JSON.parse accepts duplicate keys. Reuse the editor's syntax tree to reject
  // every duplicate (including identical values and escaped spellings) first.
  // This checks JSON structure only; Go still owns schema validation.
  jsonLanguage.parser.parse(text).iterate({ enter(node) {
    if (node.type.isError) throw new SyntaxError('Invalid JSON syntax.');
    if (node.name !== 'Object') return;
    const keys = new Set();
    for (const property of node.node.getChildren('Property')) {
      const name = property.getChild('PropertyName');
      const key = JSON.parse(text.slice(name.from, name.to));
      if (keys.has(key)) throw new SyntaxError('Duplicate JSON property.');
      keys.add(key);
    }
  } });
  return result;
}
