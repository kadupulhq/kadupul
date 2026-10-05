// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later
const { test, expect } = require('@playwright/test');
const fs = require('node:fs');
const path = require('node:path');
const { spawnSync } = require('node:child_process');
const { PNG } = require(path.join(path.dirname(require.resolve('playwright-core/package.json')), 'lib/utilsBundle.js'));
const measure = path.join(__dirname, 'theme-contrast/measure.js');
async function measurement(page, markup) {
  await page.setContent(`<!doctype html><html style="background:white"><body style="margin:0">${markup}</body></html>`);
  await page.addScriptTag({ path: measure });
  return page.evaluate(() => window.__contrast.measureText(document.getElementById('text'))[0]);
}
for (const nested of [false, true]) {
  test(`contrast follows browser ${nested ? 'nested' : 'single'} opacity groups`, async ({ page }, info) => {
    const inner = nested ? 'background:red;opacity:.5;' : '';
    const markup = `<div style="background:black;opacity:.5;width:240px;height:110px"><div style="${inner}height:100px"><span id="text" style="color:white;font:64px Arial">IIII</span></div></div>`;
    const reading = await measurement(page, markup);
    // Keep the .5 model regression, but use an exactly representable 8-bit
    // alpha for the independent pixel oracle. Chromium compositor backends
    // quantize the .5 midpoint differently; no contrast/assertion bound changes.
    const pixels = await measurement(page, markup.replaceAll('opacity:.5', `opacity:${127 / 255}`));
    expect(pixels).toMatchObject({ bg: reading.bg, fg: reading.fg });
    const shot = await page.screenshot({ path: info.outputPath('opacity-browser-pixels.png') });
    await info.attach('opacity-browser-pixels', { body: shot, contentType: 'image/png' });
    const png = PNG.sync.read(shot);
    const offset = (80 * png.width + 10) * 4;
    const expectedBackground = nested ? [191, 128, 128] : [128, 128, 128];
    [...png.data.subarray(offset, offset + 3)].forEach((value, index) => expect(Math.abs(value - expectedBackground[index])).toBeLessThanOrEqual(1));
    expect(reading.bg).toBe(nested ? '#bf8080' : '#808080');
    expect(reading.fg).toBe(nested ? '#bfbfbf' : '#ffffff');
    expect(reading.ratio).toBeGreaterThan(nested ? 1.5 : 3.9);
    const foreground = nested ? 191 : 255;
    let solidGlyph = false;
    for (let y = 12; y < 65; y++) for (let x = 0; x < 80; x++) {
      const at = (y * png.width + x) * 4;
      if ([0, 1, 2].every(channel => Math.abs(png.data[at + channel] - foreground) <= 1)) solidGlyph = true;
    }
    expect(solidGlyph).toBe(true);
  });
}
for (const covering of ['none', 'transparent', 'opaque']) {
  test(`URL image evidence survives ${covering} covering paint`, async ({ page }) => {
    const cover = covering === 'opaque' ? 'background:black' : covering === 'transparent' ? 'background:rgba(0,0,0,.5)' : '';
    const reading = await measurement(page, `<div style="background-color:white;background-image:url('/images/spikekill.gif');width:240px;height:100px"><div style="${cover};height:90px"><span id="text" style="color:red">Image background</span></div></div>`);
    expect(reading.image).toBe(covering !== 'opaque');
  });
}
for (const top of ['gradient', 'image']) {
  test(`background paint order retains a ${top} above the other layer`, async ({ page }) => {
    const gradient = 'linear-gradient(black, black)', image = "url('/images/spikekill.gif')";
    const layers = top === 'gradient' ? `${gradient},${image}` : `${image},${gradient}`;
    const reading = await measurement(page, `<div style="background-color:white;background-image:${layers};width:240px;height:100px"><span id="text" style="color:red">Layered background</span></div>`);
    expect(reading.image).toBe(top === 'image');
  });
}
test('comparison rejects head-only failures and empty reports', () => {
  const dir = path.join(__dirname, 'theme-contrast-results');
  fs.mkdirSync(dir, { recursive: true });
  const label = `regression-${process.pid}-${Date.now()}`;
  const before = `${label}-base`, after = `${label}-head`;
  const files = [before, after].map(value => path.join(dir, `theme-contrast-${value}-dark.json`));
  const passing = { page: 'graphs', state: 'default', kind: 'text', key: 'existing', text: 'Existing', ratio: 7, required: 4.5 };
  const failing = { ...passing, key: 'new', text: 'New', ratio: 1 };
  const compare = () => spawnSync(process.execPath, [path.join(__dirname, 'theme-contrast/compare.js'), before, after], { encoding: 'utf8' });
  try {
    fs.writeFileSync(files[0], JSON.stringify([passing]));
    fs.writeFileSync(files[1], JSON.stringify([passing, failing]));
    expect(compare().status).toBe(1);
    fs.writeFileSync(files[0], JSON.stringify([passing, failing]));
    expect(compare().status).toBe(0);
    fs.writeFileSync(files[1], JSON.stringify([passing, { ...failing, ratio: 0.8 }]));
    expect(compare().status).toBe(1);
    fs.writeFileSync(files[1], JSON.stringify([passing, { ...failing, ratio: null }]));
    expect(compare().status).toBe(2);
    fs.writeFileSync(files[1], '[]');
    expect(compare().status).toBe(2);
  } finally { files.forEach(filename => fs.unlinkSync(filename)); }
});
test('production spike control accepts Enter and Space under enforcing CSP and repeated initialization', async ({ page }) => {
  const rendered = spawnSync(process.env.PHP_BINARY || 'php', [path.join(__dirname, '../Fixtures/spikekill-render-native.php')], { encoding: 'utf8' });
  expect(rendered.status, rendered.stderr).toBe(0);
  const output = JSON.parse(rendered.stdout);
  const violations = [];
  page.on('console', message => { if (message.text().includes('Content Security Policy')) violations.push(message.text()); });
  await page.route('**/spike-native', route => route.fulfill({
    headers: { 'Content-Security-Policy': output.csp }, contentType: 'text/html',
    body: output.html,
  }));
  let requests = 0;
  let releasePending;
  const pending = new Promise(resolve => { releasePending = resolve; });
  await page.route('**/*action=spikemenu*', async route => {
    requests++;
    expect(new URL(route.request().url()).searchParams.get('local_graph_id')).toBe('100');
    if (requests === 5) await pending;
    return route.fulfill({ contentType: 'text/html', body: '<div class="spikekillParent"><ul class="spikekillMenu"><li><div>Settings</div></li></ul></div>' });
  });
  await page.goto('/spike-native');
  const trigger = page.getByRole('button', { name: 'Kill Spikes in Graphs' });
  await expect(trigger).toBeVisible();
  for (let tabs = 0; tabs < 8 && !(await trigger.evaluate(element => element === document.activeElement)); tabs++) await page.keyboard.press('Tab');
  await expect(trigger).toBeFocused();
  expect(await trigger.evaluate(element => parseFloat(getComputedStyle(element).outlineWidth))).toBeGreaterThan(0);
  for (const key of ['Enter', 'Space']) {
    await page.keyboard.press(key);
    await expect(trigger).toHaveAttribute('aria-expanded', 'true');
    await expect(page.getByRole('menu')).toBeVisible();
    await expect(page.getByRole('menu')).toBeFocused();
    expect(await trigger.locator('ul').count()).toBe(0);
    await page.keyboard.press('Escape');
    await expect(trigger).toHaveAttribute('aria-expanded', 'false');
    await expect(trigger).toBeFocused();
    await expect(page.getByRole('menu')).toHaveCount(0);
  }
  await page.keyboard.press('Enter');
  await expect(trigger).toHaveAttribute('aria-expanded', 'true');
  await page.evaluate(script => window.jQuery('body').append(script), output.script);
  await expect(trigger).toHaveAttribute('aria-expanded', 'false');
  await expect(page.getByRole('menu')).toHaveCount(0);
  await expect(trigger).toBeFocused();
  await page.keyboard.press('Space');
  await expect(trigger).toHaveAttribute('aria-expanded', 'true');
  await page.keyboard.press('Escape');
  await page.keyboard.press('Enter');
  await expect.poll(() => requests).toBe(5);
  await expect(trigger).toBeDisabled();
  const generation = await page.evaluate(() => spikeKillGeneration);
  await page.evaluate(script => window.jQuery('body').append(script), output.script);
  await expect.poll(() => page.evaluate(() => spikeKillGeneration)).toBe(generation + 1);
  releasePending();
  await expect(trigger).toBeEnabled();
  await expect(trigger).toHaveAttribute('aria-expanded', 'false');
  await expect(page.getByRole('menu')).toHaveCount(0);
  await trigger.focus();
  await page.keyboard.press('Enter');
  await expect(trigger).toHaveAttribute('aria-expanded', 'true');
  await page.keyboard.press('Escape');
  expect(requests).toBe(6);
  expect(violations).toEqual([]);
});
