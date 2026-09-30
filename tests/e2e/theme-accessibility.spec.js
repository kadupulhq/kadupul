// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

const { test, expect } = require('@playwright/test');

const fs = require('fs');
const path = require('path');
const themeRoot = path.resolve(__dirname, '../../include/themes');
const allThemes = fs.readdirSync(themeRoot, { withFileTypes: true })
  .filter((entry) => entry.isDirectory())
  .map((entry) => entry.name)
  .sort();
if (allThemes.length === 0) throw new Error('No shipped themes found');

// classic and paw never suppressed outlines and keep the browser's own ring.
const ringThemes = ['dark', 'midwinter', 'modern', 'paper-plane', 'sunrise'];

async function openTheme(page, theme, color) {
  const query = color ? `theme=${theme}&color=${color}` : `theme=${theme}`;
  await page.goto(`/tests/e2e/theme-accessibility.html?${query}`);
  await page.waitForFunction(() => window.__themeReady === true || window.__themeError);
  expect(await page.evaluate(() => window.__themeError || null)).toBeNull();
}

// Tabbing, rather than calling focus(), proves the element is in the tab order
// and makes the browser apply :focus-visible the way it does for a real user.
async function tabTo(page, id) {
  for (let i = 0; i < 40; i++) {
    await page.keyboard.press('Tab');
    if (await page.evaluate(() => document.activeElement && document.activeElement.id) === id) {
      return;
    }
  }
  throw new Error(`#${id} was not reached by Tab`);
}

function outline(page, selector, pseudo = null) {
  return page.evaluate(([sel, pse]) => {
    const style = getComputedStyle(document.querySelector(sel), pse);
    return { style: style.outlineStyle, width: parseFloat(style.outlineWidth), color: style.outlineColor };
  }, [selector, pseudo]);
}

// WCAG 2.x contrast of a computed colour against the backgrounds behind it,
// taking the worst case. An outline is drawn outside the box, so it is measured
// against the parent's background rather than the element's own. Linear
// gradient stops count as backgrounds: the background shorthand leaves
// background-color transparent, so reading the colour alone skips a gradient
// row and measures the page behind it. Radial gradients are left out; sunrise's
// page glow centres below the viewport, so its stops do not bound what shows.
async function contrastAgainstBackground(page, selector, colorOf, against = null) {
  return page.evaluate(([sel, prop, fixed]) => {
    const parse = (value) => {
      const m = value.match(/rgba?\(([^)]+)\)/);
      const parts = m[1].split(/[ ,/]+/).filter(Boolean).map(Number);
      return { r: parts[0], g: parts[1], b: parts[2], a: parts.length > 3 ? parts[3] : 1 };
    };
    const over = (top, bottom) => ({
      r: top.r * top.a + bottom.r * (1 - top.a),
      g: top.g * top.a + bottom.g * (1 - top.a),
      b: top.b * top.a + bottom.b * (1 - top.a),
      a: 1,
    });
    // Each layer is the set of colours one paint step can put behind the text,
    // listed top first.
    const backgrounds = (element) => {
      const layers = [];
      for (let node = element; node; node = node.parentElement) {
        const style = getComputedStyle(node);
        const stops = /^(-webkit-)?(repeating-)?linear-gradient|^-webkit-gradient\(linear/.test(style.backgroundImage)
          ? style.backgroundImage.match(/rgba?\([^)]+\)/g).map(parse)
          : [];
        const fill = parse(style.backgroundColor);
        const opaque = [];
        if (stops.length) {
          layers.push(stops);
          opaque.push(stops.every((stop) => stop.a === 1));
        }
        if (fill.a > 0) {
          layers.push([fill]);
          opaque.push(fill.a === 1);
        }
        if (opaque.includes(true)) break;
      }
      return layers.reverse().reduce(
        (below, layer) => layer.flatMap((top) => below.map((bottom) => over(top, bottom))),
        [{ r: 255, g: 255, b: 255, a: 1 }],
      );
    };
    const luminance = ({ r, g, b }) => {
      const channel = (v) => {
        const c = v / 255;
        return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4;
      };
      return 0.2126 * channel(r) + 0.7152 * channel(g) + 0.0722 * channel(b);
    };
    const element = document.querySelector(sel);
    // An image behind the text is not a colour, so a caller names the
    // worst-case colour it has to clear instead.
    const candidates = fixed ? [parse(fixed)] : backgrounds(prop === 'outlineColor' ? element.parentElement : element);
    const color = parse(getComputedStyle(element)[prop]);
    const contrast = (a, b) => {
      const [hi, lo] = [luminance(over(a, b)), luminance(b)].sort((x, y) => y - x);
      return (hi + 0.05) / (lo + 0.05);
    };
    // A hard box shadow reaching past the ring is a halo: the ring has to
    // stand out from the halo, and one of the two from the panel.
    const style = getComputedStyle(element);
    const reach = parseFloat(style.outlineOffset) + parseFloat(style.outlineWidth);
    const halo = prop === 'outlineColor' && style.boxShadow !== 'none'
      ? style.boxShadow.split(/,(?![^(]*\))/).map((shadow) => /^\s*(rgba?\([^)]+\))\s+0px\s+0px\s+0px\s+([\d.]+)px\s*$/.exec(shadow))
        .filter((m) => m && parseFloat(m[2]) > reach).map((m) => over(parse(m[1]), { r: 255, g: 255, b: 255, a: 1 }))[0]
      : null;
    if (halo) {
      return Math.min(contrast(color, halo), ...candidates.map((bg) => Math.max(contrast(color, bg), contrast(halo, bg))));
    }
    return Math.min(...candidates.map((bg) => contrast(color, bg)));
  }, [selector, colorOf, against]);
}

test.describe('theme keyboard focus', () => {
  const targets = ['plainLink', 'textInput', 'mainTab', 'navLink', 'pageTab', 'saveButton', 'bottomLink'];

  // midwinter's light mode puts the header and navigation on a mid blue, so it
  // gets its own pass.
  const passes = allThemes.map((theme) => ({ theme, color: null }))
    .concat([{ theme: 'midwinter', color: 'light' }]);

  for (const { theme, color } of passes) {
    const name = color ? `${theme} ${color}` : theme;

    test(`${name} shows a focus ring for keyboard users`, async ({ page }) => {
      await openTheme(page, theme, color);

      for (const id of targets) {
        if (await page.evaluate((target) => document.getElementById(target).getClientRects().length === 0, id)) {
          // midwinter hides the page footer altogether; any other hidden
          // target means the fixture no longer exercises it.
          expect(theme === 'midwinter' && id === 'bottomLink', `${name} #${id} is hidden`).toBe(true);
          continue;
        }
        await tabTo(page, id);
        const ring = await outline(page, `#${id}`);
        expect(ring.style, `${name} #${id} outline`).not.toBe('none');
        expect(ring.width, `${name} #${id} outline width`).toBeGreaterThan(0);

        if (ringThemes.includes(theme)) {
          expect(ring.width, `${name} #${id} outline width`).toBeGreaterThanOrEqual(2);
          const ratio = await contrastAgainstBackground(page, `#${id}`, 'outlineColor');
          expect(ratio, `${name} #${id} ring contrast`).toBeGreaterThanOrEqual(3);
        }
      }
    });
  }

  for (const theme of allThemes) {
    test(`${theme} shows no focus ring after a mouse click`, async ({ page }) => {
      await openTheme(page, theme);

      await page.click('#plainLink');
      expect(await page.evaluate(() => document.activeElement.id)).toBe('plainLink');
      expect((await outline(page, '#plainLink')).style).toBe('none');
    });
  }

  // The content area plus panels that keep their own background whatever the
  // page colour: dark title rows and message boxes in midwinter light, white
  // dialogs and menus in paper-plane.
  const link = '<a id="ringTarget" href="#">x</a>';
  const surfaces = {
    'content area': `<div class="cactiContent">${link}</div>`,
    'selected row': `<table class="cactiTable"><tr class="selectable selected"><td>${link}</td></tr></table>`,
    'table title row': `<div class="cactiTableTitleRow">${link}</div>`,
    // lib/html.php html_start_box() puts the Add and help links here.
    'table title button': '<div class="cactiTable"><div><div class="cactiTableTitle"><span>Devices</span></div>'
      + '<div class="cactiTableButton"><span class="cactiHelp"><a id="ringTarget" class="linkOverDark" href="#">?</a></span></div></div></div>',
    'table header': `<table class="cactiTable"><tr class="tableHeader"><th>${link}</th></tr></table>`,
    'message box': `<div class="messageBox">${link}</div>`,
    'spike kill menu': `<ul class="spikekillMenu"><li>${link}</li></ul>`,
    'menu options': '<ul class="menuoptions" style="display:block;position:static"><li><a id="ringTarget" href="#">x</a></li></ul>',
    'dialog': `<div class="ui-dialog ui-widget ui-widget-content"><div class="ui-dialog-content ui-widget-content">${link}</div></div>`,
    'dialog button': '<div class="ui-dialog ui-widget ui-widget-content"><div class="ui-dialog-buttonpane ui-widget-content"><button id="ringTarget" class="ui-button ui-corner-all ui-widget">Continue</button></div></div>',
    'dialog title': `<div class="ui-dialog ui-widget ui-widget-content"><div class="ui-dialog-titlebar ui-widget-header">${link}</div></div>`,
    'multiselect header': `<div class="ui-multiselect-menu ui-widget ui-widget-content" style="position:static"><div class="ui-widget-header ui-multiselect-header"><ul><li>${link}</li></ul></div></div>`,
    // auth_login.php nests the form in loginCenter, which modern fills light.
    'login page': `<div class="loginBody"><div class="loginCenter"><div class="loginArea">${link}</div></div></div>`,
    // include/layout.js setupEllipsis(), host.php and lib/html.php draw_graph_items_list().
    'overflow menu': `<div class="dropdownMenu"><ul class="submenuoptions" style="display:block;position:static"><li>${link}</li></ul></div>`,
    'device header': `<table class="hostInfoHeader"><tr><td class="textInfo right">${link}</td></tr></table>`,
    'graph item row': `<table class="cactiTable"><tr class="tableRowGraph"><td>${link}</td></tr></table>`,
    'install wizard': `<div class="cactiInstallArea cactiBorderWall"><div class="cactiInstallAreaContent"><p class="cactiInstallSection cactiInstallSectionNormal">${link}</p></div></div>`,
    'install wizard form row': `<div class="cactiInstallArea"><div class="cactiInstallAreaContent"><table class="cactiTable"><tr><td><div class="formRow"><div class="formData">${link}</div></div></td></tr></table></div></div>`,
    'install wizard table row': `<div class="cactiInstallArea"><div class="cactiInstallAreaContent"><table class="cactiTable"><tr class="odd"><td>${link}</td></tr></table></div></div>`,
  };
  const panelPasses = ringThemes.map((theme) => ({ theme, color: null }))
    .concat([{ theme: 'midwinter', color: 'light' }]);

  for (const { theme, color } of panelPasses) {
    const name = color ? `${theme} ${color}` : theme;

    test(`${name} focus ring reaches 3:1 on light and dark panels`, async ({ page }) => {
      await openTheme(page, theme, color);

      for (const [surface, markup] of Object.entries(surfaces)) {
        await page.evaluate((html) => { document.getElementById('contrastArea').innerHTML = html; }, markup);
        await page.evaluate(() => document.getElementById('saveButton').focus());
        await tabTo(page, 'ringTarget');
        const ratio = await contrastAgainstBackground(page, '#ringTarget', 'outlineColor');
        expect(ratio, `${name} ring in ${surface}`).toBeGreaterThanOrEqual(3);
      }
    });
  }

  // The switch ring sits on the slider, not the focused input, so a panel rule
  // aimed at :focus-visible alone never reaches it.
  const switches = '<label class="checkboxSwitch"><input class="formCheckbox" type="checkbox" id="panelBox"><span class="checkboxSlider checkboxRound"></span></label>'
    + '<label class="radioSwitch"><input class="formCheckbox" type="radio" id="panelRadio" name="panelRadio"><span class="radioSlider radioRound"></span></label>';
  const switchSurfaces = {
    'content area': `<div class="cactiContent">${switches}</div>`,
    'dialog': `<div class="ui-dialog ui-widget ui-widget-content"><div class="ui-dialog-content ui-widget-content">${switches}</div></div>`,
    'install wizard': `<div class="cactiInstallArea"><div class="cactiInstallAreaContent"><table class="cactiTable"><tr><td><div class="formRow"><div class="formData">${switches}</div></div></td></tr></table></div></div>`,
    'install wizard dark row': `<div class="cactiInstallArea"><div class="cactiInstallAreaContent"><table class="cactiTable"><tr><td><div class="formRow even-alternate"><div class="formData">${switches}</div></div></td></tr></table></div></div>`,
  };

  // paw keeps the browser ring elsewhere but draws its own on the sliders.
  for (const { theme, color } of panelPasses.concat([{ theme: 'paw', color: null }])) {
    const name = color ? `${theme} ${color}` : theme;

    test(`${name} switch ring reaches 3:1 in content and dialogs`, async ({ page }) => {
      await openTheme(page, theme, color);
      // Slider transitions animate outline-color; measure the settled colour.
      await page.addStyleTag({ content: '*, *::before { transition: none !important; }' });

      for (const [surface, markup] of Object.entries(switchSurfaces)) {
        await page.evaluate((html) => { document.getElementById('contrastArea').innerHTML = html; }, markup);
        await page.evaluate(() => document.getElementById('saveButton').focus());
        for (const [input, slider] of [['panelBox', '.checkboxSlider'], ['panelRadio', '.radioSlider']]) {
          await tabTo(page, input);
          const ratio = await contrastAgainstBackground(page, `#${input} + ${slider}`, 'outlineColor');
          expect(ratio, `${name} ${slider} ring in ${surface}`).toBeGreaterThanOrEqual(3);
        }
      }
    });
  }

  test('midwinter draws the ring on the replacement checkbox and radio label', async ({ page }) => {
    for (const color of [null, 'light']) {
      await openTheme(page, 'midwinter', color);

      await tabTo(page, 'rowCheck');
      const box = await outline(page, 'label[for="rowCheck"]', '::before');
      expect(box.style).toBe('solid');
      expect(box.width).toBeGreaterThanOrEqual(2);

      await page.click('#plainLink');
      expect((await outline(page, 'label[for="rowCheck"]', '::before')).style).toBe('none');
    }
  });
});

test.describe('theme switch controls', () => {
  // classic leaves the native checkbox and radio visible.
  const switchThemes = allThemes.filter((theme) => theme !== 'classic');

  for (const theme of allThemes) {
    test(`${theme} switch checkbox and radio work from the keyboard`, async ({ page }) => {
      await openTheme(page, theme);

      await tabTo(page, 'switchBox');
      await page.keyboard.press('Space');
      expect(await page.isChecked('#switchBox')).toBe(true);
      await page.keyboard.press('Space');
      expect(await page.isChecked('#switchBox')).toBe(false);

      await tabTo(page, 'switchRadio_1');
      await page.keyboard.press('ArrowDown');
      expect(await page.isChecked('#switchRadio_2')).toBe(true);

      if (switchThemes.includes(theme)) {
        const ring = await outline(page, 'label.radioSwitch:has(#switchRadio_2) .radioSlider');
        expect(ring.style, `${theme} radio slider ring`).toBe('solid');
        expect(ring.width).toBeGreaterThanOrEqual(2);
      }
    });
  }

  for (const theme of switchThemes) {
    test(`${theme} switch looks and clicks the same for mouse users`, async ({ page }) => {
      await openTheme(page, theme);

      await tabTo(page, 'switchBox');
      const ring = await outline(page, '#switchBox + .checkboxSlider');
      expect(ring.style, `${theme} checkbox slider ring`).toBe('solid');
      expect(ring.width).toBeGreaterThanOrEqual(2);

      const box = await page.locator('#switchBox').boundingBox();
      expect(box.width).toBeLessThanOrEqual(1);
      expect(box.height).toBeLessThanOrEqual(1);
      expect(await page.locator('#switchBox').evaluate((el) => getComputedStyle(el).opacity)).toBe('0');

      await page.click('#switchBox + .checkboxSlider');
      expect(await page.isChecked('#switchBox')).toBe(true);
      expect((await outline(page, '#switchBox + .checkboxSlider')).style).toBe('none');
    });
  }

  test('sunrise keeps plain checkboxes reachable and rings the drawn box', async ({ page }) => {
    await openTheme(page, 'sunrise');

    await tabTo(page, 'rowCheck');
    const ring = await outline(page, 'label[for="rowCheck"]', '::before');
    expect(ring.style).toBe('solid');
    expect(ring.width).toBeGreaterThanOrEqual(2);

    await page.keyboard.press('Space');
    expect(await page.isChecked('#rowCheck')).toBe(true);
  });
});

test.describe('dark graph utility icons', () => {
  const wrapperSize = (page) => page.locator('#dd1 .iconWrapper').evaluate((el) => {
    const rect = el.getBoundingClientRect();
    return { width: rect.width, height: rect.height, opacity: getComputedStyle(el).opacity };
  });

  test('stay hidden until the graph cell is hovered or focused', async ({ page }) => {
    await openTheme(page, 'dark');

    // The fixture adds the stylesheet after the markup, so let the fade settle.
    await expect.poll(async () => (await wrapperSize(page)).opacity).toBe('0');
    expect((await wrapperSize(page)).width).toBeLessThanOrEqual(1);

    await page.locator('#dd1').evaluate((el) => el.classList.add('iconsShown'));
    const hovered = await wrapperSize(page);
    expect(hovered.width).toBeGreaterThan(1);
    expect(hovered.height).toBeGreaterThan(1);

    await page.locator('#dd1').evaluate((el) => el.classList.remove('iconsShown'));
    expect((await wrapperSize(page)).width).toBeLessThanOrEqual(1);
  });

  test('are reachable by Tab and shown while focus is inside', async ({ page }) => {
    await openTheme(page, 'dark');

    await tabTo(page, 'graph_1_util');
    const focused = await wrapperSize(page);
    expect(focused.width).toBeGreaterThan(1);
    await expect(page.locator('#graph_1_util')).toBeVisible();

    await tabTo(page, 'graph_1_csv');
    expect((await wrapperSize(page)).width).toBeGreaterThan(1);

    await tabTo(page, 'bottomLink');
    expect((await wrapperSize(page)).width).toBeLessThanOrEqual(1);
  });

  test('close after a mouse click once the pointer leaves', async ({ page }) => {
    await openTheme(page, 'dark');

    // Browsers focus a clicked href="#" link, so a :focus-within reveal would
    // keep the column open after main.js drops the hover class.
    await page.locator('#dd1').evaluate((el) => el.classList.add('iconsShown'));
    await page.click('#graph_1_csv');
    expect(await page.evaluate(() => document.activeElement.id)).toBe('graph_1_csv');

    await page.locator('#dd1').evaluate((el) => el.classList.remove('iconsShown'));
    expect((await wrapperSize(page)).width).toBeLessThanOrEqual(1);
  });
});

test.describe('theme text contrast', () => {
  const rows = (cells) => '<table class="cactiTable"><tr class="odd"><td>' + cells + '</td></tr>'
    + '<tr class="even"><td>' + cells + '</td></tr></table>';
  const spans = (classes) => classes.map((name) => `<span class="${name}">${name}</span>`).join(' ');
  const statusFamily = ['deviceDown', 'deviceUnknown', 'deviceError', 'deviceWarning', 'deviceAlert'];
  // get_colored_device_status() cells in host.php, user_admin.php and
  // api_automation.php land in selected rows, which are light in dark and
  // paper-plane.
  const selectedRow = (cells) => '<table class="cactiTable"><tr class="selectable selected"><td>' + cells + '</td></tr></table>';
  const dialog = (cells) => '<div class="ui-dialog ui-widget ui-widget-content"><div class="ui-dialog-content ui-widget-content">' + cells + '</div></div>';
  // lib/html.php html_spikekill_menu_item() colours its icons with the status classes.
  const spikeMenu = '<ul class="spikekillMenu">'
    + '<li><span class="spikeKillMenuItem"><i class="deviceUnknown fa fa-life-ring"></i>Gap Fill Range</span></li>'
    + '<li><span class="spikeKillMenuItem"><i class="deviceDown fa fa-life-ring"></i>Float Range</span></li></ul>';

  // Each case lists classes whose text must reach 4.5:1 (or 3:1 for icons) on
  // every row they render in.
  const cases = [
    { theme: 'classic', markup: rows(spans(['disabled', 'running', 'errored', 'failed', 'badpassword', 'notAssociated', 'loginErrors'])) + '<table><tr class="disabled_row"><td><span class="disabledRowText">row</span></td></tr></table>' },
    { theme: 'modern', markup: rows(spans(['deviceUp', 'deviceDown', 'deviceDisabled', 'deviceRecovering', 'deviceDownMuted', 'deviceThreshold', 'deviceUnmonitored', 'deviceWarning', 'deviceAlert', 'loginErrors'])) },
    { theme: 'dark', markup: rows(spans(statusFamily)) + selectedRow(spans(statusFamily)) },
    { theme: 'dark', surface: 'the spike kill menu', min: 3, markup: spikeMenu },
    { theme: 'paper-plane', markup: rows(spans(statusFamily.concat(['notBeingGraphed'])) + ' <a class="plain" href="#">link</a> <span class="ui-selectmenu-button ui-button">All</span>') },
    { theme: 'paper-plane', surface: 'selected rows and dialogs', markup: selectedRow(spans(statusFamily)) + dialog(spans(statusFamily) + rows(spans(statusFamily))) },
    { theme: 'paper-plane', surface: 'rows and the spike kill menu', min: 3, markup: rows('<i class="deletequery fa fa-times">x</i> <span class="moveArrow">&uarr;</span>') + spikeMenu },
    { theme: 'paw', markup: rows(spans(['disabled'])) + '<table><tr class="disabled_row"><td><span class="disabledRowText">row</span></td></tr></table>' },
  ];

  for (const { theme, surface = 'rows', markup, min = 4.5 } of cases) {
    test(`${theme} status and link text reaches ${min}:1 on ${surface}`, async ({ page }) => {
      await openTheme(page, theme);
      await page.evaluate((html) => { document.getElementById('contrastArea').innerHTML = html; }, markup);

      const count = await page.locator('#contrastArea span, #contrastArea a, #contrastArea i, #contrastArea input').count();
      for (let i = 0; i < count; i++) {
        const target = `#contrastArea :is(span, a, i, input) >> nth=${i}`;
        const handle = page.locator(target);
        const label = await handle.evaluate((el) => el.className + ' in ' + (el.closest('tr, .ui-dialog-content, .spikekillMenu') ? el.closest('tr, .ui-dialog-content, .spikekillMenu').className : 'page'));
        await handle.evaluate((el) => { el.id = 'contrastTarget'; });
        const ratio = await contrastAgainstBackground(page, '#contrastTarget', 'color');
        expect(ratio, `${theme} ${label}`).toBeGreaterThanOrEqual(min);
        await handle.evaluate((el) => { el.removeAttribute('id'); });
      }
    });
  }

  test('classic table header text reaches 4.5:1', async ({ page }) => {
    await openTheme(page, 'classic');
    await page.evaluate(() => {
      document.getElementById('contrastArea').innerHTML = '<table><tr class="tableHeader"><th id="hdr">Name</th></tr></table>'
        + '<div class="formHeader" id="formHdr">Form</div>';
    });
    expect(await contrastAgainstBackground(page, '#hdr', 'color')).toBeGreaterThanOrEqual(4.5);
    expect(await contrastAgainstBackground(page, '#formHdr', 'color')).toBeGreaterThanOrEqual(4.5);
  });

  test('paw keeps link hover readable on hovered and selected rows', async ({ page }) => {
    await openTheme(page, 'paw');
    await page.evaluate(() => {
      document.getElementById('contrastArea').innerHTML = '<table>'
        + '<tr class="selectable odd"><td><a id="hoverLink" class="linkEditMain" href="#">Device</a></td></tr>'
        + '<tr class="selectable selected"><td><a id="selectedLink" class="linkEditMain" href="#">Device</a></td></tr>'
        + '</table><p><a id="bareLink" class="linkEditMain" href="#">Bare</a></p>';
    });

    await page.hover('#hoverLink');
    expect(await contrastAgainstBackground(page, '#hoverLink', 'color')).toBeGreaterThanOrEqual(4.5);
    await page.hover('#selectedLink');
    expect(await page.locator('#selectedLink').evaluate((el) => getComputedStyle(el).color)).toBe('rgb(255, 255, 0)');
    await page.hover('#bareLink');
    expect(await contrastAgainstBackground(page, '#bareLink', 'color')).toBeGreaterThanOrEqual(4.5);
  });

  test('paper-plane install wizard links reach 4.5:1 on the light panel and dark rows', async ({ page }) => {
    // lib/installer.php prints doc links in the section text and plugin
    // homepages in dark table rows.
    await openTheme(page, 'paper-plane');
    await page.evaluate(() => {
      document.getElementById('contrastArea').innerHTML = '<div class="cactiInstallArea cactiBorderWall"><div class="cactiInstallAreaContent">'
        + '<p class="cactiInstallSection cactiInstallSectionNormal">Read the <a id="installLink" href="#">Upgrade</a> file.</p>'
        + '<table class="cactiTable"><tr class="odd"><td><a id="installRowLink" href="#">homepage</a></td></tr></table>'
        + '</div></div>';
    });
    for (const id of ['installLink', 'installRowLink']) {
      expect(await contrastAgainstBackground(page, `#${id}`, 'color'), `paper-plane #${id}`).toBeGreaterThanOrEqual(4.5);
      await page.hover(`#${id}`);
      expect(await contrastAgainstBackground(page, `#${id}`, 'color'), `paper-plane #${id}:hover`).toBeGreaterThanOrEqual(4.5);
    }
  });

  test('sunrise selectmenu text and version clear the gradient\'s lightest stop', async ({ page }) => {
    await openTheme(page, 'sunrise');
    await page.evaluate(() => {
      document.getElementById('contrastArea').innerHTML = '<span id="goButton" class="ui-selectmenu-button ui-button">All</span>'
        + '<div class="cactiVersion" id="version">1.3.0</div><div class="versionInfo" id="versionInfo">1.3.0</div>';
    });
    for (const id of ['goButton', 'version', 'versionInfo']) {
      for (const against of ['rgb(0, 0, 0)', 'rgb(4, 51, 91)']) {
        const ratio = await contrastAgainstBackground(page, `#${id}`, 'color', against);
        expect(ratio, `sunrise #${id} on ${against}`).toBeGreaterThanOrEqual(4.5);
      }
    }
  });

  test('midwinter body text, links and navigation reach 4.5:1 in both colour modes', async ({ page }) => {
    for (const color of [null, 'light']) {
      await openTheme(page, 'midwinter', color);
      await page.evaluate(() => {
        document.getElementById('contrastArea').innerHTML = '<span id="bodyText">text</span> <a id="bodyLink" href="#">link</a>';
      });
      const vars = await page.evaluate(() => {
        const root = getComputedStyle(document.documentElement);
        const probe = document.createElement('span');
        document.body.appendChild(probe);
        const resolve = (name) => { probe.style.color = `var(${name})`; return getComputedStyle(probe).color; };
        const out = {
          background: resolve('--background'),
          content: resolve('--background-content'),
          navigation: resolve('--background-navigation'),
          navText: resolve('--text-color-navigation'),
        };
        probe.remove();
        return out;
      });
      for (const bg of [vars.background, vars.content]) {
        expect(await contrastAgainstBackground(page, '#bodyText', 'color', bg), `midwinter ${color} text on ${bg}`).toBeGreaterThanOrEqual(4.5);
        expect(await contrastAgainstBackground(page, '#bodyLink', 'color', bg), `midwinter ${color} link on ${bg}`).toBeGreaterThanOrEqual(4.5);
      }
      await page.evaluate((c) => { document.getElementById('bodyText').style.color = c; }, vars.navText);
      expect(await contrastAgainstBackground(page, '#bodyText', 'color', vars.navigation), `midwinter ${color} navigation`).toBeGreaterThanOrEqual(4.5);
    }
  });

  test('midwinter login, logout and dark panel text reaches 4.5:1 in both colour modes', async ({ page }) => {
    // These backgrounds stay dark in light mode, so the darker light-mode link
    // colour must not reach them.
    const markup = '<div class="loginBody"><div class="loginArea"><fieldset><legend id="loginLegend">User Login</legend></fieldset>'
      + '<h1 class="loginHeading" id="loginHeading">User Login</h1></div></div>'
      + '<div class="logoutBody"><div class="logoutArea"><a id="logoutLink" href="#">Login Again</a></div></div>'
      + '<div class="cactiTableTitleRow"><a id="titleRowLink" href="#">Title</a></div>'
      + '<div class="messageBox"><a id="messageLink" href="#">Message</a></div>'
      + '<ul class="spikekillMenu"><li><a id="spikeLink" href="#">Spike</a></li></ul>'
      + '<table class="cactiTable"><tr class="selectable selected"><td><a id="selectedLink" class="linkEditMain" href="#">Row</a></td></tr></table>'
      + '<div class="cactiInstallArea"><p class="cactiInstallSection"><a id="installLink" href="#">Upgrade</a></p></div>'
      + '<div class="cactiContent"><fieldset><legend id="contentLegend">Options</legend></fieldset><a id="contentLink" href="#">link</a></div>';
    for (const color of [null, 'light']) {
      await openTheme(page, 'midwinter', color);
      await page.evaluate((html) => { document.getElementById('contrastArea').innerHTML = html; }, markup);
      for (const id of ['loginLegend', 'loginHeading', 'logoutLink', 'titleRowLink', 'messageLink', 'spikeLink', 'selectedLink', 'installLink', 'contentLegend', 'contentLink']) {
        expect(await contrastAgainstBackground(page, `#${id}`, 'color'), `midwinter ${color} #${id}`).toBeGreaterThanOrEqual(4.5);
      }
    }
  });
});
