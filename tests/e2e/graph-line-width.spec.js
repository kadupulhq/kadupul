// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-2.0-or-later
const fs = require('node:fs');
const path = require('node:path');
const { test, expect } = require('@playwright/test');

for (const editor of ['graphs_items.php', 'graph_templates_items.php']) {
  test(`${editor} switches fixed and stacked line controls`, async ({ page }) => {
    const source = fs.readFileSync(path.resolve(__dirname, '../..', editor), 'utf8');
    const start = source.indexOf('function setRowVisibility()');
    expect(start).toBeGreaterThanOrEqual(0);
    const end = source.indexOf('</script>', start);
    expect(end).toBeGreaterThan(start);
    // Execute the complete shipped function; adjacent color/CDEF helpers are outside this fixture.
    const script = source.slice(start, end);
    await page.goto('/tests/e2e/theme-smoke.html');
    await page.addScriptTag({ url: '/include/js/jquery.js' });
    await page.setContent(`<select id="graph_type_id">${[4, 5, 6, 20].map(type => `<option>${type}</option>`).join('')}</select>` +
      ['line_width', 'task_item_id', 'color_id', 'dashes', 'dash_offset', 'shift', 'alpha', 'cdef_id',
        'vdef_id', 'text_format', 'hard_return'].map(field => `<div id="row_${field}"><input name="${field}" value="2.50"></div>`).join(''));
    await page.evaluate(() => { window.changeColorId = () => {}; window.cdefAlignment = () => {}; });
    await page.addScriptTag({ content: script });
    await page.evaluate(() => $('#graph_type_id').on('change', setRowVisibility));
    for (const type of ['4', '5', '6']) {
      await page.selectOption('#graph_type_id', type);
      await expect(page.locator('#row_line_width')).toBeHidden();
      for (const field of ['task_item_id', 'color_id', 'dashes', 'shift', 'alpha', 'cdef_id', 'text_format']) {
        await expect(page.locator(`#row_${field}`)).toBeVisible();
      }
      await expect(page.locator('input[name="line_width"]')).toHaveValue('2.50');
      await page.selectOption('#graph_type_id', '20');
      await expect(page.locator('#row_line_width')).toBeVisible();
      await expect(page.locator('input[name="line_width"]')).toHaveValue('2.50');
    }
  });
}
