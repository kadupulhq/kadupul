// Reproduce pinned LTS vendor assets without executing package lifecycle scripts.
import { readFile, writeFile, mkdir, lstat, realpath, readdir } from 'node:fs/promises';
import { createHash } from 'node:crypto';
import { resolve, dirname, relative, isAbsolute } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const args = process.argv.slice(2);
const write = args.includes('--write');
const sourceArg = args.find(arg => arg.startsWith('--source='));
const sourceRoot = sourceArg ? await realpath(sourceArg.slice(9)) : null;
if (args.some(arg => !['--write', '--check'].includes(arg) && !arg.startsWith('--source='))) {
  throw new Error('Usage: node tools/dependencies/sync.mjs [--check|--write] [--source=/path/to/node_modules]');
}
const manifest = JSON.parse(await readFile(resolve(root, 'tools/dependencies/assets.json'), 'utf8'));
function contained(base, path) {
  const rel = relative(base, path);
  if (!rel || rel.startsWith('..') || isAbsolute(rel)) throw new Error(`Unsafe asset path: ${path}`);
  return path;
}
async function safeDestination(path) {
  if (!path.startsWith('include/')) throw new Error(`Invalid destination: ${path}`);
  const destination = contained(root, resolve(root, path));
  let parent = destination;
  while (parent !== root) {
    try {
      if ((await lstat(parent)).isSymbolicLink()) throw new Error(`Symlink destination: ${parent}`);
    } catch (error) {
      if (error.code !== 'ENOENT') throw error;
    }
    parent = dirname(parent);
  }
  return destination;
}
async function walkPackageDirectory(packageRoot, directory, paths) {
  const path = contained(packageRoot, await realpath(resolve(packageRoot, directory)));
  for (const item of await readdir(path, { withFileTypes: true })) {
    if (item.isSymbolicLink()) throw new Error(`Symlink in package: ${item.name}`);
    const child = `${directory}/${item.name}`;
    if (item.isDirectory()) await walkPackageDirectory(packageRoot, child, paths);
    else if (item.isFile()) paths.push(child);
  }
}
const entries = [];
for (const entry of manifest) {
  if (!entry.treeSha256) {
    entries.push(entry);
    continue;
  }
  if (!sourceRoot) throw new Error('Font Awesome requires --source pointing to pinned npm packages (see README).');
  const packageRoot = contained(sourceRoot, await realpath(resolve(sourceRoot, entry.package)));
  const metadata = JSON.parse(await readFile(resolve(packageRoot, 'package.json'), 'utf8'));
  if (metadata.version !== entry.version) throw new Error(`Wrong package version: ${entry.package}`);
  const paths = [...entry.files];
  for (const directory of entry.directories) await walkPackageDirectory(packageRoot, directory, paths);
  // Hash ordering is deliberately code-unit lexical, never locale-dependent.
  paths.sort((left, right) => left < right ? -1 : Number(left > right));
  const hash = createHash('sha256');
  const expanded = [];
  for (const path of paths) {
    const source = contained(packageRoot, await realpath(resolve(packageRoot, path)));
    const digest = createHash('sha256').update(await readFile(source)).digest('hex');
    hash.update(`${path}\0${digest}\n`);
    expanded.push({ file: `${entry.file}/${path}`, package: entry.package, source: path, sha256: digest });
  }
  if (paths.length !== entry.count || hash.digest('hex') !== entry.treeSha256) {
    throw new Error(`Package tree checksum mismatch: ${entry.package}`);
  }
  entries.push(...expanded);
}
let changed = 0;
for (const entry of entries) {
  const destination = await safeDestination(entry.file);
  let data;
  if (sourceRoot && entry.source) {
    const source = await realpath(contained(sourceRoot, resolve(sourceRoot, entry.package, entry.source)));
    contained(sourceRoot, source);
    data = await readFile(source);
  } else {
    const url = new URL(entry.url);
    if (url.protocol !== 'https:' || !['cdn.jsdelivr.net', 'raw.githubusercontent.com'].includes(url.hostname)) {
      throw new Error(`Untrusted asset URL: ${entry.url}`);
    }
    const response = await fetch(url, { signal: AbortSignal.timeout(30000) });
    if (!response.ok) throw new Error(`${entry.file}: HTTP ${response.status}`);
    data = Buffer.from(await response.arrayBuffer());
  }
  if (createHash('sha256').update(data).digest('hex') !== entry.sha256) {
    throw new Error(`Upstream checksum mismatch: ${entry.file}`);
  }
  if (entry.replacements) {
    let text = data.toString('utf8');
    for (const patch of entry.replacements) {
      if (!patch.before || text.split(patch.before).length - 1 !== (patch.count ?? 1)) {
        throw new Error(`Patch context mismatch: ${entry.file}`);
      }
      text = text.split(patch.before).join(patch.after);
    }
    data = Buffer.from(text);
  }
  // Match Git text normalization so fresh checkouts reproduce the same bytes.
  if (/\.(js|map|css|scss|less|json|yml|yaml|txt|svg)$/i.test(entry.file)) {
    data = Buffer.from(data.toString('utf8').replaceAll('\r\n', '\n'));
  }
  let current;
  try { current = await readFile(destination); } catch (error) { if (error.code !== 'ENOENT') throw error; }
  if (!current || !data.equals(current)) {
    changed++;
    if (write) {
      await mkdir(dirname(destination), { recursive: true });
      await writeFile(destination, data);
    } else {
      console.error(`Out of sync: ${entry.file}`);
    }
  }
}
console.log(`${entries.length} pinned assets verified; ${changed} ${write ? 'updated' : 'out of sync'}.`);
if (!write && changed) process.exitCode = 1;
