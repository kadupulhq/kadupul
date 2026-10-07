// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

const { test, expect } = require('@playwright/test');
const fs = require('node:fs');
const path = require('node:path');

// Exercise the actual shipped cascade. Fixtures expose normally hidden menus;
// their colors and backgrounds come entirely from the production stylesheet.
const levels = ['Emergency', 'Critical', 'Alert', 'Warning', 'Error', 'Notice', 'Info', 'Debug'];
const fixture = `
  <div class="cactiContent"><span class="deviceDown" id="offline">Offline device</span><a href="#" id="contentlink">Ordinary content link</a><table><tr class="tableRowGraph"><td><a href="#" id="lightlink">Light panel link</a></td></tr><tr class="odd"><td><a href="#" id="oddlink">Dark row link</a></td></tr></table></div>
  <div class="deviceUnknownBg" id="unknown">Unknown device</div>
  <div class="deviceErrorBg" id="error">Device error</div>
  <div class="deviceAlertBg" id="alert">Device alert</div>
  <table><tbody><tr>${levels.map(name => `<td class="log${name}" id="log${name}">${name}</td>`).join('')}</tr></tbody></table>
  <div class="popupBox" id="popup">Popup</div><div class="textMenuHeader" id="header">Menu header</div>
  <ul class="submenuoptions"><li><a href="#" id="submenu">Submenu</a></li></ul>
  <ul class="menuoptions"><li><a href="#" id="menu">Menu</a></li></ul>
  <div class="cactiLoginTable"><input class="ui-button" type="button" id="login" value="Login"></div>
  <div class="saveRow"><input class="ui-button" type="button" id="save" value="Save"></div>
  <div class="actionsDropdown"><div><span class="ui-selectmenu-button" aria-expanded="true" id="expanded">Selected action</span></div></div>
  <div class="actionsDropdownButton"><input class="ui-button" type="button" id="action" value="Action"></div>
  <button class="ui-state-default ui-button" id="widget">Widget</button>
  <input type="checkbox" id="checkbox" checked><label for="checkbox" id="checklabel">Enabled</label>
  <div class="cactiInstallSectionTitle" id="install">Install section</div>
  <ul id="nav"><li><a href="#" id="navlink">Navigation</a></li></ul>
  <ul class="pagination"><li><a href="#" id="page">Page two</a></li></ul>
`;

for (const theme of ['dark', 'paper-plane', 'sunrise']) {
  test(`${theme} scanned declaration pairs remain readable in the browser`, async ({ page }, info) => {
    await page.setContent(`<!doctype html><html><body>${fixture}</body></html>`);
    await page.addStyleTag({ content: fs.readFileSync(path.resolve(__dirname, '../../include/themes', theme, 'main.css'), 'utf8') });
    await page.addStyleTag({ content: 'body { overflow: auto !important; } .popupBox, .submenuoptions, .menuoptions, #nav { display: block !important; position: static !important; }' });
    // Check the installer fallback separately from its decorative image.
    await page.locator('#install').evaluate(element => { element.style.backgroundImage = 'none'; });
    const cases = ['unknown', 'error', 'alert', 'popup', 'header', ...levels.map(name => `log${name}`)].map(id => ({ id }));
    if (theme === 'dark') cases.push({ id: 'navlink' }, { id: 'page', hover: true }, { id: 'offline' });
    if (theme === 'paper-plane') cases.push({ id: 'widget' }, { id: 'expanded' }, { id: 'contentlink', hover: true }, { id: 'lightlink', hover: true }, { id: 'oddlink', hover: true }, ...['login', 'submenu', 'menu', 'save', 'action'].map(id => ({ id, hover: true })));
    if (theme === 'sunrise') cases.push({ id: 'checklabel', pseudo: '::before' }, { id: 'install' }, ...['submenu', 'menu'].map(id => ({ id, hover: true })));
    expect(cases).toHaveLength(theme === 'dark' ? 16 : theme === 'paper-plane' ? 23 : 17);
    const readings = [];
    for (const probe of cases) {
      if (probe.hover) await page.locator(`#${probe.id}`).hover();
      const reading = await page.evaluate(({ id, pseudo }) => {
        const element = document.getElementById(id);
        const style = getComputedStyle(element, pseudo || null);
        const rgba = value => {
          const parts = value.match(/[\d.]+/g).map(Number);
          return [...parts.slice(0, 3), parts.length > 3 ? parts[3] : 1];
        };
        const blend = (front, back) => front.slice(0, 3).map((value, i) => value * front[3] + back[i] * (1 - front[3]));
        const ancestors = [];
        for (let current = pseudo ? element : element.parentElement; current; current = current.parentElement) ancestors.unshift(current);
        let background = [255, 255, 255];
        for (const ancestor of ancestors) background = blend(rgba(getComputedStyle(ancestor).backgroundColor), background);
        background = blend(rgba(style.backgroundColor), background);
        const foreground = blend(rgba(style.color), background);
        // WCAG relative luminance and unrounded normal-text ratio.
        const luminance = rgb => rgb.map(value => {
          const channel = value / 255;
          return channel <= 0.04045 ? channel / 12.92 : ((channel + 0.055) / 1.055) ** 2.4;
        }).reduce((sum, value, index) => sum + value * [0.2126, 0.7152, 0.0722][index], 0);
        const f = luminance(foreground), b = luminance(background);
        return { id, foreground: style.color, background: style.backgroundColor, ratio: (Math.max(f, b) + 0.05) / (Math.min(f, b) + 0.05) };
      }, probe);
      readings.push(reading);
      if (theme === 'dark' && probe.id === 'offline') expect(reading.foreground).toBe('rgb(255, 155, 155)');
      if (theme === 'paper-plane' && probe.id === 'lightlink') expect(reading.foreground).toBe('rgb(51, 51, 51)');
      expect.soft(reading.ratio, `${theme} ${probe.id}: ${JSON.stringify(reading)}`).toBeGreaterThanOrEqual(4.5);
      if (probe.hover) await page.mouse.move(0, 0);
    }
    const report = info.outputPath('computed-contrast.json');
    fs.writeFileSync(report, JSON.stringify(readings, null, 2));
    await info.attach('computed-contrast', { path: report, contentType: 'application/json' });
    await page.screenshot({ path: info.outputPath(`${theme}-contrast.png`), fullPage: true });
  });
}
