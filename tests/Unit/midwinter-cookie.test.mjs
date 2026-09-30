// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';

const layoutSource = readFileSync(new URL('../../include/layout.js', import.meta.url), 'utf8');
const themeSource = readFileSync(new URL('../../include/themes/midwinter/main.js', import.meta.url), 'utf8');

function implementation(source, name) {
  const start = source.indexOf(`function ${name}(`);
  assert.ok(start >= 0, `${name}() must exist`);
  let depth = 0;
  for (let offset = source.indexOf('{', start); offset < source.length; offset++) {
    depth += source[offset] === '{' ? 1 : source[offset] === '}' ? -1 : 0;
    if (depth === 0) {
      return source.slice(start, offset + 1);
    }
  }
  throw new Error(`unbalanced ${name}()`);
}
function zoneCookies(protocol) {
  const writes = [];
  const document = {};
  Object.defineProperty(document, 'cookie', { set: value => writes.push(value) });
  runInNewContext(`${implementation(layoutSource, 'setZoneInfo')}\nsetZoneInfo();`,
    { Date, document, urlPath: '/kadupul/', window: { location: { protocol } } });
  return writes;
}
function themeCookie(protocol) {
  const calls = [];
  const scope = { $: { cookie: (...args) => { calls.push(args); return args.length === 1 ? 'dark' : undefined; } },
    urlPath: '/kadupul/', window: { location: { protocol } } };
  runInNewContext(['setCookieValue', 'getCookieValue'].map(name => implementation(themeSource, name)).join('\n'), scope);
  return { scope, calls };
}

test('timezone cookies are strict scoped and Secure over HTTPS', () => {
  const writes = zoneCookies('https:');
  assert.equal(writes.length, 2);
  for (const cookie of writes) {
    assert.match(cookie, /Max-Age=31536000; path=\/kadupul\/; SameSite=Strict; Secure;/);
  }
});

test('the midwinter colour-mode cookie keeps its path and lifetime and is Secure over HTTPS', () => {
  const { scope, calls } = themeCookie('https:');
  scope.setCookieValue('CactiColorMode', 'dark');
  assert.deepEqual(calls[0].slice(0, 2), ['CactiColorMode', 'dark']);
  assert.equal(calls[0][2].expires, 365);
  assert.equal(calls[0][2].path, '/kadupul/;SameSite=Lax');
  assert.equal(calls[0][2].secure, true);
  assert.equal(scope.getCookieValue('CactiColorMode'), 'dark');
});

test('browser helper cookies omit Secure on plain HTTP so the server still receives them', () => {
  for (const cookie of zoneCookies('http:')) {
    assert.doesNotMatch(cookie, /Secure/);
  }
  const { scope, calls } = themeCookie('http:');
  scope.setCookieValue('CactiColorMode', 'light');
  assert.equal(calls[0][2].secure, false);
});

test('an unrecognised scheme does not mark the colour-mode cookie Secure', () => {
  const { scope, calls } = themeCookie('file:');
  scope.setCookieValue('CactiColorMode', 'light');
  assert.equal(calls[0][2].secure, false);
});
