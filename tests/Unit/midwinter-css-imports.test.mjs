// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

import test from 'node:test';
import assert from 'node:assert/strict';
import { existsSync, readFileSync } from 'node:fs';
import { createHash } from 'node:crypto';

const theme = new URL('../../include/themes/midwinter/', import.meta.url);
const imports = [...readFileSync(new URL('main.css', theme), 'utf8').matchAll(/@import url\('([^']+)'\)/g)]
  .map(([, file]) => file);

test('main.css imports every Midwinter stylesheet', () => {
  assert.ok(imports.length >= 15);
  for (const file of imports) {
    assert.ok(existsSync(new URL(file, theme)), `${file} is missing`);
  }
});

// Source deployments serve these URLs without a compiled manifest.
test('imports carry the actual generated child content version', () => {
  for (const file of imports) {
    const url = new URL(file, theme);
    const version = url.searchParams.get('v');
    url.search = '';
    const digest = createHash('sha256').update(readFileSync(url)).digest('hex');
    assert.equal(version, digest, `${file} does not version its child bytes`);
  }
});
