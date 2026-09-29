import test from 'node:test';
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { randomBytes } from 'node:crypto';
import { chromium } from 'playwright';

test('released account flow registers, verifies, rotates sessions and resets password', { timeout: 120000 }, async () => {
  assert.equal(process.env.GOFORMX_ACCOUNT_REHEARSAL, '1');
  assert.equal(process.env.APP_ENV, 'local');
  const origin = process.env.GOFORMX_ACCOUNT_UI_URL;
  assert.match(origin ?? '', /^http:\/\/127\.0\.0\.1:[0-9]+$/);
  const browser = await chromium.launch();
  try {
    const context = await browser.newContext();
    const page = await context.newPage();
    page.setDefaultTimeout(30000);
    const email = `account-gate-${randomBytes(10).toString('hex')}@example.test`;
    const password = randomBytes(24).toString('hex');
    const newPassword = randomBytes(24).toString('hex');
    const issue = type => {
      try {
        return execFileSync('php', ['tests/BrowserLive/fixtures/account-token.php'], {
          input: JSON.stringify({ email, type }), encoding: 'utf8', stdio: ['pipe', 'pipe', 'pipe'], timeout: 30000,
        }).trim();
      } catch {
        throw new Error('Disposable account token fixture failed; secret output withheld.');
      }
    };
    const status = path => page.evaluate(async path => (await fetch(path, { credentials: 'same-origin', cache: 'no-store' })).status, path);
    const session = async () => (await context.cookies(origin)).find(cookie => /session|sessid/i.test(cookie.name));

    assert.equal((await page.goto(origin + '/register')).status(), 200);
    const anonymous = await session();
    await page.getByLabel('Name').fill('Account gate');
    await page.getByLabel('Email').fill(email);
    await page.getByLabel('Password').fill(password);
    const registration = page.waitForResponse(response => response.url().endsWith('/api/auth/register'));
    await page.getByRole('button', { name: 'Create account' }).click();
    const registered = await registration;
    assert.equal(registered.status(), 201);
    await page.waitForURL(origin + '/verify-email');
    assert.notEqual(await status('/api/control-plane/context'), 200, 'unverified account cannot enter a workspace');

    const verificationToken = issue('email_verification');
    await page.goto(origin + '/verify-email?token=' + encodeURIComponent(verificationToken));
    const verified = page.waitForResponse(response => response.url().endsWith('/api/auth/verify-email'));
    await page.getByRole('button', { name: 'Verify email' }).click();
    assert.equal((await verified).status(), 200);
    await page.waitForURL(origin + '/login');
    assert.notEqual(await status('/api/control-plane/context'), 200, 'verification does not silently sign in an anonymous browser');

    await page.getByLabel('Email').fill(email);
    await page.getByLabel('Password').fill(password);
    const login = page.waitForResponse(response => response.url().endsWith('/api/auth/login'));
    await page.getByRole('button', { name: 'Sign in' }).click();
    assert.equal((await login).status(), 200);
    await page.waitForURL(origin + '/app');
    const authenticated = await session();
    assert.ok(authenticated);
    if (anonymous) assert.notEqual(authenticated.value, anonymous.value, 'login rotates the anonymous session');
    assert.equal(await status('/api/control-plane/context'), 200);

    await page.goto(origin + '/forgot-password');
    await page.getByLabel('Email').fill(email);
    const forgot = page.waitForResponse(response => response.url().endsWith('/api/auth/forgot-password'));
    await page.getByRole('button', { name: 'Send reset link' }).click();
    assert.equal((await forgot).status(), 200);
    const resetToken = issue('password_reset');
    await page.goto(origin + '/reset-password?token=' + encodeURIComponent(resetToken));
    await page.getByLabel('Password', { exact: true }).fill(newPassword);
    await page.getByLabel('Confirm password').fill(newPassword);
    const reset = page.waitForResponse(response => response.url().endsWith('/api/auth/reset-password'));
    await page.getByRole('button', { name: 'Reset password' }).click();
    assert.equal((await reset).status(), 200);
    await page.waitForURL(origin + '/login');
    assert.notEqual(await status('/api/control-plane/context'), 200, 'reset invalidates the prior session');
    await page.getByLabel('Email').fill(email);
    await page.getByLabel('Password').fill(newPassword);
    await page.getByRole('button', { name: 'Sign in' }).click();
    await page.waitForURL(origin + '/app');
    assert.equal(await status('/api/control-plane/context'), 200);
    const logoutStatus = await page.evaluate(async () => {
      const csrf = decodeURIComponent(document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/)?.[1] ?? '');
      return (await fetch('/api/auth/logout', { method: 'POST', headers: { 'X-XSRF-TOKEN': csrf } })).status;
    });
    assert.equal(logoutStatus, 200);
    assert.notEqual(await status('/api/control-plane/context'), 200, 'logout invalidates the active browser session');
  } finally {
    await browser.close();
  }
});
