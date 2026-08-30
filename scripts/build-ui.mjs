import { build } from 'esbuild';
import { readFile } from 'node:fs/promises';

const check = process.argv.includes('--check');
const result = await build({
  entryPoints: ['ui/forms-app.js'], outfile: 'public/assets/forms.js', bundle: true,
  format: 'esm', target: 'es2022', minify: true, legalComments: 'eof', write: !check,
});
if (check) {
  for (const file of result.outputFiles) {
    if (!Buffer.from(file.contents).equals(await readFile(file.path))) {
      throw new Error('Committed UI bundle differs from source. Run npm run build.');
    }
  }
}
