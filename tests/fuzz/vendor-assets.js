// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

import fc from 'fast-check';
import assert from 'node:assert/strict';
import { createHash, randomInt } from 'node:crypto';
import { test } from 'node:test';
import { syncAssets } from '../../tools/dependencies/sync.mjs';

const hash = (bytes) => createHash('sha256').update(bytes).digest('hex');
const runs = Number(process.env.FUZZ_RUNS ?? 1000);
const seed = process.env.FUZZ_SEED === undefined ? randomInt(-2147483648, 2147483648) : Number(process.env.FUZZ_SEED);
if (!Number.isSafeInteger(runs) || runs < 1 || runs > 100000 ||
    (seed !== undefined && (!Number.isInteger(seed) || seed < -2147483648 || seed > 2147483647))) {
  throw new Error('Use FUZZ_RUNS=1..100000 and an optional signed 32-bit FUZZ_SEED.');
}
const options = { numRuns: runs, seed, path: process.env.FUZZ_PATH };
console.log(`Property fuzzing: ${runs} cases per property; seed=${seed}`);
const bytes = fc.uint8Array({ maxLength: 1024 });

function fixture(body, extra = {}) {
  const writes = [];
  return {
    asset: {
      file: 'asset.js', package: 'fixture', version: '1.0.0',
      url: 'https://cdn.jsdelivr.net/npm/fixture@1.0.0/asset.js',
      sha256: hash(body), ...extra,
    },
    writes,
    io: {
      // All I/O is owned in memory: no external downloads or repository writes.
      fetch: async (_url, request) => {
        assert.equal(request.redirect, 'error');
        return new Response(body);
      },
      readFile: async () => { throw new Error('Unexpected local read in write mode'); },
      writeFile: async (file, content) => writes.push({ file, content }),
    },
  };
}

test('verified arbitrary bytes survive the documented UTF-8/LF conversion', async (t) => {
  t.mock.method(console, 'log', () => {});
  await fc.assert(fc.asyncProperty(bytes, async (data) => {
    const body = Buffer.from(data);
    const { asset, io, writes } = fixture(body);
    await syncAssets([asset], '--write', io);
    assert.equal(writes.length, 1);
    assert.deepEqual(writes[0].content, Buffer.from(body.toString('utf8').replaceAll('\r\n', '\n')));
  }), options);
});

test('a corrupted download at any batch position prevents every write', async (t) => {
  t.mock.method(console, 'log', () => {});
  await fc.assert(fc.asyncProperty(bytes, fc.integer({ min: 0, max: 5 }), async (data, badIndex) => {
    const { asset, io, writes } = fixture(Buffer.from(data));
    const batch = Array.from({ length: 6 }, (_, i) => ({ ...asset, file: `asset-${i}.js` }));
    // Guarantee a different digest, including when the generated body is empty.
    batch[badIndex].sha256 = (asset.sha256[0] === '0' ? '1' : '0') + asset.sha256.slice(1);
    await assert.rejects(syncAssets(batch, '--write', io), /checksum mismatch/);
    assert.equal(writes.length, 0);
  }), options);
});

test('generated patch text is literal and preserves the containing asset', async (t) => {
  t.mock.method(console, 'log', () => {});
  await fc.assert(fc.asyncProperty(fc.string({ maxLength: 1024 }), async (after) => {
    const { asset, io, writes } = fixture(Buffer.from('prefix\r\nANCHOR\r\nsuffix'), {
      replacements: [{ before: 'ANCHOR', after }],
    });
    await syncAssets([asset], '--write', io);
    assert.equal(writes.length, 1);
    assert.equal(writes[0].content.toString(), `prefix\n${after}\nsuffix`);
  }), options);
});
