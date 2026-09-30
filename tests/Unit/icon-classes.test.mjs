// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

import test from 'node:test';
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { existsSync, readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

const root = new URL('../../', import.meta.url);
const allCss = new URL('include/fa/css/all.css', root);

// Font Awesome 7 draws an unknown name as an empty 1.25em slot, so a stale
// Font Awesome 4 or 5 name fails silently in the browser.
const iconName = /(?<![\w$-])fa-[a-z0-9]+(?:-[a-z0-9]+)*(?![\w-])/g;

// Placeholders in comments, not icons.
const notIcons = new Map([
  ['fa-icon', 'html_start_box() documents the icon class format with it'],
]);

function definedIcons(css) {
  return new Set(css.match(/(?<=\.)fa-[a-z0-9]+(?:-[a-z0-9]+)*(?![\w-])/g) || []);
}

function undefinedIcons(sources, defined) {
  const missing = [];
  for (const [path, text] of sources) {
    for (const name of new Set(text.match(iconName) || [])) {
      if (!defined.has(name) && !notIcons.has(name)) {
        missing.push(`${path}: ${name}`);
      }
    }
  }
  return missing;
}

function trackedSources() {
  const files = execFileSync('git', ['ls-files', '-z', '--', '*.php', '*.js', '*.mjs', '*.html', '*.twig'], {
    cwd: fileURLToPath(root),
    encoding: 'utf8',
  }).split('\0');

  return files
    .filter(path => path !== '' && !/^(include\/vendor|include\/fa|tests|docs)\//.test(path))
    .map(path => [path, readFileSync(new URL(path, root), 'utf8')]);
}

test('the icon scan matches class names and ignores other fa- text', () => {
  const defined = definedIcons('.fa-plus {\n  --fa: "+";\n}\n.fa-angles-down, .fa-angle-double-down { }\n.fa-spin { }');
  assert.deepEqual([...defined].sort(), ['fa-angle-double-down', 'fa-angles-down', 'fa-plus', 'fa-spin']);

  assert.deepEqual(undefinedIcons([
    ['ok.php', "<i class='fa fa-plus fa-spin'></i>"],
    ['alias.js', '<i class="fas fa-angle-double-down"></i>'],
    ['vars.js', "el.css('--fa-style', 400); var $fa-x; 'fa-' + name"],
    ['doc.php', " 'class' => 'fa fa-icon'"],
    ['stale.js', '<i class="fas fa-double-angle-down"></i><i class="fa fa-trash-o">'],
  ], defined), ['stale.js: fa-double-angle-down', 'stale.js: fa-trash-o']);

  assert.deepEqual(undefinedIcons([], defined), []);
});

test('every Font Awesome class in the source exists in the built stylesheet', { skip: !existsSync(allCss) && 'include/fa is not built; run npm ci && npm run build' }, () => {
  const defined = definedIcons(readFileSync(allCss, 'utf8'));
  assert.ok(defined.has('fa-plus') && defined.size > 1000, 'include/fa/css/all.css is the full Font Awesome build');

  const sources = trackedSources();
  assert.ok(sources.some(([path]) => path === 'include/layout.js'), 'git ls-files lists the application sources');
  assert.deepEqual(undefinedIcons(sources, defined), []);
});
