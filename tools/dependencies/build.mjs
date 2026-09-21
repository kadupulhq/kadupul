// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

import { readFile, writeFile, mkdir, cp } from 'node:fs/promises';
import { syncAssets } from './sync.mjs';

const manifest = JSON.parse(await readFile(new URL('./assets.json', import.meta.url)));
const root = new URL('../../', import.meta.url);
// Keep existing URLs and reviewed security patches while sourcing bytes from npm.
const paths = {
  'jquery.js': 'jquery/dist/jquery.js',
  'purify.js': 'dompurify/dist/purify.js',
  'purify.js.map': 'dompurify/dist/purify.js.map',
  'd3.js': 'd3/dist/d3.js',
  'jquery-ui.js': 'jquery-ui/dist/jquery-ui.js',
  'jquery.tablesorter.js': 'tablesorter/dist/js/jquery.tablesorter.js',
  'jquery.tablesorter.widgets.js': 'tablesorter/dist/js/jquery.tablesorter.widgets.js',
  'jquery.tablesorter.pager.js': 'tablesorter/addons/pager/jquery.tablesorter.pager.js',
};
await mkdir(new URL('include/js/', root), { recursive: true });
await syncAssets(manifest, '--write', {
  readFile,
  writeFile,
  async fetch(url) {
    if (!(url instanceof URL)) throw new TypeError('Expected an asset URL');
    const asset = manifest.find((entry) => entry.url === url.href);
    if (!asset || !paths[asset.file]) throw new Error('Unmapped npm asset');
    // npm's tablesorter distribution omits the unminified pager; retain its small pinned source.
    const source = asset.file === 'jquery.tablesorter.pager.js'
      ? 'include/js/jquery.tablesorter.pager.js'
      : `node_modules/${paths[asset.file]}`;
    const bytes = await readFile(new URL(source, root));
    return { ok: true, arrayBuffer: async () => bytes };
  },
});

const flags = new URL('include/vendor/flag-icons/', root);
await mkdir(flags, { recursive: true });
for (const path of ['css', 'flags', 'LICENSE', 'package.json']) {
  await cp(new URL(`node_modules/flag-icons/${path}`, root), new URL(path, flags), { recursive: true });
}
console.log('flag-icons: npm assets installed');

await cp(new URL("node_modules/@fortawesome/fontawesome-free/", root), new URL("include/fa/", root), { recursive: true });

// Preserve the legacy circle-thin alias used by existing screens/plugins.
const allCss = new URL('include/fa/css/all.css', root);
const css = await readFile(allCss, 'utf8');
const anchor = '.fa-circle-notch:before {\n  content: "\\f1ce"; }';
if (css.split(anchor).length !== 2) throw new Error('Font Awesome compatibility patch no longer applies');
await writeFile(allCss, css.replace(anchor, anchor + '\n\n.fa.fa-circle-thin:before {\n  content: "\\f111"; }'));
