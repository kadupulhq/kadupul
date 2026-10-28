// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later
const path = require('node:path');
const fs = require('node:fs');
const { test, expect } = require('@playwright/test');

const shimPath = process.env.SCREENFULL_SHIM || path.resolve(__dirname, '../../include/js/screenfull.js');

test.beforeEach(async ({ page }) => {
  await page.goto('/tests/e2e/theme-smoke.html');
  await page.addScriptTag({ path: shimPath });
});

// Records rejections nobody handled, which a legacy fire-and-forget caller would trigger.
async function trackUnhandled(page) {
  await page.evaluate(() => {
    window.unhandled = [];
    window.addEventListener('unhandledrejection', event => window.unhandled.push(String(event.reason)));
  });
}

// Reloads the shim after the fixture removed the native names it should not see.
async function reloadShim(page, setup) {
  await page.evaluate(setup);
  await page.addScriptTag({ path: shimPath });
}

const removeNative = () => {
  for (const name of ['exitFullscreen', 'webkitExitFullscreen', 'mozCancelFullScreen']) {
    delete Document.prototype[name];
    delete document[name];
  }
};

test('exposes the screenfull 3.x API with real values', async ({ page }) => {
  const surface = await page.evaluate(() => {
    const target = document.createElement('div');
    let current = null;
    Object.defineProperty(document, 'fullscreenEnabled', { configurable: true, get: () => true });
    Object.defineProperty(document, 'fullscreenElement', { configurable: true, get: () => current });
    const sf = window.screenfull;
    const before = { enabled: sf.enabled, isFullscreen: sf.isFullscreen, element: sf.element };
    current = target;
    document.dispatchEvent(new Event('fullscreenchange'));
    return {
      methods: ['request', 'exit', 'toggle', 'on', 'off', 'onchange', 'onerror'].map(name => typeof sf[name]),
      before,
      after: { isFullscreen: sf.isFullscreen, sameElement: sf.element === target },
      raw: { ...sf.raw },
    };
  });
  expect(surface.methods).toEqual(Array(7).fill('function'));
  expect(surface.before.enabled).toBe(true);
  expect(surface.before.isFullscreen).toBe(false);
  expect(surface.before.element == null).toBe(true);
  expect(surface.after).toEqual({ isFullscreen: true, sameElement: true });
  expect(surface.raw).toEqual({
    requestFullscreen: 'requestFullscreen', exitFullscreen: 'exitFullscreen', fullscreenElement: 'fullscreenElement',
    fullscreenEnabled: 'fullscreenEnabled', fullscreenchange: 'fullscreenchange', fullscreenerror: 'fullscreenerror',
  });
});

test('request, exit and toggle return promises', async ({ page }) => {
  const result = await page.evaluate(async () => {
    const sf = window.screenfull;
    let fullscreen = null;
    document.documentElement.requestFullscreen = () => { fullscreen = document.documentElement; return Promise.resolve(); };
    Object.defineProperty(document, 'fullscreenElement', { configurable: true, get: () => fullscreen });
    document.exitFullscreen = () => { fullscreen = null; return Promise.resolve(); };
    const request = sf.request();
    const entered = sf.isFullscreen;
    const toggle = sf.toggle();
    const exited = sf.isFullscreen;
    const exit = sf.exit();
    await Promise.all([request, toggle, exit]);
    return {
      promises: [request, toggle, exit].map(value => value instanceof Promise),
      entered, exited,
      idleExit: (sf.exit() instanceof Promise),
    };
  });
  expect(result).toEqual({ promises: [true, true, true], entered: true, exited: false, idleExit: true });
});

test('a denied request rejects for callers that chain and stays quiet for those that do not', async ({ page }) => {
  await trackUnhandled(page);
  const outcome = await page.evaluate(async () => {
    document.documentElement.requestFullscreen = () => Promise.reject(new TypeError('denied'));
    window.screenfull.request();
    let message = 'resolved';
    try {
      await window.screenfull.request();
    } catch (error) {
      message = error.message;
    }
    await new Promise(resolve => setTimeout(resolve, 50));
    return { message, unhandled: window.unhandled };
  });
  expect(outcome).toEqual({ message: 'denied', unhandled: [] });
});

test('a synchronous throw from the native call rejects and leaves no listeners', async ({ page }) => {
  await trackUnhandled(page);
  const outcome = await page.evaluate(async () => {
    let added = 0;
    const add = document.addEventListener;
    document.addEventListener = function (...args) { added++; return add.apply(this, args); };
    document.documentElement.requestFullscreen = () => { throw new TypeError('sync'); };
    window.screenfull.request();
    let message = 'resolved';
    try {
      await window.screenfull.request();
    } catch (error) {
      message = error.message;
    }
    await new Promise(resolve => setTimeout(resolve, 50));
    return { message, added, unhandled: window.unhandled };
  });
  expect(outcome).toEqual({ message: 'sync', added: 0, unhandled: [] });
});

test('a native call that returns nothing settles on fullscreenchange', async ({ page }) => {
  const outcome = await page.evaluate(async () => {
    const removed = [];
    const remove = document.removeEventListener;
    document.removeEventListener = function (name, ...rest) { removed.push(name); return remove.call(this, name, ...rest); };
    document.documentElement.requestFullscreen = () => undefined;
    let settled = false;
    const pending = window.screenfull.request().then(() => { settled = true; });
    await Promise.resolve();
    const early = settled;
    document.dispatchEvent(new Event('fullscreenchange'));
    await pending;
    return { early, settled, removed: removed.sort() };
  });
  expect(outcome).toEqual({ early: false, settled: true, removed: ['fullscreenchange', 'fullscreenerror'] });
});

test('a native call that returns nothing rejects with an Error on fullscreenerror', async ({ page }) => {
  await trackUnhandled(page);
  const outcome = await page.evaluate(async () => {
    const removed = [];
    const remove = document.removeEventListener;
    document.removeEventListener = function (name, ...rest) { removed.push(name); return remove.call(this, name, ...rest); };
    document.documentElement.requestFullscreen = () => undefined;
    window.screenfull.request();
    const chained = window.screenfull.request();
    document.dispatchEvent(new Event('fullscreenerror'));
    let reason = null;
    try {
      await chained;
    } catch (error) {
      reason = { isError: error instanceof Error, isEvent: error instanceof Event };
    }
    await new Promise(resolve => setTimeout(resolve, 50));
    return { reason, removed: removed.sort(), unhandled: window.unhandled };
  });
  expect(outcome.reason).toEqual({ isError: true, isEvent: false });
  expect(outcome.removed).toEqual(Array(2).fill(['fullscreenchange', 'fullscreenerror']).flat().sort());
  expect(outcome.unhandled).toEqual([]);
});

test('without any fullscreen API the global is false', async ({ page }) => {
  await reloadShim(page, removeNative);
  expect(await page.evaluate(() => window.screenfull)).toBe(false);
});

test('a Moz-prefixed browser routes through the moz names', async ({ page }) => {
  await reloadShim(page, () => {
    removeNativeNames();
    function removeNativeNames() {
      for (const name of ['exitFullscreen', 'webkitExitFullscreen', 'mozCancelFullScreen']) {
        delete Document.prototype[name];
        delete document[name];
      }
    }
    let current = null;
    window.mozCalls = [];
    document.mozCancelFullScreen = () => { window.mozCalls.push('cancel'); current = null; };
    Object.defineProperty(document, 'mozFullScreenElement', { configurable: true, get: () => current });
    Object.defineProperty(document, 'mozFullScreenEnabled', { configurable: true, get: () => true });
    document.documentElement.mozRequestFullScreen = () => { window.mozCalls.push('request'); current = document.documentElement; };
  });
  const outcome = await page.evaluate(async () => {
    const sf = window.screenfull;
    const raw = { ...sf.raw };
    const enabled = sf.enabled;
    const requested = sf.request();
    document.dispatchEvent(new Event('mozfullscreenchange'));
    await requested;
    const isFullscreen = sf.isFullscreen;
    const exited = sf.exit();
    document.dispatchEvent(new Event('mozfullscreenchange'));
    await exited;
    return { raw, enabled, isFullscreen, after: sf.isFullscreen, calls: window.mozCalls };
  });
  expect(outcome.raw.requestFullscreen).toBe('mozRequestFullScreen');
  expect(outcome.raw.fullscreenchange).toBe('mozfullscreenchange');
  expect(outcome).toMatchObject({ enabled: true, isFullscreen: true, after: false, calls: ['request', 'cancel'] });
});

test('onchange, onerror, on and off follow the fullscreen events', async ({ page }) => {
  const calls = await page.evaluate(() => {
    const log = [];
    const sf = window.screenfull;
    const change = () => log.push('onchange');
    const error = () => log.push('onerror');
    const extra = () => log.push('on');
    sf.onchange(change);
    sf.onerror(error);
    sf.on('change', extra);
    sf.off('change', extra);
    document.dispatchEvent(new Event(sf.raw.fullscreenchange));
    document.dispatchEvent(new Event(sf.raw.fullscreenerror));
    return log;
  });
  expect(calls).toEqual(['onchange', 'onerror']);
});

test('layout.js double tap reaches the native request without throwing', async ({ page }) => {
  const errors = [];
  page.on('pageerror', error => errors.push(error.message));
  const layout = fs.readFileSync(path.resolve(__dirname, '../../include/layout.js'), 'utf8');
  const block = layout.match(/if \(isMobile\.any\(\) != null\) \{\s*\$\(window\)\.on\('touchstart'[\s\S]*?\n\t\}\n/);
  expect(block).not.toBeNull();
  await page.evaluate(() => {
    window.isMobile = { any: () => 'Android' };
    window.tapped = null;
    window.nativeRequests = 0;
    document.documentElement.requestFullscreen = () => { window.nativeRequests++; return Promise.resolve(); };
    Object.defineProperty(document, 'fullscreenEnabled', { configurable: true, get: () => true });
  });
  await page.addScriptTag({ content: block[0] });
  await page.evaluate(() => {
    window.dispatchEvent(new Event('touchstart'));
    window.dispatchEvent(new Event('touchstart'));
  });
  expect(await page.evaluate(() => window.nativeRequests)).toBe(1);
  expect(errors).toEqual([]);
});
