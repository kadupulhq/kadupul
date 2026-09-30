// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

import test from 'node:test';
import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';

// Exercise the file the legacy installer wrote, not a copy of the fix.
const path = 'include/vendor/csrf/csrf-magic.js';
const source = readFileSync(new URL(`../../${path}`, import.meta.url));
const manifest = JSON.parse(readFileSync(new URL('../../tools/dependencies/legacy-files.json', import.meta.url), 'utf8'));
const token = 'sid:0123456789abcdef,1700000000';
const field = '__csrf_magic';
const page = 'https://kadupul.example/kadupul/graphs.php';

function load(scope) {
  const window = Object.assign({ location: new URL(page) }, scope);
  window.window = window;
  runInNewContext(`var csrfMagicName = ${JSON.stringify(field)}; var csrfMagicToken = ${JSON.stringify(token)};\n${source}`,
    Object.assign(window, { URL }));
  return window;
}

function xhrBody(method, url, body = 'action=save') {
  class Request {
    open(...args) { this.opened = args; }
    send(data) { this.sent = data; }
    setRequestHeader() {}
  }
  load({ XMLHttpRequest: Request });
  const request = new Request();
  request.open(method, url, true);
  request.send(body);
  return request.sent;
}

const withToken = `${field}=${token}&action=save`;
const sameOrigin = ['graphs.php', '/kadupul/graphs.php', '?action=save', '//kadupul.example/kadupul/graphs.php',
  'https://kadupul.example/kadupul/graphs.php', 'https://KADUPUL.example:443/kadupul/graphs.php'];
const crossOrigin = ['https://other.example/', '//other.example/collect', 'https://kadupul.example:8443/kadupul/graphs.php',
  'http://kadupul.example/kadupul/graphs.php', 'https://kadupul.example.other.example/'];

test('the installed csrf-magic.js is the pinned artifact', () => {
  assert.equal(createHash('sha256').update(source).digest('hex'), manifest.files[path]);
});

test('same-origin XHR posts carry the token', () => {
  for (const url of sameOrigin) {
    assert.equal(xhrBody('POST', url), withToken, url);
  }
  assert.equal(xhrBody('POST', 'graphs.php', ''), `${field}=${token}`);
});

test('cross-origin XHR posts do not carry the token', () => {
  for (const url of crossOrigin) {
    assert.equal(xhrBody('POST', url), 'action=save', url);
  }
});

test('GET requests never carry the token', () => {
  for (const url of [...sameOrigin, ...crossOrigin]) {
    assert.equal(xhrBody('GET', url, null), null, url);
  }
});

test('a request reopened for another origin drops the pending token', () => {
  class Request {
    open() {}
    send(data) { this.sent = data; }
    setRequestHeader() {}
  }
  load({ XMLHttpRequest: Request });
  const request = new Request();
  request.open('POST', 'graphs.php', true);
  request.open('POST', 'https://other.example/', true);
  request.send('action=save');
  assert.equal(request.sent, 'action=save');
});

test('the load-time form pass adds the token to same-origin POST forms only', () => {
  const forms = [...sameOrigin, ...crossOrigin].map(action => ({
    method: 'post', action: new URL(action, page).href, elements: {}, added: [],
    appendChild(input) { this.added.push(input.attributes); },
  }));
  forms.push({ method: 'get', action: page, elements: {}, added: [], appendChild(input) { this.added.push(input.attributes); } });
  const window = load({
    document: {
      getElementsByTagName: () => forms,
      createElement: () => ({ attributes: {}, setAttribute(name, value) { this.attributes[name] = value; } }),
    },
  });
  window.CsrfMagic.end();
  const tokenInput = [{ name: field, value: token, type: 'hidden' }];
  forms.slice(0, sameOrigin.length).forEach(form => assert.deepEqual(form.added, tokenInput, form.action));
  forms.slice(sameOrigin.length).forEach(form => assert.deepEqual(form.added, [], form.action));
});

test('the jQuery fallback adds the token to same-origin posts only', () => {
  const calls = [];
  const jQuery = {
    ajax: settings => calls.push(settings.data),
    ajaxSettings: {},
    extend: (_deep, target, ...rest) => Object.assign(target, ...rest),
    param: data => new URLSearchParams(data).toString(),
  };
  const window = load({ jQuery });
  window.jQuery.ajax({ type: 'POST', url: '/kadupul/graphs.php', data: 'action=save' });
  window.jQuery.ajax({ type: 'POST', url: 'https://other.example/', data: 'action=save' });
  window.jQuery.ajax({ type: 'GET', url: '/kadupul/graphs.php', data: 'action=save' });
  assert.deepEqual(calls, [withToken, 'action=save', 'action=save']);
});
