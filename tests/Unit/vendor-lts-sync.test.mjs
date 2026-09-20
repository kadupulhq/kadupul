import test from 'node:test';
import assert from 'node:assert/strict';
import { mkdtemp, mkdir, writeFile, readFile, copyFile, symlink, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { resolve } from 'node:path';
import { createHash } from 'node:crypto';
import { spawnSync } from 'node:child_process';

async function fixture(run) {
  const root = await mkdtemp(resolve(tmpdir(), 'lts-vendor-sync-'));
  try {
    await mkdir(resolve(root, 'tools/dependencies'), { recursive: true });
    await mkdir(resolve(root, 'packages/example'), { recursive: true });
    await mkdir(resolve(root, 'include/js'), { recursive: true });
    await copyFile(new URL('../../tools/dependencies/sync.mjs', import.meta.url), resolve(root, 'tools/dependencies/sync.mjs'));
    const source = 'const value = 1;\r\n';
    await writeFile(resolve(root, 'packages/example/asset.js'), source);
    const entry = { file: 'include/js/asset.js', package: 'example', source: 'asset.js', sha256: createHash('sha256').update(source).digest('hex') };
    const execute = async (asset = entry, mode = '--write') => {
      await writeFile(resolve(root, 'tools/dependencies/assets.json'), JSON.stringify([asset]));
      return spawnSync(process.execPath, [resolve(root, 'tools/dependencies/sync.mjs'), mode, `--source=${root}/packages`], { encoding: 'utf8' });
    };
    await run({ root, entry, execute });
  } finally {
    await rm(root, { recursive: true, force: true });
  }
}

test('pinned sources, patches and LF normalization reproduce on check', () => fixture(async ({ root, entry, execute }) => {
  entry.replacements = [{ before: 'value = 1', after: 'value = 2' }];
  assert.equal((await execute()).status, 0);
  assert.equal(await readFile(resolve(root, 'include/js/asset.js'), 'utf8'), 'const value = 2;\n');
  assert.equal((await execute(entry, '--check')).status, 0);
  await writeFile(resolve(root, 'include/js/asset.js'), 'changed');
  assert.notEqual((await execute(entry, '--check')).status, 0);
}));

test('tampered source checksum fails closed', () => fixture(async ({ entry, execute }) => {
  entry.sha256 = '0'.repeat(64);
  assert.match((await execute()).stderr, /checksum mismatch/);
}));

test('patch context mismatch fails closed', () => fixture(async ({ entry, execute }) => {
  entry.replacements = [{ before: 'missing', after: 'replacement' }];
  assert.match((await execute()).stderr, /Patch context mismatch/);
}));

test('destination traversal and destination symlinks are rejected', () => fixture(async ({ root, entry, execute }) => {
  assert.match((await execute({ ...entry, file: 'include/../../../outside.js' })).stderr, /Unsafe asset path/);
  await symlink(resolve(root, 'packages/example'), resolve(root, 'include/linked'));
  assert.match((await execute({ ...entry, file: 'include/linked/asset.js' })).stderr, /Symlink destination/);
}));

test('source symlinks cannot escape the selected package root', () => fixture(async ({ root, entry, execute }) => {
  await writeFile(resolve(root, 'outside.js'), 'outside');
  await symlink(resolve(root, 'outside.js'), resolve(root, 'packages/example/link.js'));
  assert.match((await execute({ ...entry, source: 'link.js' })).stderr, /Unsafe asset path/);
}));

test('source traversal and symlinks cannot read a sibling package', () => fixture(async ({ root, entry, execute }) => {
  await mkdir(resolve(root, 'packages/sibling'));
  await writeFile(resolve(root, 'packages/sibling/asset.js'), 'sibling');
  assert.match((await execute({ ...entry, source: '../sibling/asset.js' })).stderr, /Unsafe asset path/);
  await symlink(resolve(root, 'packages/sibling/asset.js'), resolve(root, 'packages/example/sibling.js'));
  assert.match((await execute({ ...entry, source: 'sibling.js' })).stderr, /Unsafe asset path/);
}));
