const fs = require('node:fs');
const path = require('node:path');
const { test, expect } = require('@playwright/test');

const root = path.resolve(__dirname, '../..');
const themes = ['classic', 'dark', 'modern', 'paper-plane', 'paw', 'sunrise', 'midwinter'];

for (const theme of themes) {
  test(`${theme} login heading preserves the legacy legend styling`, async ({ page }) => {
    const relative = theme === 'midwinter' ? 'css/media/core.css' : 'main.css';
    const css = fs.readFileSync(path.join(root, 'include/themes', theme, relative), 'utf8');
    // Use the real theme rules with fixed parent geometry for both old and new markup.
    await page.setContent('<!doctype html><html><head></head><body>' +
      '<div class="loginArea"><legend id="old">User Login</legend></div>' +
      '<div class="loginArea"><h1 class="loginHeading" id="new">User Login</h1></div></body></html>');
    await page.addStyleTag({ content: css });
    await page.addStyleTag({ content: ':root { --fs-huge:22px; --text-color-highlight-a:#abcdef; }' });
    const styles = await page.evaluate(() => ['old', 'new'].map(id => {
      const element = document.getElementById(id);
      const style = getComputedStyle(element);
      const props = ['fontSize', 'fontWeight', 'fontFamily', 'color', 'textAlign',
        'marginTop', 'marginBottom', 'paddingLeft', 'paddingRight', 'display'];
      return Object.fromEntries(props.map(prop => [prop, style[prop]]));
    }));
    expect(styles[1]).toEqual(styles[0]);
    await expect(page.getByRole('heading', { name: 'User Login', level: 1 })).toBeVisible();
  });
}
