// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

const { test, expect } = require('@playwright/test');
const { readFileSync } = require('node:fs');
const { execFileSync } = require('node:child_process');
const path = require('node:path');

const root = path.resolve(__dirname, '../..');
const origin = 'http://127.0.0.1:9088';
const nonce = '0123456789abcdefghijklmn';
const token = 'sid:fixture-token,1700000000';

for (const mode of ['', 'nonce']) {
  test(`installed CSRF script preserves effective targets under ${mode || 'default'} CSP`, async ({ page }) => {
    const policy = execFileSync('php', ['-r',
      'require $argv[1]; echo CactiSecureHeaders::buildCspPolicy($argv[2], $argv[3], "https://other.example");',
      path.join(root, 'lib/headers_secure.php'), mode, nonce], { encoding: 'utf8' });
    // The generated policy is response-header data; it is never inserted into HTML.
    const headers = { 'Content-Security-Policy': policy };
    const requests = [];
    await page.route('**/*', async route => {
      const request = route.request();
      const url = new URL(request.url());
      if (url.pathname === '/fixture') {
        await route.fulfill({ contentType: 'text/html', headers, body: `
          <!doctype html><html><body>
          <form id="override" method="post" action="https://other.example/foreign-form">
            <input name="action" value="save"><button formaction="/local-form">Save locally</button>
          </form>
          <script nonce="${nonce}" src="/jquery.js"></script>
          <script nonce="${nonce}">
            var csrfMagicName = '__csrf_magic', csrfMagicToken = '${token}';
            window.nativeXHR = window.XMLHttpRequest;
            jQuery.ajaxSettings.xhr = function() { return new window.nativeXHR(); };
            window.XMLHttpRequest = undefined;
            window.violations = [];
            document.addEventListener('securitypolicyviolation', function(event) { violations.push(event.violatedDirective); });
          </script>
          <script nonce="${nonce}" src="/csrf.js"></script>
          <script nonce="${nonce}">CsrfMagic.end(); CsrfMagic.end();</script>
          </body></html>` });
      } else if (url.pathname === '/jquery.js' || url.pathname === '/csrf.js') {
        const file = url.pathname === '/jquery.js' ? 'include/js/jquery.js' : 'include/vendor/csrf/csrf-magic.js';
        await route.fulfill({ contentType: 'application/javascript', body: readFileSync(path.join(root, file)) });
      } else {
        requests.push({ url: request.url(), method: request.method(), body: request.postData() });
        await route.fulfill({ contentType: 'application/json', body: '{"ok":true}',
          headers: { 'Access-Control-Allow-Origin': origin } });
      }
    });
    await page.goto(`${origin}/fixture`);
    await expect(page.locator('#override input[name="__csrf_magic"]')).toHaveCount(0);
    await page.evaluate(async () => {
      const send = (...args) => new Promise((resolve, reject) => jQuery.ajax(...args).done(resolve).fail(reject));
      jQuery.ajaxSetup({ url: 'https://other.example/foreign-default', type: 'POST' });
      await send({ type: 'POST', data: 'action=save' });
      await send({ url: '/local-default', data: 'action=save' });
      await send('/local-string', { data: 'action=save' });
      await send({ url: '/local-get', method: 'GET', data: 'action=save' });
      jQuery.ajaxSetup({ method: 'POST' });
      await send({ url: '/explicit-get', type: 'GET', data: 'action=save' });
      jQuery.ajaxSetup({ method: 'GET' });
      await send({ url: '/explicit-post', type: 'POST', data: 'action=save' });
    });
    expect(requests).toHaveLength(6);
    expect(requests[0]).toEqual({ url: 'https://other.example/foreign-default', method: 'POST', body: 'action=save' });
    for (const request of requests.slice(1, 3)) {
      expect(request.method).toBe('POST');
      expect(new URLSearchParams(request.body).get('__csrf_magic')).toBe(token);
    }
    expect(requests[3].method).toBe('GET');
    expect(new URL(requests[3].url).searchParams.has('__csrf_magic')).toBe(false);
    expect(requests[4].method).toBe('GET');
    expect(new URL(requests[4].url).searchParams.has('__csrf_magic')).toBe(false);
    expect(requests[5].method).toBe('POST');
    expect(new URLSearchParams(requests[5].body).get('__csrf_magic')).toBe(token);
    expect(await page.evaluate(() => violations)).toEqual([]);
    await page.locator('#override button').click();
    await page.waitForURL(`${origin}/local-form`);
    expect(requests).toHaveLength(7);
    expect(requests[6].method).toBe('POST');
    expect(new URLSearchParams(requests[6].body).get('__csrf_magic')).toBe(token);
    expect(new URLSearchParams(requests[6].body).get('action')).toBe('save');
  });
}


for (const mode of ['', 'nonce']) {
  test(`legacy submitter fallback withholds tokens from GET overrides under ${mode || 'default'} CSP`, async ({ page }) => {
    const policy = execFileSync('php', ['-r',
      'require $argv[1]; echo CactiSecureHeaders::buildCspPolicy($argv[2], $argv[3], "");',
      path.join(root, 'lib/headers_secure.php'), mode, nonce], { encoding: 'utf8' });
    const headers = { 'Content-Security-Policy': policy };
    const requests = [];
    await page.route('**/*', async route => {
      const request = route.request();
      const url = new URL(request.url());
      if (url.pathname === '/fallback-fixture') {
        await route.fulfill({ contentType: 'text/html', headers, body: `
          <!doctype html><html><body>
          <form id="fallback" method="post" action="/fallback-submit">
            <input name="action" value="save">
            <input type="hidden" name="__csrf_magic" value="${token}">
          </form>
          <button form="fallback" formmethod="get">Submit as GET</button>
          <script nonce="${nonce}">
            var csrfMagicName = '__csrf_magic', csrfMagicToken = '${token}';
            window.SubmitEvent = undefined;
            window.violations = [];
            document.addEventListener('securitypolicyviolation', function(event) { violations.push(event.violatedDirective); });
          </script>
          <script nonce="${nonce}" src="/fallback-csrf.js"></script>
          <script nonce="${nonce}">CsrfMagic.end(); CsrfMagic.end();</script>
          </body></html>` });
      } else if (url.pathname === '/fallback-csrf.js') {
        await route.fulfill({ contentType: 'application/javascript', body: readFileSync(path.join(root, 'include/vendor/csrf/csrf-magic.js')) });
      } else {
        requests.push({ url: request.url(), method: request.method(), body: request.postData() });
        await route.fulfill({ contentType: 'text/html', body: '<p>Submitted</p>' });
      }
    });
    await page.goto(`${origin}/fallback-fixture`);
    expect(await page.evaluate(() => violations)).toEqual([]);
    await page.locator('button[form="fallback"]').click();
    await page.waitForURL(url => url.pathname === '/fallback-submit');
    expect(requests).toHaveLength(1);
    expect(requests[0].method).toBe('GET');
    expect(new URL(requests[0].url).searchParams.get('action')).toBe('save');
    expect(new URL(requests[0].url).searchParams.has('__csrf_magic')).toBe(false);
    expect(requests[0].body).toBeNull();
  });
}


for (const mode of ['', 'nonce']) {
  test(`server-rendered forms keep early submit protection when end is swallowed under ${mode || 'default'} CSP`, async ({ page }) => {
    const program = String.raw`
      require $argv[1] . '/lib/headers_secure.php';
      function csrf_startup() {
        csrf_conf('rewrite', false);
        csrf_conf('defer', true);
        csrf_conf('auto-session', false);
        csrf_conf('frame-breaker', false);
        csrf_conf('secret', 'isolated-browser-render-secret');
        csrf_conf('rewrite-js', '/native-csrf.js');
      }
      session_id('browser-render-fixture');
      require $argv[1] . '/include/vendor/csrf/csrf-magic.php';
      $nonce = CactiSecureHeaders::getNonceAttribute();
      $page = '<html><head><script ' . $nonce . '>
        window.submissions = []; window.violations = [];
        document.addEventListener("securitypolicyviolation", function(event) { violations.push(event.violatedDirective); });
        document.addEventListener("submit", function(event) {
          event.preventDefault();
          submissions.push(Array.from(new FormData(event.target).entries()));
        });
      </script></head><body>
      <form id="early" method="post">
        <input name="action" value="save">
        <button id="inside" formaction="https://other.example/collect">Foreign inside</button>
        <button id="local">Local</button>
      </form>
      <button id="outside" form="early" formaction="https://other.example/collect">Foreign outside</button>'
        . $argv[3] . 'open text</body></html>';
      echo json_encode(array(
        'page' => csrf_ob_handler($page, 0),
        'policy' => CactiSecureHeaders::buildCspPolicy($argv[2], CactiSecureHeaders::getNonce(), ''),
        'token' => csrf_get_tokens()
      ));
    `;
    let fixture;
    await page.route('**/*', async route => {
      const url = new URL(route.request().url());
      if (url.pathname === '/native-fixture') {
        const headers = { 'Content-Security-Policy': fixture.policy };
        await route.fulfill({ contentType: 'text/html', headers, body: fixture.page });
      } else if (url.pathname === '/native-csrf.js') {
        await route.fulfill({ contentType: 'application/javascript', body: readFileSync(path.join(root, 'include/vendor/csrf/csrf-magic.js')) });
      } else {
        throw new Error(`Unexpected request: ${url.pathname}`);
      }
    });
    for (const open of ['<plaintext>', '<textarea>', '<title>', '<xmp>', '<!--']) {
      fixture = JSON.parse(execFileSync('php', ['-r', program, root, mode, open], { encoding: 'utf8' }));
      await page.goto(`${origin}/native-fixture`);
      await expect(page.locator('#early input[name="__csrf_magic"]')).toHaveCount(1);
      // No end() pass ran: only the server has inserted this token field.
      await page.locator('#inside').click();
      await page.locator('#outside').click();
      await page.locator('#local').click();
      const submissions = await page.evaluate(() => window.submissions);
      expect(submissions).toHaveLength(3);
      expect(submissions[0]).toEqual([['action', 'save']]);
      expect(submissions[1]).toEqual([['action', 'save']]);
      expect(submissions[2]).toEqual([['__csrf_magic', fixture.token], ['action', 'save']]);
      expect(await page.evaluate(() => violations)).toEqual([]);
    }
  });
}
