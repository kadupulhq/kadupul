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

// A small DOM with the parts the form pass uses. Getters mirror the reflected
// properties that older csrf-magic builds read directly.
function pageDom({ submitter = true } = {}) {
  const nodes = [];
  const listeners = [];
  const attribute = (node, name) => Element.prototype.getAttribute.call(node, name);
  class Element {
    constructor(tagName, attributes = {}, form = null) {
      Object.assign(this, { tagName, attributes: { ...attributes }, form });
      nodes.push(this);
    }
    getAttribute(name) { return Object.hasOwn(this.attributes, name) ? this.attributes[name] : null; }
    setAttribute(name, value) { this.attributes[name] = String(value); }
    appendChild(child) { child.form = this; return child; }
    get method() { return (attribute(this, 'method') || 'get').toLowerCase(); }
    get action() { return new URL(attribute(this, 'action') || page, page).href; }
    get elements() {
      return Object.fromEntries(nodes.filter(node => node.form === this).map(node => [attribute(node, 'name'), node]));
    }
  }
  const document = {
    baseURI: page,
    getElementsByTagName: tag => nodes.filter(node => node.tagName === tag),
    getElementsByName: name => nodes.filter(node => attribute(node, 'name') === name),
    querySelectorAll(selector) {
      assert.equal(selector, '[formaction]');
      return nodes.filter(node => attribute(node, 'formaction') !== null);
    },
    createElement: tag => new Element(tag),
    addEventListener: (type, listener) => listeners.push([type, listener]),
  };
  const scope = { document, Element };
  if (submitter) {
    scope.SubmitEvent = class { get submitter() { return null; } };
  }
  return {
    Element,
    listeners,
    load: () => load(scope),
    form: (attributes, controls = []) => {
      const form = new Element('form', { method: 'post', ...attributes });
      for (const control of controls) {
        new Element(control.tag || 'input', control, form);
      }
      return form;
    },
    // What a submission would carry: associated, enabled token fields.
    submit(form, button = null) {
      for (const [type, listener] of listeners) {
        if (type === 'submit') listener({ target: form, submitter: button });
      }
      return nodes.some(node => node.form === form && attribute(node, 'name') === field && !node.disabled);
    },
    // A submit event from a browser without SubmitEvent.submitter.
    submitUnknown(form) {
      for (const [type, listener] of listeners) {
        if (type === 'submit') listener({ target: form });
      }
      return nodes.some(node => node.form === form && attribute(node, 'name') === field && !node.disabled);
    },
    button: (form, attributes) => new Element('button', { type: 'submit', ...attributes }, form),
  };
}

test('the load-time form pass adds the token to same-origin POST forms only', () => {
  const dom = pageDom();
  const local = [...sameOrigin, null, ''].map(action => dom.form(action === null ? {} : { action }));
  const foreign = crossOrigin.map(action => dom.form({ action }));
  const get = dom.form({ method: 'get', action: 'graphs.php' });
  dom.load().CsrfMagic.end();
  local.forEach(form => assert.equal(dom.submit(form), true, form.getAttribute('action')));
  [...foreign, get].forEach(form => assert.equal(dom.submit(form), false, form.getAttribute('action')));
});

test('a control named action does not hide a cross-origin form action', () => {
  const dom = pageDom();
  const foreign = dom.form({ action: 'https://evil.example/collect' }, [{ name: 'action', value: 'save' }]);
  const local = dom.form({ action: 'graphs.php' }, [{ name: 'action', value: 'save' }]);
  // The browser returns the control, whose string form is a relative path.
  for (const form of [foreign, local]) {
    Object.defineProperty(form, 'action', { value: { toString: () => '[object HTMLInputElement]' } });
  }
  // A control named getAttribute shadows the method as well.
  Object.defineProperty(local, 'getAttribute', { value: {} });
  dom.load().CsrfMagic.end();
  assert.equal(dom.submit(foreign), false);
  assert.equal(dom.submit(local), true);
});

test('a cross-origin formaction submitter does not carry the token', () => {
  const dom = pageDom();
  const form = dom.form({ action: 'graphs.php' });
  const foreign = dom.button(form, { formaction: 'https://evil.example/collect' });
  const local = dom.button(form, { formaction: '/kadupul/graphs.php' });
  const plain = dom.button(form, {});
  dom.load().CsrfMagic.end();
  assert.equal(dom.submit(form, foreign), false);
  assert.equal(dom.submit(form, local), true);
  assert.equal(dom.submit(form, plain), true);
  assert.equal(dom.submit(form), true);
});

test('without SubmitEvent.submitter a form with a cross-origin formaction gets no token', () => {
  const dom = pageDom({ submitter: false });
  const form = dom.form({ action: 'graphs.php' });
  dom.button(form, { formaction: 'https://evil.example/collect' });
  const other = dom.form({ action: 'graphs.php' });
  dom.button(other, { formaction: 'graphs.php' });
  dom.load().CsrfMagic.end();
  assert.equal(dom.submit(form), false);
  assert.equal(dom.submit(other), true);
});

test('without SubmitEvent.submitter a server-rendered token is withheld from a form with a cross-origin formaction', () => {
  const dom = pageDom({ submitter: false });
  const rendered = [{ name: field, value: token, type: 'hidden' }];
  const form = dom.form({ action: 'graphs.php' }, rendered);
  dom.button(form, { formaction: 'https://evil.example/collect' });
  const outside = dom.form({ action: 'graphs.php', id: 'f' }, rendered);
  dom.button(outside, { formaction: '//evil.example/collect', form: 'f' });
  const foreign = dom.form({ action: 'https://evil.example/collect' }, rendered);
  const other = dom.form({ action: 'graphs.php' }, rendered);
  dom.button(other, { formaction: 'graphs.php' });
  dom.load();
  assert.equal(dom.submitUnknown(form), false);
  assert.equal(dom.submitUnknown(outside), false);
  assert.equal(dom.submitUnknown(foreign), false);
  assert.equal(dom.submitUnknown(other), true);
});

// An unclosed <plaintext>, <textarea>, <title>, <xmp> or comment after a form
// puts the CsrfMagic.end() call into text, so the browser never runs it while
// the form still holds the token the server added.
test('the submit check works when CsrfMagic.end() never runs', () => {
  const dom = pageDom();
  const form = dom.form({ action: 'graphs.php', id: 'f' }, [{ name: field, value: token, type: 'hidden' }]);
  const inside = dom.button(form, { formaction: '//evil.example/collect' });
  // <button form="f"> outside the form is associated with it the same way.
  const outside = dom.button(form, { formaction: 'https://evil.example/collect', form: 'f' });
  const plain = dom.button(form, {});
  dom.load();
  assert.equal(dom.listeners.filter(([type]) => type === 'submit').length, 1);
  assert.equal(dom.submit(form, inside), false);
  assert.equal(dom.submit(form, outside), false);
  assert.equal(dom.submit(form, plain), true);
});

test('CsrfMagic.end() does not add a second submit listener', () => {
  const dom = pageDom();
  dom.load().CsrfMagic.end();
  assert.equal(dom.listeners.filter(([type]) => type === 'submit').length, 1);
});

test('a server-rendered token is withheld from a cross-origin submission', () => {
  const dom = pageDom();
  const form = dom.form({ action: 'https://evil.example/collect' }, [{ name: field, value: token, type: 'hidden' }]);
  dom.load().CsrfMagic.end();
  assert.equal(dom.submit(form), false);
});

test('relative XHR URLs resolve against the document base', () => {
  class Request {
    open() {}
    send(data) { this.sent = data; }
    setRequestHeader() {}
  }
  load({ XMLHttpRequest: Request, document: { baseURI: 'https://evil.example/' } });
  const request = new Request();
  request.open('POST', 'graphs.php', true);
  request.send('action=save');
  assert.equal(request.sent, 'action=save');
});

test('an element named baseURI does not hide the document base', () => {
  const send = scope => {
    class Request {
      open() {}
      send(data) { this.sent = data; }
      setRequestHeader() {}
    }
    load({ XMLHttpRequest: Request, ...scope });
    const request = new Request();
    request.open('POST', 'graphs.php', true);
    request.send('action=save');
    return request.sent;
  };
  // A page containing <img name="baseURI"> makes document.baseURI return the element.
  const clobbered = base => {
    class Node { get baseURI() { return base; } }
    const document = Object.create(Node.prototype);
    Object.defineProperty(document, 'baseURI', { value: { tagName: 'IMG' } });
    return { Node, document };
  };
  assert.equal(send(clobbered(page)), withToken);
  assert.equal(send(clobbered('https://evil.example/')), 'action=save');
  // Without the prototype getter a shadowed value is not trusted.
  assert.equal(send({ document: { baseURI: { tagName: 'IMG' } } }), 'action=save');
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
