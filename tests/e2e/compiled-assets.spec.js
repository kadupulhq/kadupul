// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

// Loads the stylesheets html_common_header() emits once asset-map:compile has
// run, through the same <url_path>public/assets/ URLs, and checks that every
// @import, image and Font Awesome font they reference is served.
const root = path.resolve(__dirname, '../..');
const manifestFile = path.join(root, 'public/assets/manifest.json');
const themes = fs.readdirSync(path.join(root, 'include/themes'), { withFileTypes: true })
  .filter(entry => entry.isDirectory() && fs.existsSync(path.join(root, 'include/themes', entry.name, 'main.css')))
  .map(entry => entry.name)
  .sort();

const header = fs.readFileSync(path.join(root, 'lib/html.php'), 'utf8');
const stylesheets = theme => [...header.matchAll(/get_md5_include_css\('([^']*)'(?:\s*\.\s*\$selectedTheme\s*\.\s*'([^']*)')?/g)]
  .map(([, head, tail]) => (tail === undefined ? head : head + theme + tail))
  .filter(file => file !== 'include/themes/custom.css');

function manifest() {
  if (!fs.existsSync(manifestFile)) {
    throw new Error('public/assets/manifest.json is missing; run php bin/console asset-map:compile first');
  }
  return JSON.parse(fs.readFileSync(manifestFile, 'utf8'));
}

for (const theme of themes) {
  test(`${theme} loads its compiled stylesheets and fonts`, async ({ page }) => {
    const compiled = manifest();
    const hrefs = stylesheets(theme).map(file => (compiled[file] ? `/public${compiled[file]}` : `/${file}`));
    expect(hrefs.filter(href => href.startsWith('/public/assets/')).length).toBeGreaterThan(10);

    const failed = [];
    const served = [];
    page.on('requestfailed', request => failed.push(`${request.url()} ${request.failure()?.errorText}`));
    page.on('response', response => {
      const url = new URL(response.url());
      if (response.status() >= 400) failed.push(`${url.pathname} HTTP ${response.status()}`);
      served.push(url.pathname);
    });

    const links = hrefs.map(href => `<link href='${href}' type='text/css' rel='stylesheet'>`).join('\n');
    await page.route('**/compiled-assets.html', route => route.fulfill({
      contentType: 'text/html',
      body: `<!DOCTYPE html><html><head><meta charset="utf-8">${links}</head><body>
        <i id="solid" class="fas fa-house"></i><i id="regular" class="far fa-circle"></i><i id="brands" class="fab fa-github"></i>
      </body></html>`,
    }));
    await page.goto('/compiled-assets.html', { waitUntil: 'load' });
    await page.evaluate(() => document.fonts.ready);

    expect(failed).toEqual([]);

    const sheets = await page.evaluate(() => [...document.styleSheets].map(sheet => ({ href: sheet.href, rules: sheet.cssRules.length })));
    for (const sheet of sheets) {
      expect(sheet.rules, `${sheet.href} parsed`).toBeGreaterThan(0);
    }

    const fonts = served.filter(file => file.endsWith('.woff2'));
    expect(fonts.length, 'Font Awesome fonts requested').toBeGreaterThanOrEqual(3);
    for (const font of fonts) {
      expect(font).toMatch(/^\/public\/assets\/include\/fa\/webfonts\/fa-[\w-]+-[\w-]{7}\.woff2$/);
    }

    const icons = await page.evaluate(() => ['solid', 'regular', 'brands'].map(id => {
      const icon = document.getElementById(id);
      return { id, content: getComputedStyle(icon, '::before').content, width: icon.getBoundingClientRect().width };
    }));
    for (const icon of icons) {
      expect(icon.content, `${icon.id} glyph`).not.toMatch(/^(none|normal|"")$/);
      expect(icon.width, `${icon.id} width`).toBeGreaterThan(0);
    }
    const families = await page.evaluate(() => [...document.fonts].filter(face => face.status === 'loaded').map(face => face.family.replace(/"/g, '')));
    expect(families).toEqual(expect.arrayContaining(['Font Awesome 7 Free', 'Font Awesome 7 Brands']));

    // Stylesheets reached only through @import must come from the compiled
    // tree too, or midwinter would still depend on hand-kept query hashes.
    const imported = served.filter(file => file.endsWith('.css') && !hrefs.includes(file));
    if (theme === 'midwinter') expect(imported.length).toBeGreaterThanOrEqual(15);
    for (const file of imported) {
      expect(file).toMatch(/^\/public\/assets\/include\/themes\//);
    }
  });
}
