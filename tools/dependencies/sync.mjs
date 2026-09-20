// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

// Maintenance only. No Node.js dependency is added to the application runtime.
import { createHash } from "node:crypto";
import { readFile, writeFile } from "node:fs/promises";
import { pathToFileURL } from "node:url";

const hash = (bytes) => createHash("sha256").update(bytes).digest("hex");
const defaultIo = Object.freeze({ fetch, readFile, writeFile });

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

async function prepareAsset(asset, io) {
  if (!/^[a-zA-Z0-9.-]+$/.test(asset.file) || asset.file.startsWith(".")) {
    throw new Error(`Invalid asset filename: ${asset.file}`);
  }
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
  const response = await io.fetch(url, { signal: AbortSignal.timeout(30_000) });
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

export async function syncAssets(manifest, mode, io = defaultIo) {
  if (!["--check", "--write"].includes(mode)) {
    throw new Error("Usage: node tools/dependencies/sync.mjs --check|--write");
  }
  const prepared = [];
  for (const asset of manifest) {
    prepared.push(await prepareAsset(asset, io));
  }

  // Validate every download before modifying any tracked asset.
  for (const { file, bytes, asset } of prepared) {
    if (mode === "--write") {
      await io.writeFile(file, bytes);
    } else if (!bytes.equals(await io.readFile(file))) {
      throw new Error(
        `Bundled asset differs from its pinned source: ${asset.file}`,
      );
    }
    console.log(`${asset.file}: ${asset.package}@${asset.version} verified`);
  }
}

if (
  process.argv[1] &&
  import.meta.url === pathToFileURL(process.argv[1]).href
) {
  const args = process.argv.slice(2);
  if (args.length !== 1)
    throw new Error("Supply exactly one mode: --check or --write");
  const manifest = JSON.parse(
    await readFile(new URL("assets.json", import.meta.url), "utf8"),
  );
  await syncAssets(manifest, args[0]);
}
