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
  runInContext(['setupThemeSearchIcons', 'setupThemeSelectmenus', 'setupThemeLogos', 'setupThemeFormControls'].map(layoutFunction).join('\n'), context);
  runInContext(read(`include/themes/${theme}/main.js`), context, { filename: new URL(`include/themes/${theme}/main.js`, root).href });
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

  test(`${theme} sizes select menus without building a selector from the id`, () => {
    const dotted = { attrs: { id: 'plugin.form:field[1]' }, props: {} };
    const anonymous = { attrs: {}, props: {} };
    const multiple = { attrs: { id: 'multi' }, props: { multiple: true } };
    const { context, selectors } = loadTheme(theme, { select: [dotted, anonymous, multiple] });

    assert.doesNotThrow(() => context.themeReady());

    assert.equal(dotted.menu?.style?.['max-height'], '250px');
    assert.equal(anonymous.menu?.style?.['max-height'], '250px');
    assert.equal(multiple.selectmenu, undefined, 'multi-selects stay native');
    assert.deepEqual(selectors.filter(s => s.endsWith('-menu')), []);
  });
}

test('dark no longer binds a change handler to colour dropdowns', () => {
  const colour = { attrs: { id: 'color_id' } };
  const { context, calls } = loadTheme('dark', { '.colordropdown': [colour], 'select.colordropdown': [colour] });

  context.themeReady();

  assert.deepEqual(colour.events, undefined);
  assert.deepEqual(calls.filter(c => c.selector === '.colordropdown'), []);
  assert.doesNotMatch(read('include/themes/dark/main.js'), /background-color:#'\+color\+',/);
});

test('paw keeps its helpers local, sets both logos and inserts balanced footer markup', () => {
  const host = { attrs: { id: 'host' } };
  const callBack = { value: 'applyFilter()' };
  const menuLink = { closest: { attrs: { id: 'menu_console' } }, next: undefined };
  const otherItem = { attrs: { id: 'menu_graphs' } };
  const loginLogo = {};
  const logoutLogo = {};
  const { context, calls } = loadTheme('paw', {
    '#host': [host],
    '#call_back': [callBack],
    '#nav li:has(ul) a.active': [menuLink],
    'li.menuitem': [otherItem],
    '.cactiLoginLogo': [loginLogo],
    '.cactiLogoutLogo': [logoutLogo],
  });
  const before = new Set(Object.keys(context));

  context.themeReady();
  host.autocomplete.select.call(host, {}, { item: { id: 7 } });
  menuLink.events.find(e => e.type === 'click').fn.call(menuLink, { preventDefault() {} });

  const leaked = Object.keys(context).filter(key => !before.has(key) && key !== 'filtered');
  assert.deepEqual(leaked, []);
  assert.equal(context.filtered, 1, 'the host call back still runs');
  assert.match(loginLogo.html, /fa-paw/);
  assert.match(logoutLogo.html, /fa-paw/);
  assert.deepEqual(calls.filter(c => c.method === 'css' && c.args[1] === undefined && typeof c.args[0] === 'string'), [],
    'no css() getter is called for its side effect');

  const markup = read('include/themes/paw/main.js').match(/\$\('(<div id="cactiPageBottom"[^']*)'\)/)[1];
  assert.equal((markup.match(/<a\b/g) || []).length, (markup.match(/<\/a>/g) || []).length);
});

test('theme scripts only use icon classes the shipped Font Awesome defines', () => {
  let css;
  for (const path of ['include/fa/css/all.css', 'node_modules/@fortawesome/fontawesome-free/css/all.css']) {
    try {
      css = read(path);
      break;
    } catch {
      // try the next location
    }
  }
  assert.ok(css, 'build the browser assets (npm ci && npm run build) before this test');

  const missing = [];
  for (const theme of ['classic', 'modern', 'dark', 'paper-plane', 'paw', 'sunrise']) {
    const names = new Set(read(`include/themes/${theme}/main.js`).match(/\bfa-[a-z0-9-]+/g) || []);
    for (const name of names) {
      if (!new RegExp(`\\.${name}(?![a-z0-9-])`).test(css)) {
        missing.push(`${theme}: ${name}`);
      }
    }
  }
  assert.deepEqual(missing, []);
  assert.doesNotMatch(css, /\.fa-arrow-circle-o-up(?![a-z0-9-])/, 'the Font Awesome 4 names stay undefined');
});

test('midwinter draws its filter icon from a face that has the glyph', () => {
  const classes = read('include/themes/midwinter/main.js')
    .match(/<div class="cactiTableFilter"><span><i class="([^"]+)">/)[1].split(' ');

  // The free regular font has no sliders glyph, so .far draws a missing-glyph box.
  assert.ok(!classes.includes('far') && !classes.includes('fa-regular'), classes.join(' '));
  assert.ok(classes.includes('fa-sliders'), classes.join(' '));
});
