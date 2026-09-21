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
  assert.match(await readFile(new URL('include/fa/css/all.css', root), 'utf8'), /\.fa\.fa-circle-thin:before/);
  assert.match(await readFile(new URL('include/js/purify.js', root), 'utf8'), /DOMPurify/);
});
