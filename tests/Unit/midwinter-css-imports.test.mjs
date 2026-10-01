// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

import test from 'node:test';
import assert from 'node:assert/strict';
import { existsSync, readFileSync } from 'node:fs';

const theme = new URL('../../include/themes/midwinter/', import.meta.url);
const imports = [...readFileSync(new URL('main.css', theme), 'utf8').matchAll(/@import url\('([^']+)'\)/g)]
  .map(([, file]) => file);

test('main.css imports every Midwinter stylesheet', () => {
  assert.ok(imports.length >= 15);
  for (const file of imports) {
    assert.ok(existsSync(new URL(file, theme)), `${file} is missing`);
  }
});

// asset-map:compile digests each import; a hand-kept query would go stale
// because nothing rewrites it any more.
test('imports carry no cache-busting query', () => {
  for (const file of imports) {
    assert.doesNotMatch(file, /[?#]/, `${file} carries a query`);
  }
});
