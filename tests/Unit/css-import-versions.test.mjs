// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { cp, mkdir, mkdtemp, readFile, rm, symlink, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { execFile } from 'node:child_process';
import { promisify } from 'node:util';

const execute = promisify(execFile);
const root = fileURLToPath(new URL('../../', import.meta.url));
const hash = (bytes) => createHash('sha256').update(bytes).digest('hex');
const imports = (css) => [...css.matchAll(/@import\s+url\(['"]([^'"]+)['"]\)([^;]*);/g)];

test('the actual browser build versions changed imported CSS for the uncompiled fallback', async () => {
  const directory = await mkdtemp(join(tmpdir(), 'kadupul-css-imports-'));
  try {
    await cp(join(root, 'tools/dependencies'), join(directory, 'tools/dependencies'), { recursive: true });
    await cp(join(root, 'include/themes/midwinter'), join(directory, 'include/themes/midwinter'), { recursive: true });
    await mkdir(join(directory, 'include/js'), { recursive: true });
    await cp(join(root, 'include/js/jquery.tablesorter.pager.source.js'), join(directory, 'include/js/jquery.tablesorter.pager.source.js'));
    await symlink(join(root, 'node_modules'), join(directory, 'node_modules'), 'dir');
    const stylesheet = join(directory, 'include/themes/midwinter/main.css');
    const child = join(directory, 'include/themes/midwinter/css/pre/fonts.css');
    const nested = join(directory, 'include/themes/midwinter/css/pre/nested.css');
    await writeFile(nested, 'body { color: green; }\n');
    await writeFile(child, `${await readFile(child, 'utf8')}\n@import \"./nested.css\" print;\n`);
    const build = () => execute(process.execPath, ['tools/dependencies/build.mjs'], { cwd: directory });
    await build();
    const first = await readFile(stylesheet, 'utf8');
    const firstImports = imports(first);
    assert.equal(firstImports.length, 15);
    const updated = `${await readFile(child, 'utf8')}\n/* changed child, unchanged parent input */\n`;
    await writeFile(child, updated);
    await build();
    const second = await readFile(stylesheet, 'utf8');
    const secondImports = imports(second);
    assert.notEqual(hash(first), hash(second), 'parent fallback URL fingerprint must change too');
    for (const [, reference] of secondImports) {
      const url = new URL(reference, `file://${stylesheet}`);
      assert.equal(url.searchParams.get('v'), hash(await readFile(fileURLToPath(new URL(url.pathname, 'file://')))), reference);
    }
    for (let index = 0; index < firstImports.length; ++index) {
      assert.equal(secondImports[index][2], firstImports[index][2], 'media qualifier preserved');
      if (firstImports[index][1].startsWith('./css/pre/fonts.css')) {
        assert.notEqual(secondImports[index][1], firstImports[index][1], 'child URL changes');
        assert.equal(new URL(secondImports[index][1], 'https://example.invalid/').searchParams.get('v'), hash(updated));
      } else {
        assert.equal(secondImports[index][1], firstImports[index][1], 'unmodified sibling URL stays stable');
      }
    }
    await build();
    assert.equal(await readFile(stylesheet, 'utf8'), second, 'build is idempotent');
    const priorChild = await readFile(child, 'utf8');
    await writeFile(nested, 'body { color: blue; }\n');
    await build();
    assert.notEqual(await readFile(child, 'utf8'), priorChild, 'nested child version propagates');
    assert.notEqual(await readFile(stylesheet, 'utf8'), second, 'nested version reaches the root stylesheet');
  } finally {
    await rm(directory, { recursive: true, force: true });
  }
});
