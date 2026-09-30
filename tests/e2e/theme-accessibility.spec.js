// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

const { test, expect } = require('@playwright/test');

const fs = require('fs');
const path = require('path');
const themeRoot = path.resolve(__dirname, '../../include/themes');
const allThemes = fs.readdirSync(themeRoot, { withFileTypes: true })
  .filter((entry) => entry.isDirectory())
  .map((entry) => entry.name)
  .sort();
if (allThemes.length === 0) throw new Error('No shipped themes found');

// classic and paw never suppressed outlines and keep the browser's own ring.
const ringThemes = ['dark', 'midwinter', 'modern', 'paper-plane', 'sunrise'];

async function openTheme(page, theme, color) {
  const query = color ? `theme=${theme}&color=${color}` : `theme=${theme}`;
  await page.goto(`/tests/e2e/theme-accessibility.html?${query}`);
  await page.waitForFunction(() => window.__themeReady === true || window.__themeError);
  expect(await page.evaluate(() => window.__themeError || null)).toBeNull();
}

// Tabbing, rather than calling focus(), proves the element is in the tab order
// and makes the browser apply :focus-visible the way it does for a real user.
async function tabTo(page, id) {
  for (let i = 0; i < 40; i++) {
    await page.keyboard.press('Tab');
    if (await page.evaluate(() => document.activeElement && document.activeElement.id) === id) {
      return;
    }
  }
  throw new Error(`#${id} was not reached by Tab`);
}

function outline(page, selector, pseudo = null) {
  return page.evaluate(([sel, pse]) => {
    const style = getComputedStyle(document.querySelector(sel), pse);
    return { style: style.outlineStyle, width: parseFloat(style.outlineWidth), color: style.outlineColor };
  }, [selector, pseudo]);
}

// WCAG 2.x contrast of a computed colour against the first opaque background
// behind it. An outline is drawn outside the box, so it is measured against the
// parent's background rather than the element's own.
async function contrastAgainstBackground(page, selector, colorOf) {
  return page.evaluate(([sel, prop]) => {
    const parse = (value) => {
      const m = value.match(/rgba?\(([^)]+)\)/);
      const parts = m[1].split(/[ ,/]+/).filter(Boolean).map(Number);
      return { r: parts[0], g: parts[1], b: parts[2], a: parts.length > 3 ? parts[3] : 1 };
    };
    const over = (top, bottom) => ({
      r: top.r * top.a + bottom.r * (1 - top.a),
      g: top.g * top.a + bottom.g * (1 - top.a),
      b: top.b * top.a + bottom.b * (1 - top.a),
      a: 1,
    });
    const background = (element) => {
      const layers = [];
      for (let node = element; node; node = node.parentElement) {
        const bg = parse(getComputedStyle(node).backgroundColor);
        if (bg.a > 0) layers.push(bg);
        if (bg.a === 1) break;
      }
      return layers.reverse().reduce((acc, layer) => over(layer, acc), { r: 255, g: 255, b: 255, a: 1 });
    };
    const luminance = ({ r, g, b }) => {
      const channel = (v) => {
        const c = v / 255;
        return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4;
      };
      return 0.2126 * channel(r) + 0.7152 * channel(g) + 0.0722 * channel(b);
    };
    const element = document.querySelector(sel);
    const bg = background(prop === 'outlineColor' ? element.parentElement : element);
    const fg = over(parse(getComputedStyle(element)[prop]), bg);
    const [hi, lo] = [luminance(fg), luminance(bg)].sort((a, b) => b - a);
    return (hi + 0.05) / (lo + 0.05);
  }, [selector, colorOf]);
}

test.describe('theme keyboard focus', () => {
  const targets = ['plainLink', 'textInput', 'mainTab', 'navLink', 'pageTab', 'saveButton', 'bottomLink'];

  for (const theme of allThemes) {
    test(`${theme} shows a focus ring for keyboard users`, async ({ page }) => {
      await openTheme(page, theme);

      for (const id of targets) {
        // midwinter hides the page footer altogether.
        if (await page.evaluate((target) => document.getElementById(target).getClientRects().length === 0, id)) {
          continue;
        }
        await tabTo(page, id);
        const ring = await outline(page, `#${id}`);
        expect(ring.style, `${theme} #${id} outline`).not.toBe('none');
        expect(ring.width, `${theme} #${id} outline width`).toBeGreaterThan(0);

        if (ringThemes.includes(theme)) {
          expect(ring.width, `${theme} #${id} outline width`).toBeGreaterThanOrEqual(2);
          const ratio = await contrastAgainstBackground(page, `#${id}`, 'outlineColor');
          expect(ratio, `${theme} #${id} ring contrast`).toBeGreaterThanOrEqual(3);
        }
      }
    });

    test(`${theme} shows no focus ring after a mouse click`, async ({ page }) => {
      await openTheme(page, theme);

      await page.click('#plainLink');
      expect(await page.evaluate(() => document.activeElement.id)).toBe('plainLink');
      expect((await outline(page, '#plainLink')).style).toBe('none');
    });
  }

  test('midwinter draws the ring on the replacement checkbox and radio label', async ({ page }) => {
    for (const color of [null, 'light']) {
      await openTheme(page, 'midwinter', color);

      await tabTo(page, 'rowCheck');
      const box = await outline(page, 'label[for="rowCheck"]', '::before');
      expect(box.style).toBe('solid');
      expect(box.width).toBeGreaterThanOrEqual(2);

      await page.click('#plainLink');
      expect((await outline(page, 'label[for="rowCheck"]', '::before')).style).toBe('none');
    }
  });
});

test.describe('theme switch controls', () => {
  // classic leaves the native checkbox and radio visible.
  const switchThemes = allThemes.filter((theme) => theme !== 'classic');

  for (const theme of allThemes) {
    test(`${theme} switch checkbox and radio work from the keyboard`, async ({ page }) => {
      await openTheme(page, theme);

      await tabTo(page, 'switchBox');
      await page.keyboard.press('Space');
      expect(await page.isChecked('#switchBox')).toBe(true);
      await page.keyboard.press('Space');
      expect(await page.isChecked('#switchBox')).toBe(false);

      await tabTo(page, 'switchRadio_1');
      await page.keyboard.press('ArrowDown');
      expect(await page.isChecked('#switchRadio_2')).toBe(true);

      if (switchThemes.includes(theme)) {
        const ring = await outline(page, 'label.radioSwitch:has(#switchRadio_2) .radioSlider');
        expect(ring.style, `${theme} radio slider ring`).toBe('solid');
        expect(ring.width).toBeGreaterThanOrEqual(2);
      }
    });
  }

  for (const theme of switchThemes) {
    test(`${theme} switch looks and clicks the same for mouse users`, async ({ page }) => {
      await openTheme(page, theme);

      await tabTo(page, 'switchBox');
      const ring = await outline(page, '#switchBox + .checkboxSlider');
      expect(ring.style, `${theme} checkbox slider ring`).toBe('solid');
      expect(ring.width).toBeGreaterThanOrEqual(2);

      const box = await page.locator('#switchBox').boundingBox();
      expect(box.width).toBeLessThanOrEqual(1);
      expect(box.height).toBeLessThanOrEqual(1);
      expect(await page.locator('#switchBox').evaluate((el) => getComputedStyle(el).opacity)).toBe('0');

      await page.click('#switchBox + .checkboxSlider');
      expect(await page.isChecked('#switchBox')).toBe(true);
      expect((await outline(page, '#switchBox + .checkboxSlider')).style).toBe('none');
    });
  }

  test('sunrise keeps plain checkboxes reachable and rings the drawn box', async ({ page }) => {
    await openTheme(page, 'sunrise');

    await tabTo(page, 'rowCheck');
    const ring = await outline(page, 'label[for="rowCheck"]', '::before');
    expect(ring.style).toBe('solid');
    expect(ring.width).toBeGreaterThanOrEqual(2);

    await page.keyboard.press('Space');
    expect(await page.isChecked('#rowCheck')).toBe(true);
  });
});

test.describe('dark graph utility icons', () => {
  const wrapperSize = (page) => page.locator('#dd1 .iconWrapper').evaluate((el) => {
    const rect = el.getBoundingClientRect();
    return { width: rect.width, height: rect.height, opacity: getComputedStyle(el).opacity };
  });

  test('stay hidden until the graph cell is hovered or focused', async ({ page }) => {
    await openTheme(page, 'dark');

    // The fixture adds the stylesheet after the markup, so let the fade settle.
    await expect.poll(async () => (await wrapperSize(page)).opacity).toBe('0');
    expect((await wrapperSize(page)).width).toBeLessThanOrEqual(1);

    await page.locator('#dd1').evaluate((el) => el.classList.add('iconsShown'));
    const hovered = await wrapperSize(page);
    expect(hovered.width).toBeGreaterThan(1);
    expect(hovered.height).toBeGreaterThan(1);

    await page.locator('#dd1').evaluate((el) => el.classList.remove('iconsShown'));
    expect((await wrapperSize(page)).width).toBeLessThanOrEqual(1);
  });

  test('are reachable by Tab and shown while focus is inside', async ({ page }) => {
    await openTheme(page, 'dark');

    await tabTo(page, 'graph_1_util');
    const focused = await wrapperSize(page);
    expect(focused.width).toBeGreaterThan(1);
    await expect(page.locator('#graph_1_util')).toBeVisible();

    await tabTo(page, 'graph_1_csv');
    expect((await wrapperSize(page)).width).toBeGreaterThan(1);

    await tabTo(page, 'bottomLink');
    expect((await wrapperSize(page)).width).toBeLessThanOrEqual(1);
  });

  test('close after a mouse click once the pointer leaves', async ({ page }) => {
    await openTheme(page, 'dark');

    // Browsers focus a clicked href="#" link, so a :focus-within reveal would
    // keep the column open after main.js drops the hover class.
    await page.locator('#dd1').evaluate((el) => el.classList.add('iconsShown'));
    await page.click('#graph_1_csv');
    expect(await page.evaluate(() => document.activeElement.id)).toBe('graph_1_csv');

    await page.locator('#dd1').evaluate((el) => el.classList.remove('iconsShown'));
    expect((await wrapperSize(page)).width).toBeLessThanOrEqual(1);
  });
});
