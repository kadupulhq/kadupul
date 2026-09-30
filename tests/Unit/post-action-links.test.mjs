// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';

const source = readFileSync(new URL('../../include/layout.js', import.meta.url), 'utf8');

function implementation(name) {
  const start = source.indexOf(`function ${name}(`);
  assert.ok(start >= 0, `${name}() must exist`);
  const end = source.indexOf('\n}\n', start);
  return source.slice(start, end + 2);
}

// Every method returns the same chain, so page code that walks the DOM runs
// without a browser and only the calls under test are recorded.
function chain() {
  const target = function () {};
  const proxy = new Proxy(target, {
    get: (_, key) => (key === 'length' ? 0 : key === Symbol.toPrimitive ? () => '' : () => proxy),
    apply: () => proxy,
  });
  return proxy;
}

function harness() {
  const state = { posts: [], gets: [], pushed: [], redirects: [], errors: [], formStatus: [], loads: [], bound: [], offs: [] };
  const request = { done: null, fail: null };
  const pending = {
    done(callback) { request.done = callback; return pending; },
    fail(callback) { request.fail = callback; return pending; },
  };
  const postLinks = {
    off(event) { state.offs.push(event); state.bound = state.bound.filter(b => b.event !== event); return postLinks; },
    on(event, handler) { state.bound.push({ event, handler }); return postLinks; },
  };
  function $(selector) {
    if (selector === '.cactiPostAction') {
      return postLinks;
    }
    if (selector && typeof selector === 'object' && 'dataset' in selector) {
      return { data: key => selector.dataset[key], attr: key => selector.attributes[key] };
    }
    return chain();
  }
  $.ajaxQ = { abortAll() {} };
  $.get = url => { state.gets.push(url); return pending; };
  $.post = (url, data) => { state.posts.push({ url, data }); return pending; };
  $.param = fields => fields.map(f => encodeURIComponent(f.name) + '=' + encodeURIComponent(f.value)).join('&');

  const location = { href: 'https://example.test/kadupul/cdef.php?action=edit&id=2', origin: 'https://example.test' };
  const scope = {
    $, URL, window: { location, scrollTo() {} }, document: { location },
    csrfMagicToken: 'sid:token,123',
    checkFormStatus: (href, type) => { state.formStatus.push(type); return true; },
    closeDateFilters() {}, clearAllTimeouts() {}, applySkin() {}, handleConsole() {}, cleanHeader: href => href,
    stripHeaderSuppression: href => href, basename: path => path.split('/').pop(), escapeString: value => value,
    pushState: (title, href) => state.pushed.push(href),
    checkForRedirects: (html, href) => { state.redirects.push(href); return false; },
    getPresentHTTPErrorOrRedirect: (html, href) => state.errors.push(href),
    myTitle: 'Kadupul', DOMPurify: { sanitize: value => value }, isMobile: { any: () => null }, Pace: { stop() {} },
  };
  runInNewContext(['navigateToSymfonySites', 'cactiPreparePostRequestFromUrl', 'loadPage'].map(implementation).join('\n'), scope);
  return { scope, state, request };
}

function tableNavHarness() {
  const { scope, state } = harness();
  scope.loadPage = (...args) => state.loads.push(args);
  runInNewContext(implementation('handleTableNav'), scope);
  return { scope, state };
}

function link(dataset, attributes = {}) {
  return { dataset, attributes };
}

test('a post action link loads its data-url by POST in the page', () => {
  const { scope, state } = tableNavHarness();
  scope.handleTableNav();

  assert.equal(state.bound.length, 1);
  assert.equal(state.bound[0].event, 'click.cactiPostAction');

  let prevented = false;
  const element = link({ url: 'cdef.php?action=item_moveup&id=4&cdef_id=2' }, { href: '#' });
  state.bound[0].handler.call(element, { preventDefault: () => { prevented = true; } });

  assert.equal(prevented, true);
  assert.deepEqual(state.loads, [['cdef.php?action=item_moveup&id=4&cdef_id=2', false, true]]);
});

test('a post action without data-url posts nothing', () => {
  const { scope, state } = tableNavHarness();
  scope.handleTableNav();

  for (const element of [link({}, { href: '#' }), link({}, { href: 'cdef.php?action=item_remove&id=4' }), link({ url: '' }, {})]) {
    let prevented = false;
    state.bound[0].handler.call(element, { preventDefault: () => { prevented = true; } });
    assert.equal(prevented, true);
  }

  assert.deepEqual(state.loads, []);
});

test('refreshing the page keeps one post action handler per link', () => {
  const { scope, state } = tableNavHarness();
  scope.handleTableNav();
  scope.handleTableNav();
  scope.handleTableNav();

  assert.deepEqual(state.offs, ['click.cactiPostAction', 'click.cactiPostAction', 'click.cactiPostAction']);
  assert.equal(state.bound.length, 1);
});

test('the post request carries the query as form fields and one CSRF token', () => {
  const { scope } = harness();
  const request = scope.cactiPreparePostRequestFromUrl('cdef.php?action=item_remove&id=4&__csrf_magic=stale&cdef_id=2');
  const fields = [...new URLSearchParams(request.data)];

  assert.equal(request.url, 'https://example.test/kadupul/cdef.php');
  assert.deepEqual(fields, [['__csrf_magic', 'sid:token,123'], ['action', 'item_remove'], ['id', '4'], ['cdef_id', '2']]);
});

test('the CSRF token never goes to another origin', () => {
  const { scope } = harness();
  for (const href of ['https://other.test/kadupul/cdef.php?action=item_remove', '//other.test/cdef.php?action=item_remove', 'http://example.test/cdef.php']) {
    assert.throws(() => scope.cactiPreparePostRequestFromUrl(href), /different origin/, href);
  }

  // These resolve to this origin with a path that starts with //, which on its
  // own would be a protocol-relative URL naming another host.
  for (const href of ['/.//other.test/x', '/./\\other.test/x', 'https://example.test//other.test/x']) {
    const request = scope.cactiPreparePostRequestFromUrl(href);
    assert.equal(new URL(request.url, 'https://example.test/kadupul/cdef.php').origin, 'https://example.test', href);
  }
});

test('loadPage posts a post action and keeps it out of history and redirects', () => {
  const { scope, state, request } = harness();
  scope.loadPage('tree.php?action=tree_down&id=3', false, true);

  assert.deepEqual(state.formStatus, ['post']);
  assert.deepEqual(state.gets, []);
  assert.equal(state.posts.length, 1);
  assert.equal(state.posts[0].url, 'https://example.test/kadupul/tree.php');
  assert.deepEqual([...new URLSearchParams(state.posts[0].data)],
    [['__csrf_magic', 'sid:token,123'], ['action', 'tree_down'], ['id', '3']]);

  request.done('<div>tree list</div>');
  assert.deepEqual(state.pushed, []);
  assert.deepEqual(state.redirects, [undefined]);

  request.fail({});
  assert.deepEqual(state.errors, ['https://example.test/kadupul/cdef.php?action=edit&id=2']);
});

test('ordinary navigation still loads by GET and records history', () => {
  const { scope, state, request } = harness();
  scope.loadPage('cdef.php?action=edit&id=2');

  assert.deepEqual(state.formStatus, ['loadpage']);
  assert.deepEqual(state.posts, []);
  assert.deepEqual(state.gets, ['cdef.php?action=edit&id=2']);

  request.done('<div>edit</div>');
  assert.deepEqual(state.pushed, ['cdef.php?action=edit&id=2']);
  assert.deepEqual(state.redirects, ['cdef.php?action=edit&id=2']);
});

test('continuing past the unsaved form warning replays a post action by POST', () => {
  const form = readFileSync(new URL('../../lib/html_form.php', import.meta.url), 'utf8');
  assert.match(form, /\} else if \(type == 'post'\) \{\s*loadPage\(href, true, true\);/);
});
