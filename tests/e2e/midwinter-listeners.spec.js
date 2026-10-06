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

// Full shipped scripts and UAParser remain intact. Only the installed-page
// ready callback is isolated while this fixture supplies controller markup.
async function loadNativeNavigation(page) {
  await page.goto('/tests/e2e/theme-smoke.html');
  await page.waitForFunction(() => window.__themeSmokeReady);
  for (const file of ['include/js/js.storage.js', 'include/js/jquery.cookie.js', 'include/js/purify.js', 'include/js/jquery.tablesorter.js']) {
    await page.addScriptTag({ url: '/' + file });
  }
  await page.evaluate(({ names, icons }) => {
    for (const name of names) window[name] = name;
    Object.assign(window, { kadupulIcons: icons, urlPath: '/', cactiConsoleAllowed: true, cactiGraphsAllowed: true });
    Storages.localStorage.set('midWinter_GUI_Mode', 'compact');
    Storages.localStorage.set('midWinter_Color_Mode', 'dark');
    Storages.localStorage.set('midWinter_Color_Mode_Auto', 'off');
    Storages.localStorage.set('midWinter_Font_Size', 'regular');
    window.savedReady = $.fn.ready; $.fn.ready = function () { return this; };
  }, { names: labels, icons });
  await page.addScriptTag({ url: '/include/layout.js' });
  await page.evaluate(() => { $.fn.ready = window.savedReady; });
  await page.setContent('<div class="maintabs"><nav><ul><li><a class="lefttab" id="tab-console" href="/index.php">Console</a></li>'
    + '<li><a class="lefttab" id="tab-graphs" href="/graph_view.php">Graphs</a></li><li><a class="lefttab" id="tab-plugin" href="/plugins/fixture/">Plugin</a></li></ul></nav></div>'
    + '<span class="text_tab-plugin">Plugin</span><span class="loggedInAs">Operator</span><div class="menuoptions"><ul><li>Profile</li><li>Help</li><li>Theme</li></ul></div>'
    + '<div id="cactiContent" class="cactiContent"><div id="navigation" class="cactiConsoleNavigationArea"><div id="menu"><ul><li id="menu_main_console">Console</li><li><a href="/host.php">Devices</a></li></ul></div></div>'
    + '<div id="navigation_right" class="cactiConsoleContentArea"></div></div>');
  await page.addStyleTag({ url: '/include/fa/css/all.css' });
  await page.addScriptTag({ url: '/include/themes/midwinter/main.js' });
  await page.evaluate(() => { setupTheme(); setupTheme(); });
}

test('native compact navigation preserves menu glyphs and one dialog binding', async ({ page }) => {
  await loadNativeNavigation(page);
  for (const helper of ['dashboards', 'settings', 'help', 'user']) await expect(page.locator(`.compact_nav_icon[data-helper="${helper}"]`)).toHaveCount(1);
  await expect(page.locator('#menu_tab_miscellaneous')).toHaveCount(1);
  await expect(page.locator('#compact_tab_menu a[href="/plugins/fixture/"]')).toHaveCount(1);
  await expect(page.locator('#compact_user_menu .mdw_logout')).toHaveCount(1);
  for (const name of ['nav-about', 'nav-bug', 'nav-keyboard', 'nav-contribute', 'nav-profile', 'nav-theme', 'nav-client']) {
    const classes = icons[name].split(' ').map(c => '.' + c).join('');
    await expect(page.locator('#compact_user_menu i' + classes)).toHaveCount(1);
  }
  await page.locator('.compact_nav_icon[data-helper="help"]').click();
  await expect(page.locator('.cactiConsoleNavigationUserBox[data-helper="help"]')).not.toHaveClass(/hide/);
  expect(await page.evaluate(() => $._data(document.querySelector('#compact_user_menu .dialog_client'), 'events').click.length)).toBe(1);
});

const clientProfiles = [
  ['chrome windows', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36', 'Chrome', 'Windows', 'device-desktop'],
  ['internet explorer', 'Mozilla/5.0 (Windows NT 6.1; Trident/7.0; rv:11.0) like Gecko', 'IE', 'Windows', 'device-desktop'],
  ['edge windows', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36 Edg/120.0.0.0', 'Edge', 'Windows', 'device-desktop'],
  ['firefox ubuntu', 'Mozilla/5.0 (X11; Ubuntu; Linux x86_64; rv:120.0) Gecko/20100101 Firefox/120.0', 'Firefox', 'Ubuntu', 'device-desktop'],
  ['opera linux', 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36 OPR/106.0.0.0', 'Opera', 'Linux', 'device-desktop'],
  ['safari mac', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Safari/605.1.15', 'Safari', 'Mac OS', 'device-desktop'],
  ['chrome os', 'Mozilla/5.0 (X11; CrOS x86_64 15183.78.0) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36', 'Chrome', 'Chromium OS', 'device-desktop'],
  ['raspberry pi', 'Mozilla/5.0 (X11; Raspbian; Linux armv7l) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36', 'Chrome', 'Raspbian', 'device-desktop'],
  ['blackberry mobile', 'Mozilla/5.0 (BB10; Touch) AppleWebKit/537.35+ (KHTML, like Gecko) Version/10.3.0.0 Mobile Safari/537.35+', 'Safari', 'BlackBerry', 'device-mobile'],
  ['android mobile', 'Mozilla/5.0 (Linux; Android 13; Pixel 7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Mobile Safari/537.36', 'Chrome', 'Android', 'device-mobile'],
  ['ipad tablet', 'Mozilla/5.0 (iPad; CPU OS 16_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.0 Mobile/15E148 Safari/604.1', 'Mobile Safari', 'iOS', 'device-tablet'],
  ['playstation console', 'Mozilla/5.0 (PlayStation 4 3.11) AppleWebKit/537.73 (KHTML, like Gecko)', 'WebKit', 'PlayStation', 'device-console'],
  ['smart television', 'Mozilla/5.0 (SMART-TV; Linux; Tizen 6.0) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/2.2 TV Safari/537.36', 'Samsung Internet', 'Tizen', 'device-tv'],
  ['tesla embedded', 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/89.0.4389.128 Safari/537.36 Tesla/2023.38', 'Tesla', 'Linux', 'device-embedded'],
  ['unknown client', 'OwnedClient/1.0 (X11; Linux)', 'undefined', 'Linux', 'device-desktop'],
];

for (const [name, userAgent, browser, os, device] of clientProfiles) {
  test.describe(name, () => {
    test.use({ userAgent });
    test(`${name}: native client dialog displays parsed environment and glyphs`, async ({ page }) => {
      await loadNativeNavigation(page);
      await page.locator('.compact_nav_icon[data-helper="user"]').click();
      await page.locator('#compact_user_menu .cactiConsoleNavigationUserBox[data-helper="user"] .dialog_client').click();
      await expect(page.locator('#dialog_container')).toBeVisible();
      const boxes = page.locator('#dialog_container .cactiFlexBoxContentBox');
      await expect(boxes).toHaveCount(4);
      await expect(boxes.nth(0).locator('.footer span').first()).toHaveText(browser);
      await expect(boxes.nth(1).locator('.footer span').first()).toHaveText(os);
      const classes = icons[device].split(' ').map(c => '.' + c).join('');
      await expect(boxes.nth(2).locator('i' + classes)).toHaveCount(1);
      await expect(boxes.nth(3).locator('i')).toHaveClass(icons.network);
      for (const glyph of await boxes.locator('.content i').all()) {
        expect(await glyph.evaluate(node => getComputedStyle(node, '::before').content)).not.toMatch(/^(none|normal|"")$/);
      }
      await page.locator('.ui-dialog-titlebar-close').click();
      await expect(page.locator('#dialog_container')).toBeHidden();
    });
  });
}

// Loads the real theme script and stubs only the page-building steps that
// need the full Kadupul markup; applySkin() calls themeReady() the same way.
async function loadTheme(page, { autoColorMode = 'on', stubPageSetup = true, realDefaultElements = false } = {}) {
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
  if (realDefaultElements) {
    for (const file of ['include/js/jquery-ui.js', 'include/js/jquery.tablesorter.js']) {
      await page.addScriptTag({ url: '/' + file });
    }
    // Preserve the full production helpers; isolate only installed-page ready
    // initialization because this case supplies its own controller markup.
    await page.evaluate(() => { window.savedReady = $.fn.ready; $.fn.ready = function () { return this; }; });
    await page.addScriptTag({ url: '/include/layout.js' });
    await page.evaluate(() => { $.fn.ready = window.savedReady; });
  }
  await page.evaluate(({ stub, realDefaultElements }) => {
    window.productionDefaultElements = window.setupDefaultElements;
    const steps = ['setupTree', 'setMenuVisibility', 'updateNavigation', 'checkConsoleMenu'];
    if (!realDefaultElements) steps.push('setupDefaultElements');
    for (const name of stub ? [...steps, 'setupTheme'] : steps) {
      window[name] = () => {};
    }
  }, { stub: stubPageSetup, realDefaultElements });
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

test('the relocated filter keeps its production sliders glyph and controls', async ({ page }) => {
  const errors = [];
  page.on('pageerror', error => errors.push(error.message));
  await loadTheme(page, { stubPageSetup: false, realDefaultElements: true });
  await page.evaluate(() => {
    window.cactiConsoleAllowed = true;
    window.cactiGraphsAllowed = true;
    $('body').append('<div class="maintabs"><ul><li><a id="tab-console" href="#">Console</a></li></ul></div>'
      + '<div><ul class="menuoptions"><li><a href="#">Logout</a></li></ul></div>');
    $('#navigation_right').append('<div class="cactiTable"><div class="cactiTableTitle">Device filters</div>'
      + '<table class="filterTable"><tr><td><label for="native-filter">Search devices</label>'
      + '<input id="native-filter" value="router"><input id="host" value="Any"></td></tr></table></div>');
  });
  await navigate(page, 1);
  await expect(page.locator('#filterTableOnTop .cactiTableFilter i.fas.fa-sliders')).toHaveCount(1);
  await expect(page.locator('#filterTableOnTop .cactiTableTitle')).toHaveText('Device filters');
  await expect(page.locator('#filterTableOnTop #native-filter')).toHaveValue('router');
  await expect(page.locator('#filterTableOnTop label')).toHaveAttribute('for', 'native-filter');
  await expect(page.locator('i.fa-sliders-h')).toHaveCount(0);
  expect(errors).toEqual([]);
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
