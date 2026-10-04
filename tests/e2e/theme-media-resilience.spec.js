/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2026 The Kadupul project and contributors                 |
 |                                                                         |
 | This program is free software; you can redistribute it and/or           |
 | modify it under the terms of the GNU General Public License             |
 | as published by the Free Software Foundation; either version 2          |
 | of the License, or (at your option) any later version.                  |
 +-------------------------------------------------------------------------+
*/

const fs = require('node:fs');
const path = require('node:path');
const { test, expect } = require('@playwright/test');
const { themes, consolePage } = require('./theme-console-page');

const root = path.resolve(__dirname, '../..');
const headers = ['include/top_header.php', 'include/top_graph_header.php', 'include/top_general_header.php'];

// Take the skip link from the header template so the test follows the markup that ships.
function skipLink() {
  const source = fs.readFileSync(path.join(root, 'include/top_header.php'), 'utf8');
  return source.match(/<a class='skip-link'[^>]*>[^<]*<\/a>/)[0];
}

async function open(page, theme) {
  await page.route('**/__theme_media', route => route.fulfill({
    contentType: 'text/html', body: consolePage(theme, skipLink()),
  }));
  await page.goto('/__theme_media');
  await page.evaluate(() => document.fonts.ready);
}

test('every page header renders the same skip link without an inline style', () => {
  for (const header of headers) {
    const source = fs.readFileSync(path.join(root, header), 'utf8');
    expect(source, header).toContain(skipLink());
  }
  expect(skipLink()).not.toContain('style=');
});

for (const theme of themes) {
  test.describe(theme, () => {
    test('skip link is hidden until it receives keyboard focus', async ({ page }) => {
      await open(page, theme);
      const link = page.locator('.skip-link');
      const before = await link.boundingBox();
      expect(before === null || (before.width <= 1 && before.height <= 1)).toBeTruthy();

      await page.keyboard.press('Tab');
      await expect(link).toBeFocused();
      const focused = await link.boundingBox();
      expect(focused.width).toBeGreaterThan(20);
      expect(focused.height).toBeGreaterThan(10);
      expect(focused.x).toBeGreaterThanOrEqual(0);
      expect(focused.y).toBeGreaterThanOrEqual(0);
    });

    test('reduced motion stops animations and transitions', async ({ page }) => {
      await page.emulateMedia({ reducedMotion: 'reduce' });
      await open(page, theme);
      const longest = await page.evaluate(() => {
        const seconds = value => Math.max(...value.split(',').map(part => {
          const number = parseFloat(part);
          return part.trim().endsWith('ms') ? number / 1000 : number;
        }));
        let max = 0;
        for (const element of document.querySelectorAll('*')) {
          for (const pseudo of [null, '::before', '::after']) {
            const style = getComputedStyle(element, pseudo);
            max = Math.max(max, seconds(style.transitionDuration), seconds(style.animationDuration));
          }
        }
        return max;
      });
      expect(longest).toBeLessThan(0.001);
    });

    test('forced colors keep selected rows, tabs and switches distinguishable', async ({ page }) => {
      await page.emulateMedia({ forcedColors: 'active' });
      await open(page, theme);
      const marks = await page.evaluate(() => {
        const look = (selector, pseudo = null) => {
          const style = getComputedStyle(document.querySelector(selector), pseudo);
          return [style.backgroundColor, style.color, style.outlineStyle, style.outlineColor,
            style.borderTopStyle, style.borderTopColor].join('|');
        };
        const marks = {
          row: [look('#line2 td'), look('#line1 td')],
          tab: [look('.maintabs a.selected'), look('.maintabs a:not(.selected)')],
        };
        // Classic leaves the native inputs visible, so the browser draws their state.
        if (getComputedStyle(document.querySelector('#on')).display === 'none') {
          marks.checkbox = [look('#on + .checkboxSlider'), look('#off + .checkboxSlider')];
          marks.radio = [look('#r1 + .radioSlider'), look('#r2 + .radioSlider')];
        }
        return marks;
      });
      for (const [name, [selected, plain]] of Object.entries(marks)) {
        expect(selected, `${name} should differ from its unselected peer`).not.toBe(plain);
      }
    });

    test('print shows the whole page without navigation chrome', async ({ page }) => {
      await open(page, theme);
      await page.emulateMedia({ media: 'print' });
      const layout = await page.evaluate(() => {
        const last = document.querySelector('#line200').getBoundingClientRect();
        const hidden = ['#cactiPageHead', '#breadCrumbBar', '#navigation', '.skip-link']
          .map(selector => getComputedStyle(document.querySelector(selector)).display);
        return {
          overflow: ['html', 'body', '#navigation_right'].map(s => getComputedStyle(document.querySelector(s)).overflowY),
          // scrollHeight is rounded to whole pixels
          reach: document.scrollingElement.scrollHeight + 1 >= last.bottom,
          lastBelowFold: last.bottom > window.innerHeight,
          hidden,
        };
      });
      expect(layout.overflow).toEqual(['visible', 'visible', 'visible']);
      expect(layout.lastBelowFold).toBeTruthy();
      expect(layout.reach).toBeTruthy();
      expect(layout.hidden).toEqual(['none', 'none', 'none', 'none']);

      const pdf = await page.pdf({ format: 'A4' });
      const pages = (pdf.toString('latin1').match(/\/Type\s*\/Page[^s]/g) || []).length;
      expect(pages).toBeGreaterThan(2);
    });
  });
}
