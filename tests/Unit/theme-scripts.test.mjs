// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createContext, runInContext } from 'node:vm';

const root = new URL('../../', import.meta.url);
const read = path => readFileSync(new URL(path, root), 'utf8');
const layout = read('include/layout.js');

function layoutFunction(name) {
  const start = layout.indexOf(`function ${name}(`);
  assert.ok(start >= 0, `${name}() must exist`);
  const end = layout.indexOf('\nfunction ', start + 10);
  return layout.slice(start, end < 0 ? undefined : end);
}

// A small jQuery stand-in. It models the parts of jQuery the theme scripts
// depend on for the behaviour under test (namespaced events, sibling lookup,
// selectmenu widgets) and treats every other method as a chainable no-op.
function fakeJquery(nodes = {}) {
  const selectors = [];
  const calls = [];

  function bind(node, spec, fn) {
    const [type, ns = ''] = spec.split('.');
    (node.events ||= []).push({ type, ns, fn });
  }

  function unbind(node, spec) {
    if (spec === undefined) {
      node.events = [];
      return;
    }
    const [type, ns = ''] = spec.split('.');
    node.events = (node.events || []).filter(e => !((type === '' || e.type === type) && (ns === '' || e.ns === ns)));
  }

  function wrap(list, selector) {
    const self = {
      length: list.length,
      each(fn) { list.forEach((node, i) => fn.call(node, i, node)); return proxy; },
      on(spec, fn) { list.forEach(node => spec.split(' ').forEach(s => bind(node, s, fn))); return proxy; },
      off(spec) { list.forEach(node => unbind(node, spec)); return proxy; },
      unbind(spec) { list.forEach(node => unbind(node, spec)); return proxy; },
      resize(fn) { list.forEach(node => bind(node, 'resize', fn)); return proxy; },
      trigger(type) { list.forEach(node => (node.events || []).filter(e => e.type === type).forEach(e => e.fn.call(node, { target: node }))); return proxy; },
      add(extra) { return wrap(list.concat(extra), selector); },
      next(filter) {
        const found = list.map(node => node.next).filter(sibling => sibling && (!filter || sibling.matches === filter));
        return wrap(found, `${selector} + ${filter}`);
      },
      after(html) { list.forEach(node => { node.inserted = (node.inserted || 0) + 1; node.next = { html, matches: /fa-search/.test(html) ? 'i.fa-search' : '' }; }); return proxy; },
      prop(name) { return list[0]?.props?.[name]; },
      val(value) { if (value === undefined) return list[0]?.value; list.forEach(node => { node.value = value; }); return proxy; },
      html(value) { list.forEach(node => { node.html = value; }); return proxy; },
      css(name, value) {
        calls.push({ selector, method: 'css', args: [name, value] });
        if (value === undefined && typeof name === 'string') return undefined;
        list.forEach(node => { node.style = { ...node.style, ...(typeof name === 'object' ? name : { [name]: value }) }; });
        return proxy;
      },
      selectmenu(arg) {
        const node = list[0];
        if (typeof arg === 'object') { list.forEach(n => { n.selectmenu = arg; }); return proxy; }
        if (arg === 'menuWidget') return wrap([node.menu ||= {}], `${selector} menu`);
        if (arg === 'instance') return node?.selectmenu ? {} : undefined;
        if (arg === 'close') { node.closes = (node.closes || 0) + 1; }
        return proxy;
      },
      autocomplete(arg) { if (typeof arg === 'object') list.forEach(node => { node.autocomplete = arg; }); return proxy; },
      click(fn) { if (fn) list.forEach(node => bind(node, 'click', fn)); return proxy; },
      closest() { return wrap(list.map(node => node.closest || node), selector); },
      attr(name, value) { if (value === undefined) return list[0]?.attrs?.[name]; return proxy; },
    };
    const proxy = new Proxy(self, {
      get(target, prop) {
        if (prop in target) return target[prop];
        if (typeof prop === 'symbol' || prop === 'then') return undefined;
        return (...args) => { calls.push({ selector, method: prop, args }); return proxy; };
      },
    });
    return proxy;
  }

  function $(target) {
    if (typeof target === 'string') {
      selectors.push(target);
      return wrap(nodes[target] || [], target);
    }
    return wrap(target && typeof target === 'object' ? [target] : [], String(target));
  }

  return { $, selectors, calls };
}

const pageGlobals = [
  'searchFilter', 'searchRFilter', 'noFileSelected', 'urlPath', 'treeView', 'listView', 'previewView',
  'cactiHome', 'cactiProjectPage', 'cactiCommunityForum', 'cactiDocumentation', 'reportABug', 'aboutCacti',
  'cactiVersion', 'justCacti',
];

function loadTheme(theme, nodes = {}, extra = {}) {
  const jq = fakeJquery(nodes);
  const window = extra.window || {};
  const context = createContext({
    $: jq.$,
    jQuery: jq.$,
    window,
    location: {},
    Storages: { localStorage: { isSet: () => false, get: () => null, set: () => {} } },
    basename: () => 'host.php',
    keepWindowSize: () => {},
    setNavigationScroll: () => {},
    ajaxAnchors: () => {},
    applyFilter: () => { context.filtered = (context.filtered || 0) + 1; },
    setTimeout: () => 0,
    clearTimeout: () => {},
    ...Object.fromEntries(pageGlobals.map(name => [name, name])),
    ...extra,
  });
  runInContext(read(`include/themes/${theme}/main.js`), context, { filename: `include/themes/${theme}/main.js` });
  return { context, ...jq };
}

const jqueryThemes = ['modern', 'dark', 'paper-plane', 'paw', 'sunrise'];

test('keepWindowSize keeps one resize handler however often applySkin runs', () => {
  const win = { events: [] };
  const { $ } = fakeJquery();
  const context = createContext({ $, window: win, waitForFinalEvent: () => {} });
  runInContext(layoutFunction('keepWindowSize'), context);

  $(win).on('resize.page', () => {});
  $(win).on('click', () => {});
  for (let i = 0; i < 5; i++) {
    context.keepWindowSize();
  }

  assert.equal(win.events.filter(e => e.ns === 'keepWindowSize').length, 1);
  assert.equal(win.events.filter(e => e.ns === 'page').length, 1, 'page resize handlers survive');
  assert.equal(win.events.filter(e => e.type === 'click').length, 1, 'other window events survive');
});

test('classic themeReady leaves the window handlers layout.js binds in place', () => {
  const win = { events: [] };
  const waits = [];
  const { context, $ } = loadTheme('classic', {}, {
    window: win,
    keepWindowSize: undefined,
    waitForFinalEvent: callback => waits.push(callback),
  });
  runInContext(layoutFunction('keepWindowSize'), context);
  let outsideClicks = 0;
  $(win).on('click', () => { outsideClicks++; });
  $(win).on('touchstart', () => {});

  for (let i = 0; i < 3; i++) {
    context.themeReady();
    context.keepWindowSize();
  }

  assert.deepEqual(win.events.map(e => `${e.type}.${e.ns}`).sort(), [
    'click.', 'resize.classicTheme', 'resize.keepWindowSize', 'touchstart.',
  ]);
  $(win).trigger('click');
  assert.equal(outsideClicks, 1, 'the menu close handler still fires');
  const queued = waits.length;
  $(win).trigger('resize');
  assert.equal(waits.length - queued, 1, 'one resize queues the debounced layout pass once');
});

for (const theme of jqueryThemes) {
  test(`${theme} adds each filter search icon once across repeated page loads`, () => {
    const filter = { attrs: { id: 'filter' } };
    const rfilter = { attrs: { id: 'rfilter' } };
    const filterd = { attrs: { id: 'filterd' }, next: { matches: 'i.fa-search' } };
    const { context } = loadTheme(theme, {
      'input[id="filter"]': [filter],
      'input[id="rfilter"]': [rfilter],
      'input[id="filterd"]': [filterd],
    });

    context.themeReady();
    context.themeReady();
    context.themeReady();

    assert.equal(filter.inserted, 1);
    assert.equal(rfilter.inserted, 1);
    assert.equal(filterd.inserted, undefined, 'an icon already rendered next to the input is kept');
  });

}
