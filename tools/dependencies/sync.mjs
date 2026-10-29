// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

// Maintenance only. No Node.js dependency is added to the application runtime.
import { createHash } from "node:crypto";
import { execFile } from "node:child_process";
import { readdir, readFile, writeFile } from "node:fs/promises";
import { pathToFileURL } from "node:url";
import { promisify } from "node:util";

const hash = (bytes) => createHash("sha256").update(bytes).digest("hex");
const run = promisify(execFile);
const npmLatest = async (name) =>
  (await run("npm", ["view", name, "version"], { timeout: 30_000 })).stdout.trim();
const defaultIo = Object.freeze({
  fetch,
  readFile,
  writeFile,
  readdir,
  npmLatest,
});
const jsDirectory = new URL("../../include/js/", import.meta.url);

// Keep I/O bounded to one action and stop before starting the next on failure.
function forEachSerially(items, action) {
  return Array.from(items).reduce(
    (previous, item) => previous.then(() => action(item)),
    Promise.resolve(),
  );
}

function compareText(left, right) {
  if (left === right) return 0;
  return left < right ? -1 : 1;
}

function patchAsset(asset, originalBytes) {
  // Match the repository's LF checkout policy after verifying the original bytes.
  let source = originalBytes.toString("utf8").replaceAll("\r\n", "\n");
  if (asset.patch === "ui-back-compat") {
    const anchor = `var version = $.ui.version = "${asset.version}";`;
    if (source.split(anchor).length !== 2) {
      throw new Error("jQuery UI compatibility patch no longer applies");
    }
    source = source.replace(anchor, `${anchor}\n$.uiBackCompat = true;`);
  } else if (asset.patch) {
    throw new Error(`Unknown vendor patch: ${asset.patch}`);
  }
  for (const replacement of asset.replacements || []) {
    const count = replacement.count ?? 1;
    if (
      !replacement.before ||
      source.split(replacement.before).length !== count + 1
    ) {
      throw new Error(`Vendor replacement no longer applies: ${asset.file}`);
    }
    source = source.replaceAll(replacement.before, () => replacement.after);
  }
  return Buffer.from(source);
}

function checkFilename(asset) {
  if (!/^[a-zA-Z0-9.-]+$/.test(asset.file) || asset.file.startsWith(".")) {
    throw new Error(`Invalid asset filename: ${asset.file}`);
  }
}

// A tracked asset is committed as-is and never fetched or rewritten, so only
// --check looks at it. Its digest covers the tracked bytes, so any edit has to
// update the manifest.
async function verifyTracked(asset, io) {
  checkFilename(asset);
  const file = new URL(`../../include/js/${asset.file}`, import.meta.url);
  if (hash(await io.readFile(file)) !== asset.sha256) {
    throw new Error(
      `Tracked asset differs from its inventory checksum: ${asset.file}`,
    );
  }
  return asset;
}

async function prepareAsset(asset, io) {
  checkFilename(asset);
  const url = new URL(asset.url);
  if (
    url.protocol !== "https:" ||
    ![
      "cdn.jsdelivr.net",
      "code.jquery.com",
      "raw.githubusercontent.com",
    ].includes(url.hostname)
  ) {
    throw new Error(`Unexpected upstream: ${url.origin}`);
  }
  const response = await io.fetch(url, {
    signal: AbortSignal.timeout(30_000),
    redirect: "error",
  });
  if (!response.ok) throw new Error(`${response.status}: ${url}`);
  const bytes = Buffer.from(await response.arrayBuffer());
  if (hash(bytes) !== asset.sha256)
    throw new Error(`Upstream checksum mismatch: ${asset.file}`);
  return {
    file: new URL(`../../include/js/${asset.file}`, import.meta.url),
    bytes: patchAsset(asset, bytes),
    asset,
  };
}

// Fail closed on malformed flags: a string such as "false" is truthy and would
// silently skip verification.
export function validateManifest(manifest) {
  for (const asset of manifest) {
    for (const flag of ["owned", "tracked", "npm"]) {
      if (flag in asset && typeof asset[flag] !== "boolean") {
        throw new Error(`Invalid inventory entry: ${asset.file}: "${flag}" must be a boolean`);
      }
    }
    const modes = [
      asset.owned === true,
      asset.tracked === true,
      asset.url !== undefined,
    ].filter(Boolean).length;
    if (modes !== 1) {
      throw new Error(
        `Invalid inventory entry: ${asset.file}: set exactly one of owned, tracked or url`,
      );
    }
  }
}

// Non-recursive on purpose: the LC_MESSAGES locale files are not inventoried.
export async function checkInventory(
  manifest,
  io = defaultIo,
  directory = jsDirectory,
) {
  validateManifest(manifest);
  const present = new Set(
    (await io.readdir(directory, { withFileTypes: true }))
      .filter((entry) => entry.isFile() && entry.name !== "index.php")
      .map((entry) => entry.name),
  );
  const listed = new Set(manifest.map((asset) => asset.file));
  for (const file of [...present].sort(compareText)) {
    if (!listed.has(file)) {
      throw new Error(`include/js/${file} has no inventory entry`);
    }
  }
  for (const file of [...listed].sort(compareText)) {
    if (!present.has(file)) {
      throw new Error(`Inventory entry ${file} does not exist in include/js`);
    }
  }
}

export async function syncAssets(manifest, mode, io = defaultIo) {
  if (!["--check", "--write"].includes(mode)) {
    throw new Error("Usage: node tools/dependencies/sync.mjs --check|--write");
  }
  validateManifest(manifest);
  const prepared = [];
  const tracked = [];
  // Keep requests serial and finish validation before the first write.
  await forEachSerially(manifest, async (asset) => {
    if (asset.owned) return;
    if (asset.tracked) {
      if (mode === "--check") tracked.push(await verifyTracked(asset, io));
    } else {
      prepared.push(await prepareAsset(asset, io));
    }
  });

  // Validate every download before modifying any tracked asset.
  await forEachSerially(prepared, async ({ file, bytes, asset }) => {
    if (mode === "--write") {
      await io.writeFile(file, bytes);
    } else if (!bytes.equals(await io.readFile(file))) {
      throw new Error(
        `Bundled asset differs from its pinned source: ${asset.file}`,
      );
    }
    console.log(`${asset.file}: ${asset.package}@${asset.version} verified`);
  });
  for (const asset of tracked) {
    console.log(`${asset.file}: ${asset.package}@${asset.version} tracked`);
  }
}

const versionPattern = /^(\d+)\.(\d+)\.(\d+)(?:-([0-9A-Za-z.-]+))?(?:\+.*)?$/;

function compareVersionIdentifier(left, right) {
  if (left === right) return 0;
  const leftNumeric = /^\d+$/.test(left);
  const rightNumeric = /^\d+$/.test(right);
  if (leftNumeric && rightNumeric) {
    const x = BigInt(left);
    const y = BigInt(right);
    if (x === y) return 0;
    return x < y ? -1 : 1;
  }
  if (leftNumeric !== rightNumeric) return leftNumeric ? -1 : 1;
  return compareText(left, right);
}

function compareVersionIdentifiers(left, right) {
  for (let i = 0; i < Math.min(left.length, right.length); i++) {
    const order = compareVersionIdentifier(left[i], right[i]);
    if (order !== 0) return order;
  }
  return Math.sign(left.length - right.length);
}

// Numeric identifiers have no SemVer size limit; Number would lose precision.
function compareVersions(a, b) {
  const x = versionPattern.exec(a);
  const y = versionPattern.exec(b);
  if (!x || !y) return a === b ? 0 : Number.NaN;
  const core = compareVersionIdentifiers(x.slice(1, 4), y.slice(1, 4));
  if (core !== 0) return core;
  if (x[4] === y[4]) return 0;
  if (x[4] === undefined) return 1;
  if (y[4] === undefined) return -1;
  return compareVersionIdentifiers(x[4].split("."), y[4].split("."));
}

// An unparseable version counts as behind unless it equals the latest.
const isBehind = (pinned, latest) => {
  const order = compareVersions(pinned, latest);
  return Number.isNaN(order) || order < 0;
};

// package.json pins and inventory entries describe the same packages; the
// package.json version wins so the report matches what npm installs.
export function collectPins(manifest, dependencies = {}) {
  const pins = new Map();
  for (const asset of manifest) {
    if (asset.owned || asset.npm === false || asset.version === "unknown") {
      continue;
    }
    const known = pins.get(asset.package);
    pins.set(asset.package, {
      version: asset.version,
      frozen: Boolean(asset.frozen) || known?.frozen === true,
    });
  }
  for (const [name, version] of Object.entries(dependencies)) {
    pins.set(name, { version, frozen: pins.get(name)?.frozen === true });
  }
  return pins;
}

export async function reportOutdated(pins, io = defaultIo) {
  let behind = 0;
  const entries = [...pins].sort(([left], [right]) => compareText(left, right));
  // Query in report order and stop before querying later packages on failure.
  await forEachSerially(entries, async ([name, { version, frozen }]) => {
    if (!/^(@[a-z0-9~][a-z0-9-._~]*\/)?[a-z0-9~][a-z0-9-._~]*$/.test(name)) {
      throw new Error(`Invalid npm package name: ${name}`);
    }
    const latest = await io.npmLatest(name);
    const stale = isBehind(version, latest);
    if (stale && !frozen) behind++;
    let state = "current";
    if (stale) state = frozen ? "frozen" : "behind";
    console.log(`${name}: pinned ${version}, latest ${latest} (${state})`);
  });
  return behind;
}

export async function runOutdated(manifest, dependencies, io = defaultIo) {
  const behind = await reportOutdated(collectPins(manifest, dependencies), io);
  if (behind > 0) {
    console.error(`${behind} pinned package(s) are behind npm latest`);
    return 1;
  }
  return 0;
}

if (
  process.argv[1] &&
  import.meta.url === pathToFileURL(process.argv[1]).href
) {
  const args = process.argv.slice(2);
  if (args.length !== 1)
    throw new Error("Supply exactly one mode: --check, --write or --outdated");
  const manifest = JSON.parse(
    await readFile(new URL("assets.json", import.meta.url), "utf8"),
  );
  if (args[0] === "--outdated") {
    const { dependencies } = JSON.parse(
      await readFile(new URL("../../package.json", import.meta.url), "utf8"),
    );
    process.exitCode = await runOutdated(manifest, dependencies);
  } else {
    if (args[0] === "--check") await checkInventory(manifest);
    await syncAssets(manifest, args[0]);
  }
}
