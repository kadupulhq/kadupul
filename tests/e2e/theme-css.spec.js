const { test, expect } = require('@playwright/test');

const fs = require('fs');
const path = require('path');
const themeRoot = path.resolve(__dirname, '../../include/themes');
const allThemes = fs.readdirSync(themeRoot, { withFileTypes: true })
  .filter((entry) => entry.isDirectory())
  .map((entry) => entry.name)
  .sort();
if (allThemes.length === 0) throw new Error('No shipped themes found');

test.describe('theme duplicate declarations preserve the shipped cascade', () => {
  for (const theme of ['classic', 'modern', 'paw', 'midwinter']) {
    test(`graph grouping and alignment retain their styles for ${theme}`, async ({ page }, info) => {
      await page.setContent('<!doctype html><html><body></body></html>');
      await page.addStyleTag({ url: new URL(`/include/themes/${theme}/main.css`, info.project.use.baseURL).href });
      const colors = await page.evaluate(() => {
        const classes = ['graphItemGr1Cust', 'graphItemGr1Cust graphItemGr1Alt1',
          'graphItemGr1Cust graphItemGr2Alt2', 'graphItemGr1Alt1', 'graphItemGr2Alt2'];
        return classes.map(className => {
          const element = document.createElement('div');
          element.className = className;
          document.body.appendChild(element);
          return getComputedStyle(element).backgroundColor;
        });
      });
      // Characterized from the original styles, including overlapping classes.
      expect(colors).toEqual(['rgb(210, 214, 231)', 'rgb(210, 214, 231)',
        'rgb(210, 214, 231)', 'rgb(238, 238, 238)', 'rgb(231, 233, 242)']);
      if (theme !== 'midwinter') {
        const alignment = await page.evaluate(() => {
          const element = document.createElement('div');
          element.className = 'right';
          element.style.textAlign = 'center';
          document.body.appendChild(element);
          const normal = getComputedStyle(element).textAlign;
          element.style.setProperty('text-align', 'center', 'important');
          return [normal, getComputedStyle(element).textAlign];
        });
        expect(alignment).toEqual(['right', 'center']);
      }
      const source = fs.readFileSync(path.join(themeRoot, theme,
        theme === 'midwinter' ? 'css/media/core.css' : 'main.css'), 'utf8');
      expect(source.match(/^\.graphItemGr1Cust\s*\{/gm)).toHaveLength(1);
      if (theme !== 'midwinter') expect(source.match(/^\.right\s*\{/gm)).toHaveLength(1);
    });
  }
});

test.describe('jsTree state backgrounds', () => {
  for (const theme of allThemes) {
    for (const width of [1280, 390]) {
      test(`${theme} preserves selected disabled and RTL last nodes at ${width}px`, async ({ page }, testInfo) => {
        test.setTimeout(120_000);
        await page.setViewportSize({ width, height: 900 });
        await page.goto(`/tests/e2e/theme-smoke.html?theme=${theme}`);
        await page.waitForFunction(() => window.__themeSmokeReady === true);
        await page.addStyleTag({ url: `/include/themes/${theme}/default/style.css` });
        await page.addStyleTag({ url: `/include/themes/${theme}/main.css` });
        await page.addScriptTag({ url: '/include/js/jstree.js' });

        const samples = [];
        for (const size of ['normal', 'small', 'large']) {
          for (const direction of ['ltr', 'rtl']) {
            await page.evaluate(({ size, direction }) => new Promise((resolve) => {
              const oldTree = document.getElementById('css-tree');
              if (oldTree) {
                window.jQuery(oldTree).jstree('destroy');
                oldTree.remove();
              }
              const tree = document.createElement('div');
              tree.id = 'css-tree';
              tree.style.direction = direction;
              document.getElementById('sandbox').replaceChildren(tree);
              window.jQuery(tree).one('ready.jstree', () => resolve()).jstree({
                core: {
                  animation: 0,
                  themes: { name: 'default', url: false, variant: size === 'normal' ? false : size, responsive: true },
                  data: [{
                    id: 'parent', text: 'Parent', state: { opened: true }, children: [
                      { id: 'ordinary', text: 'Ordinary' },
                      { id: 'disabled', text: 'Selected disabled', state: { selected: true, disabled: true } },
                      { id: 'last', text: 'Last child' },
                    ],
                  }],
                },
              });
            }), { size, direction });

            const disabled = page.locator('#disabled_anchor');
            await expect(disabled).toHaveClass(/jstree-disabled/);
            await expect(disabled).toHaveClass(/jstree-clicked/);
            await expect(disabled).toHaveCSS('background-color', 'rgb(239, 239, 239)');
            await expect(page.locator('#last')).toHaveClass(/jstree-last/);
            await expect(page.locator('#last')).toHaveCSS('background-image', 'none');
            await expect(page.locator('#last')).toHaveCSS('background-color', 'rgba(0, 0, 0, 0)');
            const desktopHeight = { normal: theme === 'classic' ? 24 : 20, small: 18, large: 32 }[size];
            await expect(disabled).toHaveCSS('height', `${width <= 768 ? 40 : desktopHeight}px`);
            if (direction === 'rtl') await expect(page.locator('#css-tree')).toHaveClass(/jstree-rtl/);

            for (const state of ['default', 'disabled-hovered', 'ordinary-hovered', 'ordinary-selected']) {
              if (state === 'disabled-hovered') await disabled.hover();
              if (state === 'ordinary-hovered') await page.locator('#ordinary_anchor').hover();
              if (state === 'ordinary-selected') await page.locator('#ordinary_anchor').click();
              if (state === 'disabled-hovered') {
                await expect(disabled).toHaveClass(/jstree-hovered/);
                await expect(disabled).toHaveCSS('background-color', 'rgb(239, 239, 239)');
              }
              if (state === 'ordinary-hovered') await expect(page.locator('#ordinary_anchor')).toHaveClass(/jstree-hovered/);
              if (state === 'ordinary-selected') await expect(page.locator('#ordinary_anchor')).toHaveClass(/jstree-clicked/);

              // Compare settled states rather than timing-dependent intermediate box shadows.
              await page.evaluate(() => {
                for (const animation of document.getElementById('css-tree').getAnimations({ subtree: true })) {
                  if (animation instanceof CSSTransition) animation.finish();
                }
              });
              const styles = await page.evaluate(() => {
                const properties = ['background-color', 'background-image', 'background-position', 'background-size',
                  'color', 'box-shadow', 'height', 'width', 'line-height', 'margin-left', 'margin-right', 'opacity'];
                return Object.fromEntries(['ordinary', 'ordinary_anchor', 'disabled', 'disabled_anchor', 'last', 'last_anchor'].map((id) => {
                  const style = getComputedStyle(document.getElementById(id));
                  return [id, Object.fromEntries(properties.map((property) => [property, style.getPropertyValue(property)]))];
                }));
              });
              samples.push({ size, direction, state, styles });
            }
          }
        }
        await testInfo.attach('jstree-computed-styles', { body: JSON.stringify(samples, null, 2), contentType: 'application/json' });
      });
    }
  }
});

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
