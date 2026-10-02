const { test, expect } = require('@playwright/test');

const fs = require('fs');
const path = require('path');
const themeRoot = path.resolve(__dirname, '../../include/themes');
const allThemes = fs.readdirSync(themeRoot, { withFileTypes: true })
  .filter((entry) => entry.isDirectory())
  .map((entry) => entry.name)
  .sort();
if (allThemes.length === 0) throw new Error('No shipped themes found');

test.describe('theme jquery ui css alignment', () => {
  test('all themes serve 1.14.x jquery ui bundles', async ({ request }) => {
    for (const theme of allThemes) {
      const response = await request.get(`/include/themes/${theme}/jquery-ui.css`);
      expect(response.ok(), `${theme} css request should succeed`).toBeTruthy();

      const css = await response.text();
      expect(css, `${theme} should advertise jquery ui 1.14.x`).toContain('jQuery UI - v1.14.');
      expect(css, `${theme} should not advertise jquery ui 1.12.1`).not.toContain('jQuery UI - v1.12.1');
    }
  });

});

test.describe('theme jquery ui browser smoke', () => {
  for (const theme of allThemes) {
    test(`widgets initialize and render for ${theme}`, async ({ page }) => {
      await page.goto(`/tests/e2e/theme-smoke.html?theme=${theme}`);
      await page.waitForFunction(() => window.__themeSmokeReady === true || window.__themeSmokeError);

      const smokeError = await page.evaluate(() => window.__themeSmokeError || null);
      expect(smokeError).toBeNull();

      await expect(page.locator('#theme-select-button')).toBeVisible();
      await expect(page.locator('#open-dialog')).toHaveClass(/ui-button/);
      await expect(page.locator('#group')).toHaveClass(/ui-controlgroup|ui-buttonset/);

      await page.getByRole('button', { name: 'Open Dialog' }).click();
      await expect(page.locator('.ui-dialog')).toBeVisible();
      await expect(page.locator('.ui-widget-overlay')).toBeVisible();

      const screenshot = await page.locator('#sandbox').screenshot();
      expect(screenshot.byteLength, `${theme} sandbox screenshot should not be empty`).toBeGreaterThan(1000);


    });
  }
});

test.describe('theme about link logo', () => {
  for (const theme of allThemes) {
    test(`logo fits inside .cactiLogo for ${theme}`, async ({ page }) => {
      await page.goto(`/tests/e2e/theme-smoke.html?theme=${theme}`);

      const logo = await page.evaluate(async (name) => {
        const link = document.createElement('link');
        link.rel = 'stylesheet';
        link.href = `/include/themes/${name}/main.css`;
        await new Promise((resolve, reject) => {
          link.onload = resolve;
          link.onerror = reject;
          document.head.appendChild(link);
        });

        const anchor = document.createElement('a');
        anchor.className = 'cactiLogo pic';
        document.body.appendChild(anchor);

        const style = getComputedStyle(anchor);
        if (style.display === 'none') {
          return { hidden: true };
        }

        const image = new Image();
        image.src = style.backgroundImage.replace(/^url\("?(.*?)"?\)$/, '$1');
        await image.decode();

        return {
          hidden: false,
          boxWidth: anchor.clientWidth,
          boxHeight: anchor.clientHeight,
          size: style.backgroundSize,
          x: style.backgroundPositionX,
          y: style.backgroundPositionY,
          imageWidth: image.naturalWidth,
          imageHeight: image.naturalHeight,
        };
      }, theme);

      test.skip(logo.hidden, `${theme} does not show the about link logo`);

      expect(logo.size, `${theme} logo should scale with contain`).toBe('contain');

      const scale = Math.min(logo.boxWidth / logo.imageWidth, logo.boxHeight / logo.imageHeight);
      const drawnWidth = logo.imageWidth * scale;
      const drawnHeight = logo.imageHeight * scale;

      // A percentage offset is relative to the space left over, a length is absolute.
      const offset = (value, box, drawn) => (value.endsWith('%')
        ? (box - drawn) * parseFloat(value) / 100
        : parseFloat(value));
      const left = offset(logo.x, logo.boxWidth, drawnWidth);
      const top = offset(logo.y, logo.boxHeight, drawnHeight);

      expect(left, `${theme} logo starts inside the box`).toBeGreaterThanOrEqual(0);
      expect(top, `${theme} logo starts inside the box`).toBeGreaterThanOrEqual(0);
      expect(left + drawnWidth, `${theme} logo is clipped on the right`).toBeLessThanOrEqual(logo.boxWidth + 0.5);
      expect(top + drawnHeight, `${theme} logo is clipped at the bottom`).toBeLessThanOrEqual(logo.boxHeight + 0.5);
    });
  }
});
