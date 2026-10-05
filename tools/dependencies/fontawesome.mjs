// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

import { copyFile, mkdir, readdir, readFile, rm, writeFile } from 'node:fs/promises';

// Font Awesome 5 and 7 share font file names, and browsers cache fonts by URL,
// so each font URL carries the package version.
const fontUrl = /url\((["']?)\.\.\/webfonts\/([\w.-]+\.woff2)\1\)/g;

const guard = `<?php
/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

header("Location:../index.php");
`;

// Pages load only css/all.css and the fonts it names; the rest of the npm
// package (SVGs, sprites, metadata, JS) stays out of the web root.
export async function installFontAwesome(source, destination) {
  const { version } = JSON.parse(await readFile(new URL('package.json', source), 'utf8'));
  if (!/^\d+\.\d+\.\d+$/.test(version)) throw new Error(`Unexpected Font Awesome version: ${version}`);

  let css = await readFile(new URL('css/all.css', source), 'utf8');
  // Preserve the legacy outline glyph used by existing screens/plugins.
  // Font Awesome 4 drew circle-thin in the regular face, not the solid face.
  const anchor = '.fa-circle-notch {\n  --fa: "\\f1ce";\n}';
  if (css.split(anchor).length !== 2) throw new Error('Font Awesome compatibility patch no longer applies');
  css = css.replace(anchor, anchor + '\n\n.fa.fa-circle-thin {\n  --fa: "\\f111";\n  --fa-style: 400;\n}');

  const fonts = (await readdir(new URL('webfonts/', source))).filter((name) => name.endsWith('.woff2')).sort();
  let rewritten = 0;
  css = css.replace(fontUrl, (match, quote, name) => {
    if (!fonts.includes(name)) throw new Error(`Font Awesome stylesheet references a missing font: ${name}`);
    rewritten++;
    return `url(${quote}../webfonts/${name}?v=${version}${quote})`;
  });
  if (rewritten === 0 || rewritten !== css.split('url(').length - 1) {
    throw new Error('Font Awesome stylesheet has font URLs that cannot be versioned');
  }

  // Start from an empty tree so files from an older package never linger.
  await rm(destination, { recursive: true, force: true });
  await mkdir(new URL('css/', destination), { recursive: true });
  await mkdir(new URL('webfonts/', destination), { recursive: true });
  for (const name of fonts) {
    await copyFile(new URL(`webfonts/${name}`, source), new URL(`webfonts/${name}`, destination));
  }
  await copyFile(new URL('LICENSE.txt', source), new URL('LICENSE.txt', destination));
  await writeFile(new URL('css/all.css', destination), css);
  for (const directory of ['', 'css/', 'webfonts/']) {
    await writeFile(new URL(`${directory}index.php`, destination), guard);
  }
  return { version, fonts };
}
