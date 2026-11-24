// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

import assert from 'node:assert/strict';
import test, { after } from 'node:test';

// Node strips the annotations and runs the source the browser build compiles.
// The module binds its listeners on import, so the page exists first.
const selectors = [];
const links = [0, 1].map(() => {
  const listeners = [];
  return { listeners, addEventListener: (type, listener) => listeners.push([type, listener]) };
});
let steps = 0;
globalThis.document = { querySelectorAll: (selector) => (selectors.push(selector), links) };
globalThis.window = { history: { back: () => ++steps } };
after(() => {
  delete globalThis.document;
  delete globalThis.window;
});
await import('../../frontend/permission-denied.ts');

test('each Return link without a target steps back in history instead of following #', () => {
  assert.deepEqual(selectors, ['a.returnTo']);
  assert.equal(steps, 0, 'nothing navigates before a click');
  for (const [index, { listeners }] of links.entries()) {
    assert.equal(listeners.length, 1);
    const [type, listener] = listeners[0];
    assert.equal(type, 'click');
    let prevented = 0;
    listener({ preventDefault: () => ++prevented });
    assert.equal(prevented, 1, `link ${index} keeps the browser on the page`);
    assert.equal(steps, index + 1, `link ${index} steps back once`);
  }
});
