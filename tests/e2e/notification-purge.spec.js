// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

const { test, expect } = require('@playwright/test');
const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '../..');
const render = view => execFileSync(process.env.PHP_BINARY || 'php', [path.join(root, 'tests/Fixtures/notification-purge-render.php'), view], { encoding: 'utf8' });
const layout = fs.readFileSync(path.join(root, 'include/layout.js'), 'utf8');
const postHelper = layout.match(/^function loadPageUsingPost\([^]*?^\}/m)?.[0];
if (!postHelper) throw new Error('Unable to load the shipped POST helper');

for (const scenario of [
  { view: 'manager', path: '/managers.php', button: '#purge', fields: { action: 'edit', tab: 'logs', id: '3', purge: '1', header: 'false' } },
  { view: 'utilities', path: '/utilities.php', button: '#purge', fields: { action: 'view_snmpagent_events', purge: '1', header: 'false' } },
  { view: 'clog', path: '/clog.php', button: '#pc', fields: { purge_continue: '1', header: 'false', filename: 'cacti.log' } },
]) {
  test(`the rendered ${scenario.view} purge control sends a POST carrying the session token`, async ({ page }) => {
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.route(`**${scenario.path}*`, route => route.fulfill({ contentType: 'text/html', body: route.request().method() === 'GET' ? '<!doctype html><html><body></body></html>' : '<div>Purged</div>' }));
    await page.goto(scenario.path);
    await page.addScriptTag({ path: path.join(root, 'include/js/jquery.js') });
    await page.addScriptTag({ path: path.join(root, 'include/js/jquery-ui.js') });
    await page.addScriptTag({ path: path.join(root, 'include/js/purify.js') });
    await page.addScriptTag({ content: postHelper });
    await page.evaluate(() => {
      window.csrfMagicToken = 'sid:isolated-browser-session,1234567890';
      window.applySkin = () => {};
    });
    await page.setContent(`<div id="main">${render(scenario.view)}</div>`);
    const request = page.waitForRequest(request => new URL(request.url()).pathname === scenario.path);
    await page.locator(scenario.button).click();
    const sent = await request;
    expect(sent.method()).toBe('POST');
    const fields = Object.fromEntries(new URLSearchParams(sent.postData()));
    expect(fields).toEqual({ ...scenario.fields, __csrf_magic: 'sid:isolated-browser-session,1234567890' });
    await expect(page.locator('#main')).toContainText('Purged');
    expect(errors).toEqual([]);
  });
}
