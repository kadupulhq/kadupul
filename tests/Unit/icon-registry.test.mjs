// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

import test from 'node:test';
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { existsSync, readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { createContext, runInContext } from 'node:vm';

const root = new URL('../../', import.meta.url);
const read = path => readFileSync(new URL(path, root), 'utf8');
const layout = read('include/layout.js');
const registry = JSON.parse(read('config/icons.json'));
const allCss = new URL('include/fa/css/all.css', root);

function layoutFunction(name) {
  const start = layout.indexOf(`function ${name}(`);
  assert.ok(start >= 0, `${name}() must exist`);
  const end = layout.indexOf('\n}\n', start);
  assert.ok(end > start, `${name}() must have its closing boundary`);
  return layout.slice(start, end + 2);
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

// Files that still spell out Font Awesome classes. Each moves to html_icon()
// or iconClass() in its own change, so this list may only shrink.
const notMigrated = 'legacy page, not yet drawn from config/icons.json';
const hardCoded = new Map([
  ['auth_changepassword.php', notMigrated],
  ['auth_profile.php', notMigrated],
  ['automation_snmp.php', notMigrated],
  ['automation_templates.php', notMigrated],
  ['cdef.php', notMigrated],
  ['color_templates.php', notMigrated],
  ['data_debug.php', notMigrated],
  ['data_queries.php', notMigrated],
  ['data_source_profiles.php', notMigrated],
  ['data_sources.php', notMigrated],
  ['data_templates.php', notMigrated],
  ['graph_templates.php', notMigrated],
  ['graph_xport.php', notMigrated],
  ['graphs.php', notMigrated],
  ['graphs_new.php', notMigrated],
  ['host.php', notMigrated],
  ['host_templates.php', notMigrated],
  ['install/install.js', notMigrated],
  ['install/install.php', notMigrated],
  ['lib/api_automation.php', notMigrated],
  ['lib/html_form.php', notMigrated],
  ['lib/html_graph.php', notMigrated],
  ['lib/html_reports.php', notMigrated],
  ['lib/html_tree.php', notMigrated],
  ['lib/html_utility.php', notMigrated],
  ['lib/installer.php', notMigrated],
  ['plugins.php', notMigrated],
  ['tools/dependencies/fontawesome.mjs', 'writes the fa-circle-thin alias into the built stylesheet'],
  ['tree.php', notMigrated],
  ['user_admin.php', notMigrated],
  ['user_group_admin.php', notMigrated],
  ['utilities.php', notMigrated],
]);

// html_start_box() documents the plugin 'class' format with a placeholder.
const placeholders = new Set(['fa-icon']);
const faClass = /(?<![\w$-])fa-[a-z0-9]+(?:-[a-z0-9]+)*(?![\w-])/g;

function trackedSources() {
  const files = execFileSync('git', ['ls-files', '-z', '--', '*.php', '*.js', '*.mjs', '*.html', '*.twig'], {
    cwd: fileURLToPath(root),
    encoding: 'utf8',
  }).split('\0');

  return files
    .filter(path => path !== '' && !/^(include\/vendor|include\/fa|tests|docs)\//.test(path))
    .map(path => [path, read(path)]);
}

function hardCodedClasses(sources) {
  const found = new Map();
  for (const [path, text] of sources) {
    const names = [...new Set(text.match(faClass) || [])].filter(name => !placeholders.has(name));
    if (names.length) {
      found.set(path, names);
    }
  }
  return found;
}

test('the hard-coded class scan finds classes, selectors and modifiers', () => {
  const found = hardCodedClasses([
    ['a.js', "$('i.fa-sort'); '<i class=\"fa fa-plus fa-spin\">'"],
    ['b.js', "iconClass('add'); var $fa-x; '--fa-style'; 'fa-' + name"],
    ['c.php', " 'class' => 'fa fa-icon'"],
  ]);
  assert.deepEqual([...found], [['a.js', ['fa-sort', 'fa-plus', 'fa-spin']]]);
});

test('core files draw Font Awesome classes only from config/icons.json', () => {
  const sources = trackedSources();
  assert.ok(sources.some(([path]) => path === 'include/layout.js'), 'git ls-files lists the application sources');
  const found = hardCodedClasses(sources);

  const unlisted = [...found].filter(([path]) => !hardCoded.has(path)).map(([path, names]) => `${path}: ${names.join(' ')}`);
  assert.deepEqual(unlisted, [], 'use html_icon(), html_icon_class() or iconClass() with a registry name');

  const stale = [...hardCoded.keys()].filter(path => !found.has(path));
  assert.deepEqual(stale, [], 'drop migrated files from the allow-list');

  for (const [path, reason] of hardCoded) {
    assert.ok(reason.length > 0, `${path} needs a reason`);
  }
});

test('every icon name the code asks for is in the registry', () => {
  const names = [];
  const calls = /\b(?:iconClass|iconSelector|iconMarkup|setupThemeLogos|html_icon|html_icon_class)\(\s*['"]([a-z0-9-]+)['"]\s*[,)]/g;
  for (const [path, source] of trackedSources()) {
    for (const [, name] of source.matchAll(calls)) {
      names.push([path, name]);
    }
  }
  assert.ok(names.length > 50, `found ${names.length} icon names`);

  // Menu glyphs are names; lib/html.php also sends a sort direction and the
  // default glyph through variables.
  const glyphs = read('include/global_arrays.php').match(/\$menu_glyphs = array\(([\s\S]*?)\);/)[1];
  for (const [, name] of glyphs.matchAll(/=>\s*'([^']+)'/g)) {
    names.push(['include/global_arrays.php', name]);
  }
  for (const name of ['menu-folder', 'sort', 'sort-asc', 'sort-desc']) {
    names.push(['lib/html.php', name]);
  }

  // midwinter builds these from the operating system ua-parser reports.
  for (const os of ['android', 'centos', 'fedora', 'freebsd', 'redhat', 'suse', 'ubuntu']) {
    names.push(['include/themes/midwinter/main.js', `brand-${os}`]);
  }

  assert.deepEqual(names.filter(([, name]) => !(name in registry.icons)).map(pair => pair.join(': ')), []);
});

test('registry entries name classes the built Font Awesome defines', { skip: !existsSync(allCss) && 'include/fa is not built; run npm ci && npm run build' }, () => {
  const css = read('include/fa/css/all.css');
  const defined = new Set(css.match(/(?<=\.)fa-[a-z0-9]+(?:-[a-z0-9]+)*(?![\w-])/g) || []);
  assert.ok(defined.has('fa-plus') && defined.size > 1000, 'include/fa/css/all.css is the full Font Awesome build');

  const styles = new Set(['fa', 'fas', 'far', 'fab']);
  const entries = [
    ...Object.entries(registry.icons).map(([name, classes]) => [name, classes]),
    ...Object.entries(registry.themes).flatMap(([theme, icons]) => Object.entries(icons).map(([name, classes]) => [`${theme}/${name}`, classes])),
  ];
  const problems = [];
  for (const [name, classes] of entries) {
    const tokens = classes.split(' ');
    if (tokens.filter(token => styles.has(token)).length !== 1) {
      problems.push(`${name}: needs exactly one of ${[...styles].join(', ')}`);
    }
    if (!tokens.some(token => token.startsWith('fa-'))) {
      problems.push(`${name}: names no glyph`);
    }
    for (const token of tokens.filter(token => !styles.has(token))) {
      if (!defined.has(token)) {
        problems.push(`${name}: ${token}`);
      }
    }
  }
  assert.deepEqual(problems, []);
});

test('registry names and theme overrides stay sorted and valid', () => {
  const sorted = object => JSON.stringify(Object.keys(object)) === JSON.stringify(Object.keys(object).sort());
  assert.ok(sorted(registry.icons), 'icons are in name order');
  assert.ok(sorted(registry.themes), 'themes are in name order');
  for (const [theme, icons] of Object.entries(registry.themes)) {
    assert.ok(existsSync(new URL(`include/themes/${theme}/main.css`, root)), `${theme} is an installed theme`);
    assert.ok(sorted(icons), `${theme} overrides are in name order`);
    assert.deepEqual(Object.keys(icons).filter(name => !(name in registry.icons)), [], `${theme} only redraws existing names`);
  }
});
