// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later
const { test, expect } = require('@playwright/test');
const { execFileSync } = require('node:child_process');
const path = require('node:path');
const fs = require('node:fs');
const root = path.resolve(__dirname, '../..');

function render(scenario) {
  return JSON.parse(execFileSync(process.env.FORM_TEST_PHP || 'php', [path.join(root, 'tests/Fixtures/form-renderer-native.php'), JSON.stringify({ ...scenario, browser: true })], { encoding: 'utf8' }));
}

async function load(page, scenario) {
  const { markup, script } = render(scenario);
  await page.route('**/tests/e2e/theme-smoke.html', route => route.fulfill({ contentType: 'text/html', body: '<html><body></body></html>' }));
  await page.goto('/tests/e2e/theme-smoke.html', { waitUntil: 'commit' });
  await page.route('**/include/**', route => route.abort());
  await page.setContent(markup);
  for (const file of ['include/js/jquery.js', 'include/js/jquery-ui.js', 'include/js/js.storage.js', 'include/js/jquery.cookie.js', 'include/js/purify.js', 'include/js/jquery.tablesorter.js']) {
    await page.addScriptTag({ path: path.join(root, file) });
  }
  await page.evaluate(() => {
    window.urlPath = '/';
    window.cactiNonce = '';
    window.CsrfMagic = { end() {} };
    window.cactiVersion = 'fixture';
    window.zoom_i18n_settings = {};
    window.graphs = [];
    window.sessionMessageCancel = 'Cancel';
    window.sessionMessageContinue = 'Continue';
  });
  await page.addScriptTag({ path: path.join(root, 'include/layout.js') });
  await page.addScriptTag({ content: script });
  await page.waitForFunction(() => document.readyState === 'complete');
  // Flush jQuery's ready queue without depending on a timer delay.
  await page.evaluate(() => new Promise(resolve => $(resolve)));
}

test('punctuated form id binds submission and preserves the action URL', async ({ page }) => {
  const errors = [];
  page.on('pageerror', error => errors.push(error.message));
  await load(page, { action: 'user_admin.php?tab=permsg&id=5', id: 'a.b' });
  expect(await page.evaluate(() => formArray['a.b'])).toEqual({ fixture: 'original' });
  await page.evaluate(() => {
    window.posts = [];
    $.post = (url, data) => { posts.push({ url, data }); return { done() {} }; };
    document.getElementById('a.b').requestSubmit();
  });
  expect(await page.evaluate(() => posts)).toEqual([{ url: 'user_admin.php?tab=permsg&id=5&header=false', data: { fixture: 'original' } }]);
  expect(errors).toEqual([]);
});

test('checkFormStatus uses the raw form id to detect changed fields', async ({ page }) => {
  await load(page, { action: 'save.php', id: 'a.b' });
  expect(await page.evaluate(() => checkFormStatus('next.php', 'loadpage'))).toBe(true);
  await page.evaluate(() => { window.warnings = []; window.warningMessage = (...args) => warnings.push(args); document.querySelector('input').value = 'modified'; });
  expect(await page.evaluate(() => checkFormStatus('next.php', 'loadpage'))).toBe(false);
  expect(await page.evaluate(() => warnings)).toEqual([['next.php', 'loadpage', undefined]]);
});

test('orphan form script exits without a selector error or submit handler', async ({ page }) => {
  const errors = [];
  page.on('pageerror', error => errors.push(error.message));
  await load(page, { orphan: true });
  expect(await page.evaluate(() => Object.keys(formArray))).toEqual([]);
  expect(errors).toEqual([]);
});

test('multiselect setting fallback survives browser form serialization', async ({ page }) => {
  const errors = [];
  page.on('pageerror', error => errors.push(error.message));
  await load(page, { multiselect: { value: '', setting: 'b' } });
  await expect(page.locator('#choices')).toHaveValues(['b']);
  expect(await page.evaluate(() => $('#multiselect').serializeArray())).toEqual([{ name: 'choices[]', value: 'b' }]);
  await page.locator('#choices').selectOption(['a', '0']);
  expect(await page.evaluate(() => $('#multiselect').serializeArray())).toEqual([
    { name: 'choices[]', value: 'a' },
    { name: 'choices[]', value: '0' },
  ]);
  expect(errors).toEqual([]);
});

test('change-only edit form binds under nonce CSP and consumes its queue before the next form', async ({ page }) => {
  const errors = [];
  page.on('pageerror', error => errors.push(error.message));
  const fixture = render({ actions: 'change' });
  expect(fixture.nonce).not.toBe('');
  await page.route('**/include/js/jquery.js', route => route.fulfill({
    contentType: 'application/javascript',
    body: fs.readFileSync(path.join(root, 'include/js/jquery.js'), 'utf8'),
  }));
  await page.route('**/tests/e2e/theme-smoke.html', route => route.fulfill({
    contentType: 'text/html',
    headers: { 'Content-Security-Policy': `default-src 'none'; script-src 'nonce-${fixture.nonce}'; style-src 'unsafe-inline'` },
    body: fixture.markup + fixture.second_html,
  }));
  await page.goto('/tests/e2e/theme-smoke.html');
  await page.evaluate(nonce => new Promise((resolve, reject) => {
    const library = document.createElement('script');
    library.nonce = nonce;
    library.src = '/include/js/jquery.js';
    library.onload = resolve;
    library.onerror = reject;
    document.head.append(library);
  }), fixture.nonce);
  // Execute the production-generated fixture script with its actual nonce.
  await page.evaluate(({ nonce, script }) => {
    window.changeCalls = 0;
    const handler = document.createElement('script');
    handler.nonce = nonce;
    handler.textContent = script;
    document.head.append(handler);
  }, { nonce: fixture.nonce, script: fixture.script });
  await page.locator('#choice').uncheck();
  expect(await page.evaluate(() => window.changeCalls)).toBe(1);
  await page.locator('#choice').check();
  expect(await page.evaluate(() => window.changeCalls)).toBe(2);
  await page.locator('#next_choice').uncheck();
  expect(await page.evaluate(() => window.changeCalls)).toBe(2);
  await page.evaluate(() => {
    const blocked = document.createElement('script');
    blocked.nonce = 'wrong-nonce';
    blocked.textContent = 'window.changeCalls += 1000;';
    document.head.append(blocked);
  });
  expect(await page.evaluate(() => window.changeCalls)).toBe(2);
  expect(errors).toEqual([]);
});
