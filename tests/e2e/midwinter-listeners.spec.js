// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later
const fs = require('node:fs');
const path = require('node:path');
const { test, expect } = require('@playwright/test');

const root = path.resolve(__dirname, '../..');
const markup = `
  <div id="menu"><input type="text" name="keyword">
    <ul role="menu"><li><a role="menuitem" href="#">Devices</a></li><li><a role="menuitem" href="#">Graphs</a></li></ul>
  </div>
  <div id="navigation_right" class="cactiConsoleContentArea" style="height: 200px"></div>`;

// Loads the real theme script and stubs only the page-building steps that
// need the full Kadupul markup; applySkin() calls themeReady() the same way.
async function loadTheme(page, { autoColorMode = 'on', stubPageSetup = true } = {}) {
  await page.goto('/tests/e2e/theme-smoke.html');
  await page.setContent(markup);
  for (const file of ['include/js/jquery.js', 'include/js/js.storage.js', 'include/js/jquery.cookie.js', 'include/js/purify.js']) {
    await page.addScriptTag({ path: path.join(root, file) });
  }
  await page.evaluate(auto => {
    Storages.localStorage.set('midWinter_Color_Mode_Auto', auto);
    window.urlPath = '/';
    window.loads = [];
    window.graphRefreshes = 0;
    window.colourListeners = 0;
    window.loadPage = url => window.loads.push(url);
    window.initializeGraphs = () => window.graphRefreshes++;
    window.ajaxAnchors = () => {};
    const add = MediaQueryList.prototype.addEventListener;
    MediaQueryList.prototype.addEventListener = function (type, ...rest) {
      if (type === 'change') window.colourListeners++;
      return add.call(this, type, ...rest);
    };
  }, autoColorMode);
  await page.addScriptTag({ path: path.join(root, 'include/themes/midwinter/main.js') });
  await page.evaluate(stub => {
    const steps = ['setupTree', 'setupDefaultElements', 'setMenuVisibility', 'updateNavigation', 'checkConsoleMenu'];
    for (const name of stub ? [...steps, 'setupTheme'] : steps) {
      window[name] = () => {};
    }
  }, stubPageSetup);
}

async function navigate(page, times) {
  for (let i = 0; i < times; i++) {
    await page.evaluate(() => themeReady());
  }
  // jQuery lowers $.active only after the vendor scripts' done() callbacks ran.
  await page.waitForFunction(() => $.active === 0 && typeof hotkeys === 'function' && typeof $.fn.markRegExp === 'function');
}

test('three page loads leave one handler per shortcut, menu link and keyword box', async ({ page }) => {
  await loadTheme(page);
  await navigate(page, 3);

  await page.keyboard.down('c');
  await page.keyboard.press('t');
  await page.keyboard.up('c');
  expect(await page.evaluate(() => window.loads)).toEqual(['/graph_view.php?action=tree']);

  const bound = await page.evaluate(() => ({
    keyword: $._data($("input[name='keyword']")[0], 'events').input.length,
    menuitem: $._data($('a[role="menuitem"]')[0], 'events').click.length,
  }));
  expect(bound).toEqual({ keyword: 1, menuitem: 1 });

  await page.locator("input[name='keyword']").fill('dev');
  await expect(page.locator('ul[role="menu"] mark')).toHaveText(['Devices']);
});

test('a double-click on the page does not enter fullscreen', async ({ page }) => {
  await loadTheme(page);
  await navigate(page, 2);

  await page.locator('#navigation_right').dblclick();
  expect(await page.evaluate(() => document.fullscreenElement)).toBeNull();
});

test('a failed hotkeys load is retried on the next page load', async ({ page }) => {
  await loadTheme(page);
  await page.route('**/vendor/hotkeys/hotkeys.js', route => route.fulfill({ status: 404 }));
  await page.evaluate(() => setHotKeys());
  await page.waitForFunction(() => $.active === 0);
  await page.keyboard.press('Shift+C');
  expect(await page.evaluate(() => window.loads)).toEqual([]);

  await page.unroute('**/vendor/hotkeys/hotkeys.js');
  await navigate(page, 1);
  await page.keyboard.press('Shift+C');
  expect(await page.evaluate(() => window.loads)).toEqual(['/index.php']);
});

test('the user menu and content area keep one handler each across page loads', async ({ page }) => {
  await loadTheme(page, { stubPageSetup: false });
  // The page header defines these labels from lib/html.php.
  const labels = [...fs.readFileSync(path.join(root, 'lib/html.php'), 'utf8').matchAll(/var (\w+)='<\?php print __esc/g)].map(match => match[1]);
  await page.evaluate(names => {
    for (const name of [...names, 'cactiVersion', 'zoom_i18n_settings']) {
      window[name] = name;
    }
    window.cactiConsoleAllowed = true;
    window.cactiGraphsAllowed = true;
    $('body').append('<div class="maintabs"><ul><li><a id="tab-console" href="#">Console</a></li></ul></div>'
      + '<div><ul class="menuoptions"><li><a href="#">Logout</a></li></ul></div>');
  }, labels);
  await navigate(page, 3);

  const bound = await page.evaluate(() => ({
    menuoptions: $._data($('.menuoptions')[0], 'events').click.length,
    content: $._data($('.cactiConsoleContentArea')[0], 'events').mouseover.length,
  }));
  expect(bound).toEqual({ menuoptions: 1, content: 1 });
});
