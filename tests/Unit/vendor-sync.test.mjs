// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later
import { test } from "node:test";
import assert from "node:assert/strict";
import { createHash } from "node:crypto";
import { syncAssets } from "../../tools/dependencies/sync.mjs";

function fixture(source = "upstream\r\n", fields = {}) {
  const bytes = Buffer.from(source);
  const asset = {
    file: "test.js",
    package: "test",
    version: "1.0.0",
    url: "https://cdn.jsdelivr.net/npm/test@1.0.0/test.js",
    sha256: createHash("sha256").update(bytes).digest("hex"),
    ...fields,
  };
  const writes = [];
  const io = {
    fetch: async () => new Response(bytes),
    readFile: async () => Buffer.from(source.replace(/\r\n/g, "\n")),
    writeFile: async (file, content) => writes.push([file, content]),
  };
  return { asset, io, writes };
}

test("checks an exact upstream copy after LF normalization without writing", async () => {
  const { asset, io, writes } = fixture();
  await syncAssets([asset], "--check", io);
  assert.equal(writes.length, 0);
});

test("writes only verified bytes to the expected asset path", async () => {
  const { asset, io, writes } = fixture();
  await syncAssets([asset], "--write", io);
  assert.equal(writes.length, 1);
  assert.ok(writes[0][0].pathname.endsWith("/include/js/test.js"));
  assert.equal(writes[0][1].toString(), "upstream\n");
});

test("preserves jQuery UI backward compatibility", async () => {
  const { asset, io, writes } = fixture(
    'var version = $.ui.version = "1.0.0";\r\n',
    { patch: "ui-back-compat" },
  );
  await syncAssets([asset], "--write", io);
  assert.equal(
    writes[0][1].toString(),
    'var version = $.ui.version = "1.0.0";\n$.uiBackCompat = true;\n',
  );
});

for (const [name, fields, error] of [
  ["path traversal", { file: "../escape.js" }, /filename/],
  ["dot files", { file: ".hidden" }, /filename/],
  ["HTTP source", { url: "http://cdn.jsdelivr.net/file.js" }, /upstream/],
  ["unknown host", { url: "https://example.org/file.js" }, /upstream/],
  ["checksum mismatch", { sha256: "0".repeat(64) }, /checksum/],
  ["unknown patch", { patch: "other" }, /Unknown vendor patch/],
  [
    "missing compatibility anchor",
    { patch: "ui-back-compat" },
    /no longer applies/,
  ],
]) {
  test(`rejects ${name} before writing`, async () => {
    const { asset, io, writes } = fixture("upstream", fields);
    await assert.rejects(syncAssets([asset], "--write", io), error);
    assert.equal(writes.length, 0);
  });
}

test("rejects changed local bytes in check mode", async () => {
  const { asset, io } = fixture();
  io.readFile = async () => Buffer.from("modified");
  await assert.rejects(
    syncAssets([asset], "--check", io),
    /Bundled asset differs/,
  );
});

test("rejects unsuccessful upstream responses", async () => {
  const { asset, io } = fixture();
  io.fetch = async () => new Response("", { status: 404 });
  await assert.rejects(syncAssets([asset], "--write", io), /404/);
});

test("disallows upstream redirects before any asset write", async () => {
  const { asset, io, writes } = fixture();
  io.fetch = async (_url, options) => {
    assert.equal(options.redirect, "error");
    throw new TypeError("fetch failed: unexpected redirect");
  };
  await assert.rejects(
    syncAssets([asset], "--write", io),
    /unexpected redirect/,
  );
  assert.equal(writes.length, 0);
});

test("validates the entire batch before writing its first asset", async () => {
  const { asset, io, writes } = fixture();
  await assert.rejects(
    syncAssets([asset, { ...asset, sha256: "bad" }], "--write", io),
    /checksum/,
  );
  assert.equal(writes.length, 0);
});

test("rejects invalid modes", async () => {
  await assert.rejects(syncAssets([], "--invalid"), /Usage:/);
});

test("applies exact compatibility replacements without interpreting dollar signs", async () => {
  const { asset, io, writes } = fixture("old", {
    replacements: [{ before: "old", after: "$& $.escapeSelector" }],
  });
  await syncAssets([asset], "--write", io);
  assert.equal(writes[0][1].toString(), "$& $.escapeSelector");
});

for (const before of ["missing", "", "upstream"]) {
  test(`rejects missing, empty or ambiguous replacements: ${before}`, async () => {
    const { asset, io, writes } = fixture("upstream upstream", {
      replacements: [{ before, after: "patched" }],
    });
    await assert.rejects(
      syncAssets([asset], "--write", io),
      /no longer applies/,
    );
    assert.equal(writes.length, 0);
  });
}
