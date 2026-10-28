// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

const { test, expect } = require('@playwright/test');
const { readFileSync } = require('node:fs');
const { execFileSync } = require('node:child_process');
const path = require('node:path');

const root = path.resolve(__dirname, '../..');
const origin = 'http://127.0.0.1:9088';
const nonce = '0123456789abcdefghijklmn';

// A plugin page reached by in-app navigation answers header=false with no
// head, so the library has to travel inside the partial response.
const libraries = [
  ['sparkline', () => typeof window.jQuery.fn.sparkline === 'function'],
  ['chart', () => typeof window.Chart === 'function'],
];

for (const [library, loaded] of libraries) {
  test(`${library} requested in a header=false response runs under the nonce CSP`, async ({ page }) => {
    const policy = execFileSync('php', ['-r',
      'require $argv[1]; echo CactiSecureHeaders::buildCspPolicy($argv[2], $argv[3], "");',
      path.join(root, 'lib/headers_secure.php'), 'nonce', nonce], { encoding: 'utf8' });
    const partial = execFileSync('php', [path.join(__dirname, 'legacy-js-partial.php'), library, nonce], { encoding: 'utf8' });
    expect(partial).toContain(`nonce="${nonce}"`);

    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.route('**/*', async route => {
      const url = new URL(route.request().url());
      if (url.pathname === '/fixture') {
        await route.fulfill({ contentType: 'text/html', headers: { 'Content-Security-Policy': policy }, body: `
          <!doctype html><html><body><div id="main"></div>
          <script nonce="${nonce}" src="/include/js/jquery.js"></script>
          <script nonce="${nonce}">
            window.violations = [];
            document.addEventListener('securitypolicyviolation', event => violations.push(event.violatedDirective));
          </script></body></html>` });
      } else if (url.pathname === '/partial') {
        await route.fulfill({ contentType: 'text/html', body: partial });
      } else if (url.pathname.startsWith('/include/js/')) {
        await route.fulfill({ contentType: 'application/javascript',
          body: readFileSync(path.join(root, url.pathname)) });
      } else {
        await route.fulfill({ status: 404, body: '' });
      }
    });

    await page.goto(`${origin}/fixture`);
    expect(await page.evaluate(loaded)).toBe(false);
    // Same insertion as the untitled-response branch of loadPageNoHeader() in include/layout.js.
    await page.evaluate(async () => {
      const html = await window.jQuery.get('/partial?header=false');
      window.jQuery('#main').empty().html(html);
    });
    await page.waitForFunction(loaded);
    expect(await page.evaluate(() => violations)).toEqual([]);
    expect(errors).toEqual([]);
  });
}
