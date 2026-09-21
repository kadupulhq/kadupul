// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';

test('locked npm packages produce the legacy browser assets and compatibility aliases', async () => {
  await import('../../tools/dependencies/build.mjs');
  const root = new URL('../../', import.meta.url);
  const flags = JSON.parse(await readFile(new URL('include/vendor/flag-icons/package.json', root), 'utf8'));
  assert.equal(flags.name, 'flag-icons');
  assert.match(await readFile(new URL('include/vendor/flag-icons/flags/4x3/us.svg', root), 'utf8'), /<svg/);
  const iconCss = await readFile(new URL('include/fa/css/all.css', root), 'utf8');
  assert.match(iconCss, /\.fa\.fa-circle-thin \{\s*--fa: "\\f111";\s*\}/);
  assert.equal(iconCss.split('.fa.fa-circle-thin {').length, 2, 'install the legacy alias exactly once');
  assert.match(iconCss, /\.fa-circle-notch \{\s*--fa: "\\f1ce";\s*\}/);
  assert.match(iconCss, /\.fa\)::before \{\s*content: var\(--fa\)/);
  assert.match(await readFile(new URL('include/js/purify.js', root), 'utf8'), /DOMPurify/);
});
