const { test, expect } = require('@playwright/test');

const fs = require('fs');
const path = require('path');

/* Read the shipped themes so the list cannot name one that is not there */
const themeRoot = path.resolve(__dirname, '../../include/themes');
const allThemes = fs.readdirSync(themeRoot, { withFileTypes: true })
  .filter((entry) => entry.isDirectory())
  .map((entry) => entry.name)
  .sort();

if (allThemes.length === 0) {
  throw new Error('No shipped themes found');
}

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

test.describe('theme icon glyphs', () => {
  const glyphContent = async (page, classes) => {
    await page.goto('/tests/e2e/theme-smoke.html?theme=paper-plane');
    await page.addStyleTag({ url: '/include/fa/css/all.css' });

    return page.evaluate((iconClasses) => {
      const icon = document.createElement('i');
      icon.className = iconClasses;
      document.body.appendChild(icon);

      return getComputedStyle(icon, '::before').content;
    }, classes);
  };

  test('the paper-plane scroll icon far fa-arrow-alt-circle-up has a glyph', async ({ page }) => {
    const content = await glyphContent(page, 'far fa-arrow-alt-circle-up');

    expect(content).not.toBe('none');
    expect(content).not.toBe('normal');
  });

  test('the Font Awesome 4 name fa fa-arrow-circle-o-up has no glyph in the shipped bundle', async ({ page }) => {
    const content = await glyphContent(page, 'fa fa-arrow-circle-o-up');

    expect(['none', 'normal']).toContain(content);
  });
});
