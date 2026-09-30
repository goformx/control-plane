import { spawn, spawnSync } from 'node:child_process';
import { createServer } from 'node:net';
import { mkdtemp, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { basename, dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

export async function startPublicServer() {
  const root = fileURLToPath(new URL('../../../', import.meta.url));
  const probe = createServer();
  await new Promise(resolve => probe.listen(0, '127.0.0.1', resolve));
  const port = probe.address().port;
  await new Promise(resolve => probe.close(resolve));
  const origin = `http://127.0.0.1:${port}`;
  const directory = await mkdtemp(join(tmpdir(), 'goformx-website-'));
  const env = { ...process.env, APP_ENV: 'local', APP_DEBUG: 'false', APP_URL: origin,
    WAASEYAA_DB: join(directory, 'website.sqlite'), WAASEYAA_APP_SECRET: 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
    WAASEYAA_JWT_SECRET: 'public-website-disposable-fixture', GOFORMX_API_URL: 'http://127.0.0.1:1',
    GOFORMX_PUBLIC_API_URL: 'http://127.0.0.1:1' };
  const bootstrap = spawnSync('php', ['vendor/bin/waaseyaa', 'install:init'], { cwd: root, env, encoding: 'utf8' });
  if (bootstrap.status !== 0) throw new Error(`Disposable website bootstrap failed: ${bootstrap.status}`);
  const server = spawn('php', ['-S', `127.0.0.1:${port}`, '-t', 'public', 'public/index.php'], { cwd: root, env, stdio: 'ignore' });
  const stop = async () => {
    if (server.exitCode === null) { server.kill(); await new Promise(resolve => server.once('exit', resolve)); }
    // Delete only this newly created, empty-user fixture, never a project or quarantined journey directory.
    if (resolve(dirname(directory)) !== resolve(tmpdir()) || !basename(directory).startsWith('goformx-website-')) throw new Error('Unsafe fixture cleanup path');
    await rm(directory, { recursive: true, force: true });
  };
  try {
    for (let attempt = 0; attempt < 100; attempt++) {
      try { const response = await fetch(origin); if (response.ok) return { origin, stop }; } catch {}
      if (server.exitCode !== null) throw new Error('Public website server stopped');
      await new Promise(resolve => setTimeout(resolve, 100));
    }
    throw new Error('Public website server did not become ready');
  } catch (error) { await stop(); throw error; }
}
