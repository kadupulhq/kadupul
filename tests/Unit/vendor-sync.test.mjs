// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later
import { test } from "node:test";
import assert from "node:assert/strict";
import { createHash } from "node:crypto";
import { readFileSync } from "node:fs";
import {
  checkInventory,
  collectPins,
  reportOutdated,
  runOutdated,
  syncAssets,
} from "../../tools/dependencies/sync.mjs";

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

test("skips owned entries without reading or fetching", async () => {
  const io = {
    fetch: async () => assert.fail("owned entries are not fetched"),
    readFile: async () => assert.fail("owned entries are not read"),
    writeFile: async () => assert.fail("owned entries are not written"),
  };
  await syncAssets([{ file: "own.js", owned: true }], "--check", io);
});

test("verifies a tracked entry by its own bytes without fetching it", async () => {
  const { asset, io, writes } = fixture("local", { tracked: true, url: undefined });
  io.fetch = async () => assert.fail("tracked entries are not fetched");
  io.readFile = async () => Buffer.from("local");
  await syncAssets([asset], "--check", io);
  assert.equal(writes.length, 0);
});

test("leaves tracked entries alone when writing", async () => {
  const { asset, io, writes } = fixture("local", { tracked: true, url: undefined });
  io.fetch = async () => assert.fail("tracked entries are not fetched");
  io.readFile = async () => assert.fail("tracked entries are not read");
  await syncAssets([asset], "--write", io);
  assert.equal(writes.length, 0);
});

test("rejects a tracked entry whose bytes no longer match", async () => {
  const { asset, io } = fixture("local", { tracked: true, url: undefined });
  io.readFile = async () => Buffer.from("edited");
  await assert.rejects(
    syncAssets([asset], "--check", io),
    /Tracked asset differs/,
  );
});

const manifest = JSON.parse(
  readFileSync(new URL("../../tools/dependencies/assets.json", import.meta.url)),
);
const jsDir = new URL("../../include/js/", import.meta.url);

const listing = (...names) => ({
  readdir: async () =>
    names.map((name) => ({ name, isFile: () => !name.endsWith("/") })),
});

test("inventories every top-level file in include/js", async () => {
  await checkInventory(manifest);
});

test("the inventory check reports a file with no entry", async () => {
  const without = manifest.filter((entry) => entry.file !== "jstree.js");
  await assert.rejects(checkInventory(without), /jstree\.js has no inventory entry/);
});

test("the inventory check reports an entry whose file is gone", async () => {
  for (const entry of [
    { file: "gone.js", tracked: true },
    { file: "gone.js", owned: true },
    { file: "gone.js", url: "https://cdn.jsdelivr.net/gone.js" },
  ]) {
    await assert.rejects(
      checkInventory([entry], listing()),
      /gone\.js.*does not exist/,
    );
  }
});

test("the inventory check ignores index.php and subdirectories", async () => {
  await checkInventory(
    [{ file: "a.js", owned: true }],
    listing("a.js", "index.php", "LC_MESSAGES/"),
  );
});

for (const [name, entry] of [
  ["a string owned flag", { file: "a.js", owned: "false" }],
  ["a numeric tracked flag", { file: "a.js", tracked: 1 }],
  ["a string npm flag", { file: "a.js", tracked: true, npm: "false" }],
  ["owned with tracked", { file: "a.js", owned: true, tracked: true }],
  [
    "owned with a download url",
    { file: "a.js", owned: true, url: "https://cdn.jsdelivr.net/a.js" },
  ],
  [
    "tracked with a download url",
    { file: "a.js", tracked: true, url: "https://cdn.jsdelivr.net/a.js" },
  ],
  ["no mode at all", { file: "a.js" }],
]) {
  test(`rejects ${name} before any skip logic`, async () => {
    const io = {
      fetch: async () => assert.fail("no fetch"),
      readFile: async () => assert.fail("no read"),
      writeFile: async () => assert.fail("no write"),
    };
    for (const mode of ["--check", "--write"]) {
      await assert.rejects(syncAssets([entry], mode, io), /Invalid inventory entry: a\.js/);
    }
    await assert.rejects(checkInventory([entry], listing("a.js")), /Invalid inventory entry/);
  });
}

test("lists each file once", () => {
  const files = manifest.map((entry) => entry.file);
  assert.equal(new Set(files).size, files.length);
});

test("tracked entries match their committed bytes", async () => {
  const tracked = manifest.filter((entry) => entry.tracked);
  assert.ok(tracked.length > 0);
  await syncAssets(tracked, "--check");
});

test("tracked entries record version, digest and provenance", () => {
  for (const entry of manifest.filter((item) => item.tracked)) {
    assert.match(entry.sha256, /^[0-9a-f]{64}$/, entry.file);
    assert.ok(entry.package && entry.version, entry.file);
    // Without a source URL the entry must say why none exists.
    assert.ok(entry.source || entry.modified, entry.file);
    if (entry.source) assert.match(entry.source, /^https:\/\//, entry.file);
    if ("frozen" in entry) assert.ok(entry.frozen.length > 0, entry.file);
  }
});

test("owned entries carry no checksum", () => {
  for (const entry of manifest.filter((item) => item.owned)) {
    assert.equal(entry.sha256, undefined, entry.file);
  }
});

test("collects pins from the inventory and package.json", () => {
  const pins = collectPins(
    [
      { file: "a.js", package: "a", version: "1.0.0", frozen: "legacy" },
      { file: "b.js", package: "b", version: "1.0.0", npm: false },
      { file: "c.js", package: "c", version: "unknown" },
      { file: "d.js", owned: true },
    ],
    { e: "2.0.0", a: "1.1.0" },
  );
  assert.deepEqual(
    [...pins],
    [
      ["a", { version: "1.1.0", frozen: true }],
      ["e", { version: "2.0.0", frozen: false }],
    ],
  );
});

test("reports pins behind npm latest and ignores frozen ones", async () => {
  const latest = { cur: "1.2.3", old: "1.3.0", dev: "3.0.0", ahead: "1.0.0" };
  const io = { npmLatest: async (name) => latest[name] };
  const log = console.log;
  console.log = () => {};
  try {
    const behind = await reportOutdated(
      new Map([
        ["cur", { version: "1.2.3", frozen: false }],
        ["old", { version: "1.2.9", frozen: false }],
        ["dev", { version: "2.0.0", frozen: true }],
        ["ahead", { version: "1.0.1", frozen: false }],
      ]),
      io,
    );
    assert.equal(behind, 1);
  } finally {
    console.log = log;
  }
});

test("rejects an invalid npm package name before querying", async () => {
  const io = { npmLatest: async () => assert.fail("no query") };
  await assert.rejects(
    reportOutdated(new Map([["--bad", { version: "1.0.0" }]]), io),
    /Invalid npm package name/,
  );
});

const quiet = async (fn) => {
  const lines = [];
  const { log, error } = console;
  console.log = (line) => lines.push(line);
  console.error = (line) => lines.push(line);
  try {
    return { lines, result: await fn() };
  } catch (failure) {
    return { lines, failure };
  } finally {
    console.log = log;
    console.error = error;
  }
};

test("orders prerelease tags below the release", async () => {
  const cases = [
    ["1.0.0-beta.1", "1.0.0", 1],
    ["1.0.0", "1.0.0-beta.1", 0],
    ["1.0.0-beta.2", "1.0.0-beta.10", 1],
    ["1.0.0-beta.10", "1.0.0-beta.2", 0],
    ["1.0.0-alpha", "1.0.0-alpha.1", 1],
    ["1.0.0-alpha.1", "1.0.0-beta", 1],
    ["1.0.0-1", "1.0.0-alpha", 1],
    ["1.0.0-rc.1", "1.0.0-rc.1", 0],
  ];
  for (const [pinned, latest, behind] of cases) {
    const { result } = await quiet(() =>
      reportOutdated(new Map([["p", { version: pinned, frozen: false }]]), {
        npmLatest: async () => latest,
      }),
    );
    assert.equal(result, behind, `${pinned} vs ${latest}`);
  }
});

test("an npm view failure propagates and is never reported as current", async () => {
  const io = {
    npmLatest: async (name) => {
      if (name === "b") throw new Error("npm view failed: E404");
      return "1.0.0";
    },
  };
  const pins = new Map([
    ["a", { version: "1.0.0", frozen: false }],
    ["b", { version: "1.0.0", frozen: false }],
  ]);
  const { lines, failure } = await quiet(() => reportOutdated(pins, io));
  assert.match(failure.message, /E404/);
  assert.deepEqual(lines.filter((line) => line.startsWith("b:")), []);
  const exit = await quiet(() => runOutdated([], { b: "1.0.0" }, io));
  assert.match(exit.failure.message, /E404/);
});

test("the outdated run exits nonzero only when a pin is behind", async () => {
  const entries = [{ file: "x.js", package: "x", version: "1.0.0", tracked: true }];
  const io = (latest) => ({ npmLatest: async () => latest });
  assert.equal((await quiet(() => runOutdated(entries, {}, io("1.0.1")))).result, 1);
  assert.equal((await quiet(() => runOutdated(entries, {}, io("1.0.0")))).result, 0);
  const frozen = [{ ...entries[0], frozen: "legacy" }];
  assert.equal((await quiet(() => runOutdated(frozen, {}, io("2.0.0")))).result, 0);
});
