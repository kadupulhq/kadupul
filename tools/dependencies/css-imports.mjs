// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

import { createHash } from 'node:crypto';
import { readFile, writeFile } from 'node:fs/promises';

// Preserve source CSS cache busting when no AssetMapper manifest is installed.
// Process children first so a nested import changes every ancestor's version.
export async function versionCssImports(stylesheet, active = new Set()) {
  const key = stylesheet.href;
  if (active.has(key)) throw new Error(`Cyclic CSS import: ${stylesheet.pathname}`);
  active.add(key);
  try {
    const source = await readFile(stylesheet, 'utf8');
    const pattern = /\/\*[\s\S]*?\*\/|(@import\s+(?:url\(\s*)?['"])([^'"]+)(['"]\s*\)?)/g;
    let output = '';
    let offset = 0;
    for (const match of source.matchAll(pattern)) {
      if (!match[1]) continue; // Comment, not an import.
      const reference = match[2];
      if (/^(?:[a-z][a-z\d+.-]*:|\/|#)/i.test(reference)) continue;
      const target = new URL(reference, stylesheet);
      target.search = '';
      target.hash = '';
      const version = await versionCssImports(target, active);
      const query = new URL(reference, stylesheet);
      query.searchParams.set('v', version);
      const versioned = reference.split(/[?#]/, 1)[0] + query.search + query.hash;
      output += source.slice(offset, match.index) + match[1] + versioned + match[3];
      offset = match.index + match[0].length;
    }
    output += source.slice(offset);
    if (output !== source) await writeFile(stylesheet, output);
    return createHash('sha256').update(output).digest('hex');
  } finally {
    active.delete(key);
  }
}
