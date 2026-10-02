// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

// Every <i> the theme scripts inject must draw a glyph with the Font Awesome
// build lib/html.php serves, which ships without the v4 shims.
const themes = ['classic', 'modern', 'dark', 'paper-plane', 'paw', 'sunrise'];
const injected = Object.fromEntries(themes.map(theme => {
  const source = fs.readFileSync(path.resolve(__dirname, `../../include/themes/${theme}/main.js`), 'utf8');
  const classes = [...source.matchAll(/<i class=["']([^"']+)["']/g)].map(match => match[1]);
  return [theme, [...new Set(classes)]];
}).filter(([, classes]) => classes.length));

async function glyphs(page, classes) {
  await page.goto('/tests/e2e/theme-smoke.html');
  await page.addStyleTag({ url: '/include/fa/css/all.css' });
  await page.evaluate(() => document.fonts.ready);

  return page.evaluate(list => list.map(name => {
    const icon = document.createElement('i');
    icon.className = name;
    document.body.appendChild(icon);
    const content = getComputedStyle(icon, '::before').content;
    const width = icon.getBoundingClientRect().width;
    icon.remove();
    return { name, content, width };
  }), classes);
}

for (const [theme, classes] of Object.entries(injected)) {
  test(`${theme} injects icons that render a glyph`, async ({ page }) => {
    for (const icon of await glyphs(page, classes)) {
      expect(icon.content, `${icon.name} has glyph content`).not.toMatch(/^(none|normal|"")$/);
      expect(icon.width, `${icon.name} takes up space`).toBeGreaterThan(0);
    }
  });
}

test('Font Awesome 4 names the themes used to inject render nothing', async ({ page }) => {
  for (const icon of await glyphs(page, ['fa fa-arrow-circle-o-up', 'fa fa-sun-o', 'fa fa-trash-o'])) {
    expect(icon.content, `${icon.name} has no glyph`).toMatch(/^(none|normal|"")$/);
  }
});
