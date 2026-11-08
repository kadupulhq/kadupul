// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later
const { test, expect } = require('@playwright/test');
const { execFileSync } = require('node:child_process');
const path = require('node:path');

// The CLI fixture renders the shipped entry, Twig blocks, native CSRF filter
// and footer. Browser requests serve that output without installing a database.
async function loginPage(page, scenario) {
  const rendered = JSON.parse(execFileSync('php', ['-d', 'memory_limit=2G',
    path.resolve(__dirname, '../Fixtures/login-page-presentation.php'), JSON.stringify(scenario)], { encoding: 'utf8' }));
  expect(rendered.token_valid).toBe(true);
  const errors = [];
  page.on('pageerror', error => errors.push(error.message));
  await page.addInitScript(() => {
    window.cspViolations = [];
    document.addEventListener('securitypolicyviolation', event => window.cspViolations.push(event.effectiveDirective));
  });
  await page.route('**/monitoring/login.php', route => route.fulfill({
    contentType: 'text/html', headers: { 'Content-Security-Policy': rendered.csp },
    body: route.request().method() === 'GET' ? rendered.page : '<p>Submission received</p>',
  }));
  await page.goto('/monitoring/login.php');
  await page.waitForFunction(() => typeof window.storage !== 'undefined' && document.activeElement?.id.startsWith('login_'));
  expect(errors).toEqual([]);
  expect(await page.evaluate(() => window.cspViolations)).toEqual([]);
  return errors;
}

for (const csp of ['', 'nonce']) {
  test(`native Twig login submits keyboard credentials and CSRF under ${csp || 'default'} CSP`, async ({ page }) => {
    const errors = await loginPage(page, { method: 3, remember: true, csp });
    await expect(page.locator('#login_username')).toBeFocused();
    await expect(page.locator('#login_password')).toHaveValue('');
    await page.locator('#login_username').fill('alice');
    await page.locator('#login_password').fill('fixture-password');
    await page.locator('#realm').selectOption('1');
    await page.locator('#remember_me').check();
    const token = await page.locator('input[name="__csrf_magic"]').inputValue();
    const request = page.waitForRequest(request => request.url().endsWith('/monitoring/login.php') && request.method() === 'POST');
    await page.locator('#login_password').press('Enter');
    const fields = new URLSearchParams((await request).postData());
    expect(Object.fromEntries(fields)).toEqual({ action: 'login', plugin_value: 'retained',
      login_username: 'alice', login_password: 'fixture-password', realm: '1', remember_me: 'on', __csrf_magic: token });
    await expect(page.getByText('Submission received')).toBeVisible();
    await page.goto('/monitoring/login.php');
    await expect(page.locator('#realm')).toHaveValue('1');
    expect(await page.evaluate(() => storage.get('user_realm'))).toBe(1);
    expect(await page.evaluate(() => refreshIsLogout)).toBe(false);
    expect(errors).toEqual([]);
    expect(await page.evaluate(() => window.cspViolations)).toEqual([]);
  });
}

test('native Twig login preserves plugin changes and focus without rendering error markup', async ({ page }) => {
  await loginPage(page, { method: 3, mutate: true });
  await expect(page.locator('#login_username')).toHaveValue('before <tag> & `');
  await expect(page.locator('#login_username')).toBeFocused();
  await expect(page.locator('#realm')).toHaveValue('7');
  await expect(page.locator('.loginErrors')).toHaveText('after <script>not markup</script> & `');
  await expect(page.locator('.loginErrors script')).toHaveCount(0);
  await expect(page.locator('input[name="__csrf_magic"]')).toHaveCount(1);
  await expect(page.locator('#login_password')).toHaveValue('');
});

test('native Twig login focuses the password when a username is retained', async ({ page }) => {
  await loginPage(page, { username: 'alice' });
  await expect(page.locator('#login_password')).toBeFocused();
  await expect(page.locator('#login_username')).toHaveValue('alice');
  await expect(page.locator('#realm, #remember_me')).toHaveCount(0);
});
