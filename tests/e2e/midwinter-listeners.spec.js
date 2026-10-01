// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later
const fs = require('node:fs');
const path = require('node:path');
const { test, expect } = require('@playwright/test');
require('./browser-coverage').collectThemeCoverage(test);

const root = path.resolve(__dirname, '../..');
// The page header defines these labels from lib/html.php.
const labels = [...fs.readFileSync(path.join(root, 'lib/html.php'), 'utf8').matchAll(/var (\w+)='<\?php print __esc/g)]
  .map(match => match[1]).concat(['cactiVersion', 'zoom_i18n_settings']);
// layout.js loads before the theme and supplies the icon helpers, and the page
// header prints the registry map they read.
const layout = fs.readFileSync(path.join(root, 'include/layout.js'), 'utf8');
const iconHelpers = ['iconClass', 'iconSelector', 'iconMarkup', 'basename', 'setNavigationScroll'].map(name => {
  const start = layout.indexOf(`function ${name}(`);
  const end = layout.indexOf('\n}\n', start);
  if (start < 0 || end <= start) throw new Error(`Missing native layout function: ${name}`);
  return layout.slice(start, end + 2);
}).join('\n');
const registry = JSON.parse(fs.readFileSync(path.join(root, 'config/icons.json'), 'utf8'));
const icons = { ...registry.icons, ...registry.themes.midwinter };
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
  await page.addScriptTag({ content: iconHelpers });
  await page.evaluate(({ auto, names, icons }) => {
    window.kadupulIcons = icons;
    for (const name of names) {
      window[name] = name;
    }
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
  }, { auto: autoColorMode, names: labels, icons });
  await page.addScriptTag({ url: '/include/themes/midwinter/main.js' });
  await page.evaluate(stub => {
    window.productionDefaultElements = window.setupDefaultElements;
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
  await page.evaluate(() => {
    window.cactiConsoleAllowed = true;
    window.cactiGraphsAllowed = true;
    $('body').append('<div class="maintabs"><ul><li><a id="tab-console" href="#">Console</a></li></ul></div>'
      + '<div><ul class="menuoptions"><li><a href="#">Logout</a></li></ul></div>');
  });
  await navigate(page, 3);

  const bound = await page.evaluate(() => ({
    menuoptions: $._data($('.menuoptions')[0], 'events').click.length,
    content: $._data($('.cactiConsoleContentArea')[0], 'events').mouseover.length,
  }));
  expect(bound).toEqual({ menuoptions: 1, content: 1 });
});

test('auto colour mode follows the system scheme through one listener', async ({ page }) => {
  await page.emulateMedia({ colorScheme: 'light' });
  await loadTheme(page);
  await navigate(page, 3);
  expect(await page.evaluate(() => window.colourListeners)).toBe(1);
  const before = await page.evaluate(() => window.graphRefreshes);

  await page.emulateMedia({ colorScheme: 'dark' });
  await expect(page.locator('html')).toHaveAttribute('data-theme-color', 'dark');
  expect(await page.evaluate(() => window.graphRefreshes)).toBe(before + 1);
  expect(await page.evaluate(() => $.cookie('CactiColorMode'))).toBe('dark');
});

test('a system scheme change leaves a manual colour mode alone', async ({ page }) => {
  await page.emulateMedia({ colorScheme: 'light' });
  await loadTheme(page);
  await navigate(page, 1);
  await page.evaluate(() => {
    Storages.localStorage.set('midWinter_Color_Mode', 'light');
    toggleColorModeAuto();
  });
  await expect(page.locator('html')).toHaveAttribute('data-theme-color', 'light');
  const before = await page.evaluate(() => window.graphRefreshes);

  // Registered after the theme's listener, so it resolves only once the
  // theme has seen the change; asserting earlier would pass without the fix.
  await page.evaluate(() => {
    window.schemeChanged = new Promise(resolve => {
      matchMedia('(prefers-color-scheme: dark)').addEventListener('change', resolve, { once: true });
    });
  });
  await page.emulateMedia({ colorScheme: 'dark' });
  await page.evaluate(() => window.schemeChanged);
  await expect(page.locator('html')).toHaveAttribute('data-theme-color', 'light');
  expect(await page.evaluate(() => window.graphRefreshes)).toBe(before);
  expect(await page.evaluate(() => $.cookie('CactiColorMode'))).toBe('light');
});

test('ESC outside fullscreen and the retired c+F1 shortcut raise no error or alert', async ({ page }) => {
  const errors = [];
  const dialogs = [];
  page.on('pageerror', error => errors.push(error.message));
  page.on('dialog', dialog => { dialogs.push(dialog.message()); dialog.dismiss(); });
  await loadTheme(page);
  await navigate(page, 1);

  await page.keyboard.press('Escape');
  await page.keyboard.down('c');
  await page.keyboard.press('F1');
  await page.keyboard.up('c');
  await page.evaluate(() => new Promise(resolve => setTimeout(resolve, 50)));
  expect(dialogs).toEqual([]);
  expect(errors).toEqual([]);
  expect(await page.evaluate(() => window.loads)).toEqual([]);
});

test('SHIFT+k enters fullscreen on the content area and leaves it again', async ({ page }) => {
  await loadTheme(page);
  await navigate(page, 1);

  await page.keyboard.press('Shift+K');
  await page.waitForFunction(() => document.fullscreenElement !== null);
  expect(await page.evaluate(() => document.fullscreenElement.id)).toBe('navigation_right');

  await page.keyboard.press('Shift+K');
  await page.waitForFunction(() => document.fullscreenElement === null);
});


test('repeated native page setup keeps one search icon per input', async ({ page }) => {
  await loadTheme(page);
  await page.addScriptTag({ path: path.join(root, 'include/js/jquery-ui.js') });
  await page.evaluate(() => {
    window.cactiConsoleAllowed = true;
    // No colour dropdown exists in this fixture; that external widget is outside the icon contract.
    $.fn.dropcolor = function() { return this; };
    $('body').append('<table><tr><td><input id="filter"></td><td><input id="filterd"></td><td><input id="rfilter"></td></tr></table>');
    for (let count = 0; count < 3; count++) productionDefaultElements();
  });
  for (const id of ['filter', 'filterd', 'rfilter']) {
    await expect(page.locator(`#${id} + i.filter`)).toHaveCount(1);
    await expect(page.locator(`#${id}`).locator('..').locator('i.filter')).toHaveCount(1);
  }
});
