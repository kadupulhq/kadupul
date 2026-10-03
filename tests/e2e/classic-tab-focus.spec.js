// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

test('classic tab keyboard focus survives unavailable focus-visible rules', async ({ page }) => {
  const css = fs.readFileSync(path.join(__dirname, '../../include/themes/classic/main.css'), 'utf8');
  await page.setContent('<table><tr><td id="tabs"><a class="classicTab" href="#console">Console</a></td></tr></table>');
  await page.addStyleTag({ content: css });
  // Remove rules unavailable to older engines while retaining the production
  // fallback. A selector list containing focus-visible is removed as a whole.
  await page.evaluate(() => {
    for (const sheet of document.styleSheets) {
      for (let index = sheet.cssRules.length - 1; index >= 0; index--) {
        if (sheet.cssRules[index].selectorText?.includes(':focus-visible')) sheet.deleteRule(index);
      }
    }
  });
  await page.keyboard.press('Tab');
  const tab = page.locator('#tabs a');
  await expect(tab).toBeFocused();
  const outline = await tab.evaluate(element => {
    const style = getComputedStyle(element);
    return [style.outlineStyle, style.outlineWidth, style.outlineColor];
  });
  expect(outline).toEqual(['solid', '2px', 'rgb(255, 255, 255)']);
});
