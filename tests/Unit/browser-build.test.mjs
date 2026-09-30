// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

import test, { after } from 'node:test';
import assert from 'node:assert/strict';
import { mkdir, mkdtemp, readdir, readFile, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { pathToFileURL } from 'node:url';
import { installFontAwesome } from '../../tools/dependencies/fontawesome.mjs';

const root = new URL('../../', import.meta.url);
const anchor = '.fa-circle-notch {\n  --fa: "\\f1ce";\n}';
const scratch = pathToFileURL(await mkdtemp(join(tmpdir(), 'fa-test-')) + '/');
let fixtures = 0;
after(() => rm(scratch, { recursive: true, force: true }));

async function fixturePackage(css, { version = '7.3.1', fonts = ['fa-solid-900.woff2'] } = {}) {
  const base = new URL(`${++fixtures}/`, scratch);
  const source = new URL('package/', base);
  await mkdir(new URL('css/', source), { recursive: true });
  await mkdir(new URL('webfonts/', source), { recursive: true });
  await mkdir(new URL('svgs/solid/', source), { recursive: true });
  await writeFile(new URL('package.json', source), JSON.stringify({ name: '@fortawesome/fontawesome-free', version }));
  await writeFile(new URL('LICENSE.txt', source), 'licence');
  await writeFile(new URL('css/all.css', source), css);
  await writeFile(new URL('svgs/solid/circle.svg', source), '<svg/>');
  for (const font of fonts) await writeFile(new URL(`webfonts/${font}`, source), font);
  return { source, destination: new URL('fa/', base) };
}

async function tree(directory, prefix = '') {
  const entries = [];
  for (const entry of await readdir(directory, { withFileTypes: true })) {
    if (entry.isDirectory()) entries.push(...await tree(new URL(`${entry.name}/`, directory), `${prefix}${entry.name}/`));
    else entries.push(prefix + entry.name);
  }
  return entries.sort();
}

test('locked npm packages produce the legacy browser assets and compatibility aliases', async () => {
  // A leftover from an older full-package copy must not survive a rebuild.
  await mkdir(new URL('include/fa/svgs/solid/', root), { recursive: true });
  await writeFile(new URL('include/fa/svgs/solid/stale.svg', root), '<svg/>');
  await import('../../tools/dependencies/build.mjs');
  const flags = JSON.parse(await readFile(new URL('include/vendor/flag-icons/package.json', root), 'utf8'));
  assert.equal(flags.name, 'flag-icons');
  assert.match(await readFile(new URL('include/vendor/flag-icons/flags/4x3/us.svg', root), 'utf8'), /<svg/);
  const iconCss = await readFile(new URL('include/fa/css/all.css', root), 'utf8');
  assert.match(iconCss, /\.fa\.fa-circle-thin \{\s*--fa: "\\f111";\s*--fa-style: 400;\s*\}/, 'the legacy alias draws the regular outline');
  assert.equal(iconCss.split('.fa.fa-circle-thin {').length, 2, 'install the legacy alias exactly once');
  assert.match(iconCss, /\.fa-circle-notch \{\s*--fa: "\\f1ce";\s*\}/);
  assert.match(iconCss, /\.fa\)::before \{\s*content: var\(--fa\)/);
  assert.match(await readFile(new URL('include/js/purify.js', root), 'utf8'), /DOMPurify/);
});

test('the Font Awesome build ships only the stylesheet, its fonts, the licence and directory guards', async () => {
  const { version } = JSON.parse(await readFile(new URL('node_modules/@fortawesome/fontawesome-free/package.json', root), 'utf8'));
  const installed = await tree(new URL('include/fa/', root));
  const fonts = installed.filter((file) => file.startsWith('webfonts/') && file.endsWith('.woff2'));
  assert.ok(fonts.length > 0);
  assert.deepEqual(installed, ['LICENSE.txt', 'css/all.css', 'css/index.php', 'index.php', ...fonts, 'webfonts/index.php'].sort());
  for (const guard of ['index.php', 'css/index.php', 'webfonts/index.php']) {
    const source = await readFile(new URL(`include/fa/${guard}`, root), 'utf8');
    assert.match(source, /SPDX-License-Identifier: GPL-3\.0-or-later/);
    assert.match(source, /header\("Location:\.\.\/index\.php"\);/);
  }
  const iconCss = await readFile(new URL('include/fa/css/all.css', root), 'utf8');
  const urls = [...iconCss.matchAll(/url\(([^)]*)\)/g)].map((match) => match[1]);
  assert.ok(urls.length > 0);
  for (const url of urls) {
    const [, file, query] = url.match(/^"\.\.\/webfonts\/([^"?]+)\?([^"]+)"$/);
    assert.equal(query, `v=${version}`);
    assert.ok(fonts.includes(`webfonts/${file}`), `${file} is bundled`);
  }
});

test('versions quoted and unquoted font URLs and drops unreferenced package files', async () => {
  const css = `${anchor}\n@font-face { src: url("../webfonts/fa-solid-900.woff2") format("woff2"); }\n`
    + '@font-face { src: url(../webfonts/fa-brands-400.woff2); }\n'
    + "@font-face { src: url('../webfonts/fa-solid-900.woff2'); }\n";
  const { source, destination } = await fixturePackage(css, { fonts: ['fa-brands-400.woff2', 'fa-solid-900.woff2', 'fa-solid-900.ttf'] });
  await mkdir(new URL('js/', destination), { recursive: true });
  await writeFile(new URL('js/all.js', destination), 'stale');
  assert.deepEqual(await installFontAwesome(source, destination), { version: '7.3.1', fonts: ['fa-brands-400.woff2', 'fa-solid-900.woff2'] });
  assert.deepEqual(await tree(destination), [
    'LICENSE.txt', 'css/all.css', 'css/index.php', 'index.php',
    'webfonts/fa-brands-400.woff2', 'webfonts/fa-solid-900.woff2', 'webfonts/index.php',
  ]);
  const installed = await readFile(new URL('css/all.css', destination), 'utf8');
  assert.match(installed, /url\("\.\.\/webfonts\/fa-solid-900\.woff2\?v=7\.3\.1"\) format\("woff2"\)/);
  assert.match(installed, /url\(\.\.\/webfonts\/fa-brands-400\.woff2\?v=7\.3\.1\)/);
  assert.match(installed, /url\('\.\.\/webfonts\/fa-solid-900\.woff2\?v=7\.3\.1'\)/);
  assert.match(installed, /\.fa\.fa-circle-thin \{\s*--fa: "\\f111";\s*--fa-style: 400;\s*\}/);
});

test('rejects a stylesheet whose fonts are missing or cannot be versioned, leaving the old tree intact', async () => {
  const cases = [
    [`${anchor}\nsrc: url("../webfonts/fa-regular-400.woff2");`, {}, /references a missing font: fa-regular-400\.woff2/],
    [`${anchor}\nsrc: url("../webfonts/fa-solid-900.ttf");`, { fonts: ['fa-solid-900.ttf'] }, /cannot be versioned/],
    [`${anchor}\nsrc: url("../webfonts/fa-solid-900.woff2?v=1");`, {}, /cannot be versioned/],
    [anchor, {}, /cannot be versioned/],
    ['src: url("../webfonts/fa-solid-900.woff2");', {}, /compatibility patch no longer applies/],
    [`${anchor}\nsrc: url("../webfonts/fa-solid-900.woff2");`, { version: '7.3.1"><x' }, /Unexpected Font Awesome version/],
  ];
  for (const [css, options, error] of cases) {
    const { source, destination } = await fixturePackage(css, options);
    await mkdir(destination, { recursive: true });
    await writeFile(new URL('index.php', destination), 'previous');
    await assert.rejects(installFontAwesome(source, destination), error);
    assert.equal(await readFile(new URL('index.php', destination), 'utf8'), 'previous');
  }
});
