// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createContext, runInContext } from 'node:vm';

const root = new URL('../../', import.meta.url);
const read = path => readFileSync(new URL(path, root), 'utf8');
const layout = read('include/layout.js');
const registry = JSON.parse(read('config/icons.json'));

function layoutFunction(name) {
  const start = layout.indexOf(`function ${name}(`);
  assert.ok(start >= 0, `${name}() must exist`);
  return layout.slice(start, layout.indexOf('\n}\n', start) + 2);
}

// What html_common_header() prints for a theme.
function resolved(theme) {
  return { ...registry.icons, ...(registry.themes[theme] || {}) };
}

function helpers(globals = {}) {
  const context = createContext(globals);
  runInContext(['iconClass', 'iconSelector', 'iconMarkup'].map(layoutFunction).join('\n'), context);
  return context;
}

test('layout.js draws names from the map html_common_header prints', () => {
  const js = helpers({ kadupulIcons: resolved('paw') });

  assert.equal(js.iconClass('sort-asc'), 'fa fa-sort-up');
  assert.equal(js.iconClass('export'), 'fa fa-chevron-down', 'theme overrides arrive already resolved');
  assert.equal(js.iconSelector('loading'), '.fa.fa-circle-notch.fa-spin');
  assert.equal(js.iconMarkup('collapse-all'), '<i class="fas fa-angles-down" aria-hidden="true"></i>');
});

test('an unknown name draws nothing and selects nothing', () => {
  const js = helpers({ kadupulIcons: resolved('modern') });

  assert.equal(js.iconClass('fa-plus'), '');
  assert.equal(js.iconClass('constructor'), '', 'inherited properties are not icons');
  assert.equal(js.iconSelector('no-such-icon'), ':not(*)');
  assert.equal(helpers({}).iconClass('add'), '', 'a page without the header map still loads');
  assert.equal(helpers({ kadupulIcons: null }).iconSelector('add'), ':not(*)');
});
