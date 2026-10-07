// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later
const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
require('./browser-coverage').collectThemeCoverage(test);

// html_common_header() prints this map before layout.js loads.
const icons = JSON.parse(fs.readFileSync(path.resolve(__dirname, '../../config/icons.json'), 'utf8')).icons;

async function loadLayout(page) {
  await page.goto('/tests/e2e/theme-smoke.html');
  await page.waitForFunction(() => window.__themeSmokeReady);
  await page.addScriptTag({ url: '/include/js/jquery.tablesorter.js' });
  // Skip the application-ready bootstrap; this fixture supplies its own page.
  await page.evaluate(() => { window.savedReady = $.fn.ready; $.fn.ready = function () { return this; }; });
  await page.evaluate(map => { window.kadupulIcons = map; }, icons);
  await page.addScriptTag({ url: '/include/layout.js' });
  await page.evaluate(() => { $.fn.ready = window.savedReady; });
  await page.setContent('<div class="cactiConsoleContentArea" style="height:250px;overflow:auto"><div style="height:1800px"></div><select id="storage_location"><option value="0">Local</option><option value="1">RRDtool Proxy Server</option></select><div style="height:200px"></div></div>');
  await page.evaluate(() => { $('#storage_location').selectmenu(); setupSelectmenuScrollClose(); });
}

test('select menu remains usable after the browser scrolls its button into view', async ({ page }) => {
  await loadLayout(page);
  await page.locator('#storage_location-button').click();
  await page.getByRole('option', { name: 'RRDtool Proxy Server' }).click();
  await expect(page.locator('#storage_location')).toHaveValue('1');
});

test('scrolling a panel after opening a select menu closes it', async ({ page }) => {
  await loadLayout(page);
  await page.locator('#storage_location-button').scrollIntoViewIfNeeded();
  await page.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))));
  await page.locator('#storage_location-button').click();
  await expect(page.locator('.ui-selectmenu-open')).toHaveCount(1);
  await page.evaluate(() => { document.querySelector('.cactiConsoleContentArea').scrollTop -= 10; });
  await expect(page.locator('.ui-selectmenu-open')).toHaveCount(0);
});

test('a scroll queued before the menu opens does not immediately close it', async ({ page }) => {
  await loadLayout(page);
  await page.evaluate(() => {
    document.querySelector('.cactiConsoleContentArea').scrollTop = 1800;
    $('#storage_location').selectmenu('open');
  });
  await page.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))));
  await expect(page.locator('.ui-selectmenu-open')).toHaveCount(1);
  await page.getByRole('option', { name: 'RRDtool Proxy Server' }).click();
  await expect(page.locator('#storage_location')).toHaveValue('1');
});

test('shared theme controls preserve filter icons, select widget sizing and both logos', async ({ page }) => {
  await loadLayout(page);
  await page.setContent('<input id="filter"><input id="filterd"><input id="rfilter"><select id="plugin.field:select"><option>One</option><option>Two</option></select><select id="multiple" multiple><option>Three</option></select><div class="cactiLoginLogo"></div><div class="cactiLogoutLogo"></div>');
  await page.addStyleTag({ url: '/include/fa/css/all.css' });
  await page.evaluate(() => {
    window.searchFilter = 'Search'; window.searchRFilter = 'Filter';
    for (let i = 0; i < 2; i++) { setupThemeSearchIcons(); setupThemeSelectmenus(); setupThemeLogos('paw'); }
  });
  await expect(page.locator('input + i.fa-search')).toHaveCount(3);
  await expect(page.locator('#rfilter')).toHaveAttribute('placeholder', 'Filter');
  const widgets = await page.evaluate(() => {
    const select = document.getElementById('plugin.field:select');
    return { maxHeight: $(select).selectmenu('menuWidget').css('max-height'), multiple: $('#multiple').selectmenu('instance') !== undefined };
  });
  expect(widgets).toEqual({ maxHeight: '250px', multiple: false });
  await expect(page.locator('.cactiLoginLogo i.fa-paw, .cactiLogoutLogo i.fa-paw')).toHaveCount(2);
  await page.evaluate(() => setupThemeLogos('sun'));
  await expect(page.locator('.cactiLoginLogo i.fa-sun, .cactiLogoutLogo i.fa-sun')).toHaveCount(2);
  for (const logo of await page.locator('.cactiLoginLogo i, .cactiLogoutLogo i').all()) {
    expect(await logo.evaluate(node => getComputedStyle(node, '::before').content)).not.toMatch(/^(none|normal|"")$/);
  }
});


test('shared form controls retain import labels and theme widths', async ({ page }) => {
  await loadLayout(page);
  await page.setContent('<input id="filter"><input type="password"><textarea></textarea><div class="checkboxgroup"><br><input type="checkbox" id="check"><label for="check">Check</label></div><button class="import_label">Import</button><input class="import_button"><span class="import_text"></span><select><option>One</option></select>');
  await page.evaluate(() => { window.searchFilter = 'Search'; window.searchRFilter = 'Filter'; window.noFileSelected = 'No file'; setupThemeFormControls(480); });
  await expect(page.locator('.import_text')).toHaveText('No file');
  await expect(page.locator('.checkboxgroup br')).toHaveCount(0);
  await page.locator('.import_button').fill('fixture.xml');
  await page.locator('.import_button').dispatchEvent('change');
  await expect(page.locator('.import_text')).toHaveText('fixture.xml');
  expect(await page.evaluate(() => maxWidth)).toBe(480);
  await page.evaluate(() => setupThemeFormControls(300));
  expect(await page.evaluate(() => maxWidth)).toBe(300);
  await expect(page.locator('input#filter + i.fa-search')).toHaveCount(1);
});

test('responsive filters preserve control callbacks and visibility across clicks', async ({ page }) => {
  await loadLayout(page);
  await page.addScriptTag({ url: '/include/js/js.storage.js' });
  await page.setContent('<div id="main"><div class="cactiConsoleContentArea"><div id="deviceFilter" class="cactiTable">'
    + '<div><div class="cactiTableTitle">Device filters</div><div class="cactiTableButton"></div></div>'
    + '<div id="deviceFilter_child"><table class="filterTable"><tr><td><input id="export" value="Export"><input id="import" value="Import"><input id="clear" value="Clear"></td></tr></table></div>'
    + '</div><table id="dataTable" class="cactiTable"><tr><th>Device</th></tr><tr><td>Router</td></tr></table></div></div>');
  await page.addStyleTag({ url: '/include/fa/css/all.css' });
  const posts = [];
  await page.route('**/auth_profile.php?**', async route => {
    posts.push(new URLSearchParams(route.request().postData()));
    await route.fulfill({ status: 200, body: '' });
  });
  await page.evaluate(() => {
    window.urlPath = '/'; window.userSettings = true; window.hScroll = false;
    window.csrfMagicToken = 'owned-token'; window.tableConstraints = 'Column constraints'; window.clearFilterTitle = 'Clear filter';
    window.showHideFilter = 'Show or hide filter'; window.clickedControls = [];
    $('#export, #import, #clear').on('click', function () { clickedControls.push(this.id); });
    Storages.localStorage.set('filterVisibility', 'visible');
    makeFiltersResponsive();
  });
  await expect(page.locator('#overflow')).toHaveClass(/fa-expand/);
  await page.locator('.cactiSwitchConstraints').click();
  await expect(page.locator('#overflow')).toHaveClass(/fa-compress/);
  await page.locator('.cactiSwitchConstraints').click();
  await expect(page.locator('#overflow')).toHaveClass(/fa-expand/);
  expect(posts.map(p => [p.get('name'), p.get('value'), p.get('__csrf_magic')])).toEqual([
    ['enable_hscroll', 'on', 'owned-token'], ['enable_hscroll', '', 'owned-token'],
  ]);
  await page.locator('#deviceFilter .cactiTableTitle').click();
  await expect(page.locator('#deviceFilter_child')).toBeHidden();
  for (const name of ['Export', 'Import', 'Clear filter']) await page.locator(`#deviceFilter span[title="${name}"]`).click();
  expect(await page.evaluate(() => clickedControls)).toEqual(['export', 'import', 'clear']);
  await page.locator('#deviceFilter .cactiTableTitle').click();
  await expect(page.locator('#deviceFilter_child')).toBeVisible();
  await page.evaluate(() => {
    $('#deviceFilter .cactiFilterState, .cactiSwitchConstraintWrapper').remove();
    Storages.localStorage.set('filterVisibility', 'hidden'); hScroll = true; makeFiltersResponsive();
  });
  await expect(page.locator('#deviceFilter_child')).toBeHidden();
  await expect(page.locator('#overflow')).toHaveClass(/fa-compress/);
  await expect(page.locator('.cactiFilterState i')).toHaveClass(/fa-angle-double-down/);
});

test('debug table actions and stored collapsible sections retain registry glyphs', async ({ page }) => {
  await loadLayout(page);
  await page.addScriptTag({ url: '/include/js/js.storage.js' });
  await page.setContent('<div id="dqdebug"><div class="cactiTableButton"><a href="#">Remove query</a><a class="cactiTableCopy" href="#">Copy query</a></div></div>'
    + '<div id="section" class="spacer collapsible"><i></i>Device settings</div><div id="fields"><input value="retained"></div><div class="spacer">Next</div>');
  await page.evaluate(() => {
    $.fx.off = true; makeFiltersResponsive();
    Storages.localStorage.set('section_cs', 'hide'); setupCollapsible(); setupCollapsible();
  });
  await expect(page.locator('#dqdebug a:not(.cactiTableCopy)')).toHaveAttribute('title', 'Remove query');
  await expect(page.locator('#dqdebug a:not(.cactiTableCopy)')).toHaveClass(/fa-trash/);
  await expect(page.locator('#dqdebug .cactiTableCopy')).toHaveClass(/fa-copy/);
  await expect(page.locator('#fields')).toBeHidden();
  await page.locator('#section').click();
  await expect(page.locator('#fields')).toBeVisible();
  await page.locator('#section').click();
  await expect(page.locator('#fields')).toBeHidden();
  expect(await page.evaluate(() => Storages.localStorage.get('section_cs'))).toBe('hide');
  await expect(page.locator('#fields input')).toHaveValue('retained');
});

test('SNMP passphrase validation draws real status glyphs for each field state', async ({ page }) => {
  await loadLayout(page);
  await page.setContent('<input type="password" id="snmp_password"><input type="password" id="snmp_password_confirm"><input type="password" id="snmp_priv_passphrase"><input type="password" id="snmp_priv_passphrase_confirm">');
  await page.evaluate(() => {
    Object.assign(window, { passwordTooShort: 'Too short', passwordPass: 'Valid', passwordMatchTooShort: 'Partial match', passwordNotMatchTooShort: 'Short mismatch', passwordNotMatch: 'Mismatch', passwordMatch: 'Match' });
  });
  for (const [type, input, confirm, status] of [['auth', 'snmp_password', 'snmp_password_confirm', 'auth'], ['priv', 'snmp_priv_passphrase', 'snmp_priv_passphrase_confirm', 'priv']]) {
    for (const [pass, confirmation, text] of [['abcd', 'ab', 'Partial match'], ['abcdefgh', 'zz', 'Short mismatch'], ['abcdefgh', 'different', 'Mismatch'], ['abcdefgh', 'abcdefgh', 'Match']]) {
      await page.locator(`#${input}`).fill(pass); await page.locator(`#${confirm}`).fill(confirmation);
      await page.evaluate(t => checkSNMPPassphrase(t), type);
      await expect(page.locator(`#${status}conf`)).toContainText(text);
      await expect(page.locator(`#${status}conf i`)).toHaveClass(text === 'Match' ? /fa-check/ : /fa-times/);
    }
    await page.locator(`#${input}`).fill(''); await page.evaluate(t => checkSNMPPassphrase(t), type);
    await expect(page.locator(`#${status}, #${status}conf`)).toHaveCount(0);
  }
  expect(await page.evaluate(() => [iconClass('unregistered-icon'), iconSelector('unregistered-icon')])).toEqual(['', ':not(*)']);
});

test('realtime graph activation preserves loading glyph response and original image', async ({ page }) => {
  const errors = [];
  page.on('pageerror', error => errors.push(error.message));
  await loadLayout(page);
  await page.setContent('<input id="date1" value="2026-01-01 00:00"><input id="date2" value="2026-01-01 01:00">'
    + '<input id="graph_start" value="60"><input id="ds_step" value="60"><input id="size" value="100"><input id="columns" value="1">'
    + '<div id="timespan">Timespan</div><div id="search">Search</div><div id="device">Device</div><div id="realtime" style="display:none">Realtime</div>'
    + '<div id="wrapper_7"><img id="graph_7" alt="Original graph" src="data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw=="></div>'
    + '<a id="graph_7_realtime" href="#"><img alt="Start realtime" src="data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw=="></a>');
  await page.addStyleTag({ url: '/include/fa/css/all.css' });
  for (const file of ['include/js/js.storage.js', 'include/js/jquery.cookie.js', 'include/js/pace.js', 'include/js/jquery.zoom.js', 'include/realtime.js']) await page.addScriptTag({ url: '/' + file });
  const requests = [];
  const envelopes = [];
  const csrfToken = 'owned-realtime-csrf-token';
  const image = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a6r0AAAAASUVORK5CYII=';
  await page.route('**/graph_realtime.php?**', async route => {
    requests.push(new URL(route.request().url()));
    envelopes.push({ method: route.request().method(), fields: [...new URLSearchParams(route.request().postData() || '')] });
    await route.fulfill({ status: 200, contentType: 'text/plain', body: JSON.stringify({ local_graph_id: 7, image_format: 'png', data: image }) });
  });
  await page.evaluate(token => {
    Object.assign(window, { csrfMagicToken: token, urlPath: '/', realtimeClickOn: 'Start realtime', realtimeClickOff: 'Stop realtime', refreshMSeconds: 600000, refreshIsLogout: false, timeOffset: 0 });
    initializeGraphs();
  }, csrfToken);
  const before = await page.locator('#wrapper_7').innerHTML();
  await page.locator('#graph_7_realtime').click();
  await expect(page.locator('#graph_7_realtime i')).toHaveClass('drillDown ' + icons.loading);
  await expect(page.locator('#graph_7_realtime i')).toHaveAttribute('title', 'Stop realtime');
  await expect(page.locator('#graph_7')).toHaveAttribute('src', 'data:image/png;base64,' + image);
  await expect(page.locator('#realtime')).toBeVisible();
  expect(requests).toHaveLength(1);
  expect(envelopes).toEqual([{ method: 'POST', fields: [['__csrf_magic', csrfToken]] }]);
  expect(['local_graph_id', 'graph_start', 'ds_step'].map(key => requests[0].searchParams.get(key))).toEqual(['7', '-60', '60']);
  await page.locator('#graph_7_realtime').click();
  expect(await page.locator('#wrapper_7').innerHTML()).toBe(before);
  expect(errors).toEqual([]);
  await expect(page.locator('#realtime')).toBeHidden();
  await expect(page.locator('#timespan')).toBeVisible();
  expect(await page.evaluate(() => realtimeArray[7])).toBe(false);
});

async function loadDarkGraphMenu(page) {
  await loadLayout(page);
  await page.setContent('<div id="dd1" class="graphDrillDown" style="width:100px;height:80px"><div class="iconWrapper"><button id="graph-one">First graph</button></div></div><div id="dd2" class="graphDrillDown" style="width:100px;height:80px"><div class="iconWrapper"><button id="graph-two">Second graph</button></div></div><button id="outside">Outside graphs</button>');
  await page.addStyleTag({ url: '/include/themes/dark/main.css' });
  await page.evaluate(() => {
    window.searchFilter = 'Search'; window.searchRFilter = 'Filter'; window.noFileSelected = 'No file';
  });
  await page.addScriptTag({ url: '/include/themes/dark/main.js' });
  // Exercise the legitimate pointer-inside state when graph handlers initialize.
  await page.locator('#dd1').hover();
  await page.evaluate(() => {
    // This owned graph-only fixture isolates unrelated navigation/page sizing.
    // Keep the actual themeReady graph handlers and shared layout state intact.
    window.keepWindowSize = () => {};
    window.setMenuVisibility = () => {};
    window.setNavigationScroll = () => {};
    themeReady();
  });
  // A hover within an already-entered cell does not emit another mouseenter.
  // Leave after binding so the test performs a real outside-to-graph transition.
  await page.locator('#outside').hover();
}

test('dark graph hover retains handoff leave reinitialization and keyboard focus', async ({ page }) => {
  const errors = [];
  page.on('pageerror', error => errors.push(error.message));
  await loadDarkGraphMenu(page);
  const first = page.locator('#dd1');
  const second = page.locator('#dd2');
  for (let pass = 0; pass < 2; pass++) {
    if (pass) await page.evaluate(() => themeReady());
    await first.hover();
    await expect(first).toHaveClass(/iconsShown/);
    await expect(first.locator('.iconWrapper')).toHaveCSS('opacity', '1');
    await second.hover();
    await expect(first).not.toHaveClass(/iconsShown/);
    await expect(second).toHaveClass(/iconsShown/);
    expect(await page.evaluate(() => graphMenuElement)).toBe('2');
    await page.locator('#outside').hover();
    await expect(second).not.toHaveClass(/iconsShown/);
    await expect(second.locator('.iconWrapper')).toHaveCSS('opacity', '0');
  }
  await page.locator('body').click({ position: { x: 500, y: 500 } });
  await page.keyboard.press('Tab');
  await expect(page.locator('#graph-one')).toBeFocused();
  await expect(first.locator('.iconWrapper')).toHaveCSS('opacity', '1');
  await expect(first).not.toHaveClass(/iconsShown/);
  expect(errors).toEqual([]);
});

test('dark graph timer snapshots its element before a class hook changes shared state', async ({ page }) => {
  await loadDarkGraphMenu(page);
  await page.evaluate(() => {
    window.savedDarkAddClass = $.fn.addClass;
    $.fn.addClass = function (name) {
      const result = savedDarkAddClass.apply(this, arguments);
      if (name === 'iconsShown') window.element = $('#dd2');
      return result;
    };
  });
  try {
    await page.locator('#dd1').hover();
    await expect(page.locator('#dd1')).toHaveClass(/iconsShown/);
    expect(await page.evaluate(() => graphMenuElement)).toBe('1');
    await expect(page.locator('#dd2')).not.toHaveClass(/iconsShown/);
  } finally {
    await page.evaluate(() => { $.fn.addClass = savedDarkAddClass; delete window.savedDarkAddClass; });
  }
});

for (const nativeApi of [true, false]) {
  test(`native own icon lookup preserves registry semantics with modern API ${nativeApi ? 'available' : 'absent'}`, async ({ page }) => {
    await loadLayout(page);
    const result = await page.evaluate(enabled => {
      const descriptor = Object.getOwnPropertyDescriptor(Object, 'hasOwn');
      try {
        if (!enabled) Object.defineProperty(Object, 'hasOwn', { value: undefined, configurable: true });
        const registry = Object.create(null);
        registry.add = 'fa fa-plus'; registry.hasOwnProperty = 'registry-value';
        window.kadupulIcons = registry;
        const ordinary = [iconClass('add'), iconClass('missing'), iconClass('hasOwnProperty'), iconSelector('add'), iconMarkup('add')];
        window.kadupulIcons = Object.create({ inherited: 'not-an-own-icon' });
        return { ordinary, inherited: iconClass('inherited') };
      } finally {
        Object.defineProperty(Object, 'hasOwn', descriptor);
      }
    }, nativeApi);
    expect(result).toEqual({ ordinary: ['fa fa-plus', '', 'registry-value', '.fa.fa-plus', '<i class="fa fa-plus" aria-hidden="true"></i>'], inherited: '' });
  });
}
