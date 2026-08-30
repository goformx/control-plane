import { build } from 'esbuild';
import { readFile, readdir, writeFile } from 'node:fs/promises';

const check = process.argv.includes('--check');
const result = await build({
  entryPoints: ['ui/forms-app.js'], outfile: 'public/assets/forms.js', bundle: true,
  format: 'esm', target: 'es2022', minify: true, legalComments: 'eof', write: !check, metafile: true,
});
// Ship the actual license texts for every package included in the browser bundle.
const packages = [...new Set(Object.keys(result.metafile.inputs).map(path => path.replaceAll('\\', '/').match(/^node_modules\/((?:@[^/]+\/)?[^/]+)\//)?.[1]).filter(Boolean))].sort();
let notices = 'Third-party software included in forms.js\n\n';
for (const name of packages) {
  const root = `node_modules/${name}`;
  const metadata = JSON.parse(await readFile(`${root}/package.json`, 'utf8'));
  const file = (await readdir(root)).find(name => /^LICENSE(?:\.(?:md|txt))?$/i.test(name));
  if (!file) throw new Error(`Missing license text for bundled package ${name}`);
  notices += `${name} ${metadata.version}\n${'='.repeat(60)}\n${(await readFile(`${root}/${file}`, 'utf8')).replaceAll('\r\n', '\n').trim()}\n\n`;
}
const noticePath = 'public/assets/forms.LICENSE.txt';
if (check) {
  if (await readFile(noticePath, 'utf8') !== notices) throw new Error('Bundled license notices differ. Run npm run build.');
} else await writeFile(noticePath, notices);
if (check) {
  for (const file of result.outputFiles) {
    if (!Buffer.from(file.contents).equals(await readFile(file.path))) {
      throw new Error('Committed UI bundle differs from source. Run npm run build.');
    }
  }
}
