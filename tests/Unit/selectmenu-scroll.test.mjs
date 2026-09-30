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

function harness() {
  const windowObject = {};
  const state = { menuOpen: true, scans: 0, bound: [], containers: undefined, added: undefined };
  const selects = [{ initialized: true, closes: 0 }, { initialized: false, closes: 0 }];

  function $(selector) {
    if (typeof selector === 'string' && selector.startsWith('.cactiConsoleContentArea')) {
      state.containers = selector;
      const chain = {
        add: target => { state.added = target; return chain; },
        off: event => { state.bound = state.bound.filter(b => b.event !== event); return chain; },
        on: (event, handler) => { state.bound.push({ event, handler }); return chain; },
      };
      return chain;
    }
    if (selector === 'select') {
      state.scans++;
      return { each: callback => selects.forEach(select => callback.call(select)) };
    }
    if (selector === '.ui-selectmenu-open') {
      return { length: state.menuOpen ? 1 : 0 };
    }
    return {
      selectmenu: action => {
        if (action === 'instance') {
          return selector.initialized ? {} : undefined;
        }
        assert.equal(action, 'close');
        selector.closes++;
      },
    };
  }

  const scope = { $, window: windowObject };
  runInNewContext(implementation('setupSelectmenuScrollClose'), scope);
  return { scope, state, selects, windowObject };
}

test('scrolling a content area closes the open select menu', () => {
  const { scope, state, selects, windowObject } = harness();
  scope.setupSelectmenuScrollClose();

  assert.match(state.containers, /\.cactiConsoleContentArea/);
  assert.match(state.containers, /\.cactiGraphContentArea/);
  assert.match(state.containers, /\.cactiTreeNavigationArea/);
  assert.equal(state.added, windowObject);
  assert.equal(state.bound.length, 1);
  assert.equal(state.bound[0].event, 'scroll.cactiSelectmenu');

  state.bound[0].handler();
  assert.equal(selects[0].closes, 1);
  assert.equal(selects[1].closes, 0, 'selects without a selectmenu widget are left alone');
});

test('scrolling with no open menu does not scan the selects', () => {
  const { scope, state } = harness();
  scope.setupSelectmenuScrollClose();
  state.menuOpen = false;

  state.bound[0].handler();
  assert.equal(state.scans, 0);
});

test('repeated page loads keep one scroll handler', () => {
  const { scope, state } = harness();
  for (let i = 0; i < 4; i++) {
    scope.setupSelectmenuScrollClose();
  }
  assert.equal(state.bound.length, 1);
});

test('applySkin binds the scroll handler after the theme builds its select menus', () => {
  const applySkin = implementation('applySkin');
  const themeReady = applySkin.indexOf('themeReady();');
  assert.ok(themeReady >= 0);
  assert.ok(applySkin.indexOf('setupSelectmenuScrollClose();', themeReady) > themeReady);
});
