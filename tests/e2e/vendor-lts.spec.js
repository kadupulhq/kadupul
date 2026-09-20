const path = require('node:path');
const { test, expect } = require('@playwright/test');

async function load(page, ...files) {
  for (const file of files) {
    await page.addScriptTag({ path: path.resolve(__dirname, '../../', file) });
  }
}

test.beforeEach(async ({ page }) => {
  await page.route('**/__vendor_lts', route => route.fulfill({
    contentType: 'text/html', body: '<!doctype html><html><head></head><body></body></html>',
  }));
  await page.goto('/__vendor_lts');
  await load(page, 'include/js/jquery.js');
});

test('cookie refresh preserves LTS missing, encoded, raw and deletion contracts', async ({ page }) => {
  await load(page, 'include/js/jquery.cookie.js');
  const result = await page.evaluate(() => {
    const missing = $.cookie('absent');
    $.cookie('encoded', 'a + b/é', { path: '/' });
    const encoded = $.cookie('encoded');
    $.cookie('raw', 'a%20b', { raw: true, path: '/' });
    const raw = $.cookie('raw', { raw: true });
    const decoded = $.cookie('raw');
    $.cookie('encoded', null, { path: '/' });
    const deleted = $.cookie('encoded');
    $.cookie('undefined', 'present', { path: '/' });
    $.cookie('undefined', undefined, { path: '/' });
    $.cookie('remove', 'present', { path: '/' });
    const removed = $.removeCookie('remove', { path: '/' });
    return { missing, encoded, raw, decoded, deleted, undefinedDeleted: $.cookie('undefined'), removed };
  });
  expect(result).toEqual({ missing: null, encoded: 'a + b/é', raw: 'a%20b', decoded: 'a b', deleted: null, undefinedDeleted: null, removed: true });
});

test('cookie options and names cannot pollute prototypes', async ({ page }) => {
  await load(page, 'include/js/jquery.cookie.js');
  expect(await page.evaluate(() => {
    $.cookie('test', 'safe', JSON.parse('{"__proto__":{"polluted":true}}'));
    document.cookie = '__proto__=untrusted; path=/';
    const all = $.cookie();
    return [({}).polluted === undefined, Object.getPrototypeOf(all) === null, all.__proto__];
  })).toEqual([true, true, 'untrusted']);
});

test('LTS cookie function values remain writes, not new converter callbacks', async ({ page }) => {
  await load(page, 'include/js/jquery.cookie.js');
  expect(await page.evaluate(() => {
    let calls = 0;
    const value = function () { calls++; return 'converted'; };
    $.cookie('function-value', value, { path: '/' });
    return { called: calls, preserved: $.cookie('function-value') === String(value) };
  })).toEqual({ called: 0, preserved: true });
});

test('UAParser retains browser and operating system detection', async ({ page }) => {
  await load(page, 'include/themes/midwinter/vendor/ua-parser/ua-parser.js');
  const result = await page.evaluate(() => new UAParser('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36').getResult());
  expect(result.browser.name).toBe('Chrome');
  expect(result.os.name).toBe('Windows');
});

test('hotkeys dispatches and unbinds without changing the theme API', async ({ page }) => {
  await load(page, 'include/themes/midwinter/vendor/hotkeys/hotkeys.js');
  await page.evaluate(() => { window.keyCount = 0; hotkeys('alt+k', () => window.keyCount++); });
  await page.keyboard.press('Alt+k');
  expect(await page.evaluate(() => window.keyCount)).toBe(1);
  await page.evaluate(() => hotkeys.unbind('alt+k'));
  await page.keyboard.press('Alt+k');
  expect(await page.evaluate(() => window.keyCount)).toBe(1);
});

test('jQuery Hotkeys retains object-form key bindings', async ({ page }) => {
  await load(page, 'include/js/jquery.hotkeys.js');
  await page.evaluate(() => {
    window.jqueryKeyCount = 0;
    $(document).on('keydown', { keys: 'alt+j' }, () => window.jqueryKeyCount++);
  });
  await page.keyboard.press('Alt+j');
  expect(await page.evaluate(() => window.jqueryKeyCount)).toBe(1);
});

test('D3 quantile indexes retain original positions and support generators', async ({ page }) => {
  await load(page, 'include/js/d3.js');
  expect(await page.evaluate(() => {
    function* values() { yield null; yield 10; yield 20; yield NaN; yield 30; }
    return [d3.version, d3.quantileIndex(values(), 0.5), d3.quantileIndex([NaN, 9, 3, 5], 0.5)];
  })).toEqual(['7.9.0', 2, 3]);
});

test('tablesorter sorts and paginates with the existing plugin API', async ({ page }) => {
  await page.setContent('<table id="grid"><thead><tr><th>Value</th></tr></thead><tbody><tr><td>3</td></tr><tr><td>1</td></tr><tr><td>2</td></tr></tbody></table><div id="pager"><span class="pagedisplay"></span><select class="pagesize"><option selected>2</option></select></div>');
  await load(page, 'include/js/jquery.tablesorter.js', 'include/js/jquery.tablesorter.widgets.js', 'include/js/jquery.tablesorter.pager.js');
  await page.evaluate(() => $('#grid').tablesorter({ sortList: [[0, 0]], widgets: ['zebra'] }).tablesorterPager({ container: $('#pager'), size: 2, savePages: false }));
  await expect(page.locator('#grid tbody tr:visible')).toHaveCount(2);
  await expect(page.locator('#grid tbody tr:visible').first()).toHaveText('1');
});

test('colorpicker and multiselect initialize on the existing jQuery UI', async ({ page }) => {
  await page.setContent('<input id="color" value="ff0000"><select id="choice" multiple><option selected value="a">Alpha</option><option value="b">Beta</option></select>');
  await load(page, 'include/js/jquery-ui.js', 'include/js/jquery.colorpicker.js', 'include/js/jquery.multiselect.js', 'include/js/jquery.multiselect.filter.js');
  const result = await page.evaluate(() => {
    $('#color').colorpicker({ colorFormat: 'HEX', showOn: 'button' });
    $('#choice').multiselect().multiselectfilter();
    return { color: $('#color').val(), selected: $('#choice').val(), initialized: !!$('#color').data('vanderlee-colorpicker') };
  });
  expect(result.color).toBe('ff0000');
  expect(result.selected).toEqual(['a']);
  expect(result.initialized).toBe(true);
});

test('Sparkline and Dygraphs render charts', async ({ page }) => {
  await page.setContent('<span id="spark"></span><div id="graph" style="width:600px;height:300px"></div>');
  await load(page, 'include/js/jquery.sparkline.js', 'include/js/dygraph-combined.js');
  await page.evaluate(() => {
    $('#spark').sparkline([1, 4, 2, 5], { type: 'line' });
    window.graph = new Dygraph(document.getElementById('graph'), [[1, 2], [2, 4]], { labels: ['X', 'Y'] });
  });
  await expect(page.locator('#spark canvas')).toHaveCount(1);
  expect(await page.locator('#graph canvas').count()).toBeGreaterThan(0);
});

test('TableDnD retains initialization and row serialization', async ({ page }) => {
  await page.setContent('<table id="rows"><tbody><tr id="row_1"><td>One</td></tr><tr id="row_2"><td>Two</td></tr></tbody></table>');
  await load(page, 'include/js/jquery.tablednd.js');
  const result = await page.evaluate(() => {
    $('#rows').tableDnD();
    // The application serializes from onDrop, when currentTable is populated.
    $.tableDnD.currentTable = document.getElementById('rows');
    return { initialized: !!document.getElementById('rows').tableDnDConfig, serialized: $('#rows').tableDnDSerialize() };
  });
  expect(result.initialized).toBe(true);
  expect(result.serialized).toContain('1');
  expect(result.serialized).toContain('2');
});

test('Font Awesome loads matching fonts and renders the SVG API', async ({ page }) => {
  await page.setContent('<i class="fas fa-user"></i>');
  await page.addStyleTag({ url: '/include/fa/css/all.css' });
  expect(await page.evaluate(async () => (await document.fonts.load('900 16px "Font Awesome 5 Free"')).length)).toBeGreaterThan(0);
  await load(page, 'include/fa/js/all.js');
  await expect(page.locator('svg[data-icon="user"]')).toHaveCount(1);
});
