const { test, expect } = require('@playwright/test');

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
