// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

import test from 'node:test';
import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { existsSync, readFileSync } from 'node:fs';

const theme = new URL('../../include/themes/midwinter/', import.meta.url);
const imports = [...readFileSync(new URL('main.css', theme), 'utf8').matchAll(/@import url\('([^?']+)\?([0-9a-f]*)'\)/g)]
  .map(([, file, hash]) => ({ file, hash }));

test('main.css imports every Midwinter stylesheet with a query hash', () => {
  assert.ok(imports.length >= 15);
  for (const { file, hash } of imports) {
    assert.ok(existsSync(new URL(file, theme)), `${file} is missing`);
    assert.match(hash, /^[0-9a-f]{32}$/, `${file} has no MD5 query`);
  }
});

// update_hash.php writes these; a stale one lets browsers keep the old CSS.
test('each import hash matches the current file contents', () => {
  for (const { file, hash } of imports) {
    const actual = createHash('md5').update(readFileSync(new URL(file, theme))).digest('hex');
    assert.equal(hash, actual, `${file} changed without running update_hash.php`);
  }
});
