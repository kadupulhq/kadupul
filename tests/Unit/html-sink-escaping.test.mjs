// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createContext, runInContext } from 'node:vm';

const root = new URL('../../', import.meta.url);
const layout = readFileSync(new URL('include/layout.js', root), 'utf8');

// Records every string handed to $() and every value set through .val(),
// which is all the color dropdown needs to show whether a name was parsed
// as markup.
function recordingJquery() {
  const parsed = [];
  const values = [];

  const chain = () => {
    const self = new Proxy({}, {
      get(target, prop) {
        if (prop === 'val') {
          return value => { values.push(value); return self; };
        }
        return () => self;
      },
    });
    return self;
  };

  const $ = arg => {
    if (typeof arg === 'string') parsed.push(arg);
    return chain();
  };
  $.proxy = () => () => {};

  return { $, parsed, values };
}

function dropcolorPrototype($) {
  const start = layout.indexOf("$.widget('custom.dropcolor'");
  assert.ok(start >= 0, 'the dropcolor widget must exist');

  // The widget's regular expressions contain brackets, so the call is cut at
  // the first statement that closes at column zero instead of by counting.
  const end = layout.indexOf('\n});', start);
  assert.ok(end >= 0, 'the dropcolor widget must be closed');

  let prototype;
  $.widget = (name, proto) => { prototype = proto; };
  runInContext(layout.slice(start, end + 4), createContext({ $ }), { filename: 'include/layout.js' });
  return prototype;
}

function createDropcolorInput(name) {
  const jquery = recordingJquery();
  const widget = dropcolorPrototype(jquery.$);
  const selected = { val: () => '12', text: () => name };

  widget._createAutocomplete.call({
    element: { children: () => selected },
    wrapper: jquery.$(),
    _on: () => {},
  });

  return jquery;
}

test('color dropdown sets a decoded color name as the input value, not as markup', () => {
  for (const name of ['Evil" autofocus onfocus=window.pwn=1 x="', '"><img src=x onerror=window.pwn=1>']) {
    const { parsed, values } = createDropcolorInput(name);
    const input = parsed.filter(html => html.startsWith('<input'));

    assert.equal(input.length, 1);
    assert.ok(!input[0].includes(name), 'the color name must not be part of the parsed markup');
    assert.ok(!/\svalue=/.test(input[0]), 'the input markup must not carry a value attribute');
    assert.deepEqual(values, [name]);
  }
});

test('color dropdown keeps an ordinary color name as the input value', () => {
  assert.deepEqual(createDropcolorInput('Red (FF0000)').values, ['Red (FF0000)']);
});
