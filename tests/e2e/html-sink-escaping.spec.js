// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later
const fs = require('fs');
const path = require('path');
const { test, expect } = require('@playwright/test');
require('./browser-coverage').collectThemeCoverage(test);

const root = path.join(__dirname, '..', '..');

// Returns the body of a page's [data-tooltip] content callback, exactly as the
// PHP file prints it.
function permissionTooltipBody(file) {
  const source = fs.readFileSync(path.join(root, file), 'utf8');
  const match = source.match(/items: '\[data-tooltip\]',\s*content: function\(\) \{([\s\S]*?)\n\t+\}/);

  if (!match) {
    throw new Error(`No permission tooltip callback in ${file}`);
  }

  return match[1];
}

// Returns tree.php's draggable() as the page prints it.
function treeDraggable() {
  const source = fs.readFileSync(path.join(root, 'tree.php'), 'utf8');
  const start = source.indexOf('function draggable(element) {');
  let depth = 0;

  for (let offset = source.indexOf('{', start); offset < source.length; offset++) {
    if (source[offset] === '{') {
      depth++;
    } else if (source[offset] === '}' && --depth === 0) {
      return source.slice(start, offset + 1);
    }
  }

  throw new Error('No draggable() in tree.php');
}

async function loadLayout(page) {
  await page.goto('/tests/e2e/theme-smoke.html');
  await page.waitForFunction(() => window.__themeSmokeReady);
  await page.addScriptTag({ url: '/include/js/jquery.tablesorter.js' });
  // Skip the application-ready bootstrap; this fixture supplies its own page.
  await page.evaluate(() => { window.savedReady = $.fn.ready; $.fn.ready = function () { return this; }; });
  await page.addScriptTag({ url: '/include/layout.js' });
  await page.evaluate(() => { $.fn.ready = window.savedReady; });
}

test('a color name with markup stays inside the color dropdown input', async ({ page }) => {
  const names = [
    'Evil" autofocus onfocus=window.pwn=1 x="',
    '"><img src=x onerror=window.pwn=1>',
  ];

  await loadLayout(page);

  for (const name of names) {
    await page.evaluate((text) => {
      window.pwn = 0;
      const select = $('<select class="colordropdown"><option value="">None</option><option value="7" selected></option></select>');
      select.find('option[value="7"]').text(text);
      $('#sandbox').empty().append(select);
      select.dropcolor();
    }, name);
    await page.waitForTimeout(50);

    const state = await page.evaluate(() => ({
      inputs: $('#sandbox input').length,
      value: $('#sandbox input').val(),
      images: $('#sandbox img').length,
      handlers: $('#sandbox [onfocus], #sandbox [onerror]').length,
      pwn: window.pwn,
    }));

    expect(state).toEqual({ inputs: 1, value: name, images: 0, handlers: 0, pwn: 0 });
  }
});

test('an ordinary color name is shown in the color dropdown input', async ({ page }) => {
  await loadLayout(page);
  await page.evaluate(() => {
    $('#sandbox').empty().append('<select class="colordropdown"><option value="">None</option><option value="7" selected>Red (FF0000)</option></select>');
    $('#sandbox select').dropcolor();
  });

  await expect(page.locator('#sandbox input')).toHaveValue('Red (FF0000)');
});

for (const file of ['user_admin.php', 'user_group_admin.php']) {
  test(`${file} shows permission reasons with markup as text`, async ({ page }) => {
    // Two 20-character group names that only form a tag once the reason joins them.
    const reasons = [
      'Granted By: Group:(<img src=x a="), Group:(" onerror=window.pwn=1>)',
      'Granted By: Group:(Operators)',
    ];

    await loadLayout(page);
    await page.evaluate((body) => {
      $(document).tooltip({ items: '[data-tooltip]', content: new Function(body) });
    }, permissionTooltipBody(file));

    for (const reason of reasons) {
      await page.evaluate((text) => {
        window.pwn = 0;
        $('.ui-tooltip').remove();
        const span = $('<span class="accessGranted">Granted</span>').attr('data-tooltip', text);
        $('#sandbox').empty().append(span);
      }, reason);
      await page.locator('#sandbox span[data-tooltip]').hover();
      await expect(page.locator('.ui-tooltip-content')).toBeVisible();
      await page.waitForTimeout(100);

      const state = await page.evaluate(() => ({
        images: $('.ui-tooltip img').length,
        handlers: $('.ui-tooltip [onerror]').length,
        pwn: window.pwn,
      }));

      expect(state).toEqual({ images: 0, handlers: 0, pwn: 0 });
      await expect(page.locator('.ui-tooltip-content')).toHaveText(reason);
      await page.mouse.move(0, 0);
    }
  });
}

test('rebuilding a tree list keeps escaped names as text', async ({ page }) => {
  const names = [
    '<span style="position:fixed;top:0;left:0;width:100%;height:100%;z-index:99999"><a href="https://example.invalid/">Session expired</a></span>',
    '<img src=x onerror=window.pwn=1>',
    'Traffic - eth0 & lo',
  ];

  await loadLayout(page);
  await page.addScriptTag({ url: '/include/js/purify.js' });
  await page.addScriptTag({ url: '/include/js/jstree.js' });
  await page.evaluate((code) => {
    window.editable = false;
    window.reset = false;
    window.pwn = 0;
    (0, eval)(code);
  }, treeDraggable());

  for (const name of names) {
    await page.evaluate((title) => {
      // The same markup display_graphs() prints, with the title escaped.
      const item = $('<li id="tgraph:7" data-jstree=\'{ "type": "graph" }\'></li>').text(title);
      $('#sandbox').empty().append($('<div id="graphs"></div>').append($('<ul></ul>').append(item)));
      draggable('graphs');
    }, name);
    await expect(page.locator('#graphs .jstree-anchor')).toHaveText(name);

    // Locking the tree rebuilds the list from the rendered nodes.
    await page.evaluate(() => draggable('graphs'));
    await expect(page.locator('#graphs .jstree-anchor')).toHaveText(name);
    await page.waitForTimeout(50);

    const state = await page.evaluate(() => ({
      nodes: $('#graphs .jstree-node').length,
      injected: $('#graphs .jstree-anchor').find('span, a, img').length,
      pwn: window.pwn,
    }));

    expect(state).toEqual({ nodes: 1, injected: 0, pwn: 0 });
  }
});
