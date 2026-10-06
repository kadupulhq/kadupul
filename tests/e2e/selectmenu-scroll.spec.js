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
