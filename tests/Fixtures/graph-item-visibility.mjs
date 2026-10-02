// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';

const source = readFileSync(process.argv[2], 'utf8');
const script = source.match(/function setRowVisibility\(\) \{[\s\S]*?\n\t\}/)?.[0];
if (!script) throw new Error('Missing production row-visibility function');
const results = {};
for (const type of ['1', '4', '5', '6', '20']) {
  const rows = {};
  const writes = {};
  const context = {
    changeColorId() {}, cdefAlignment() {},
    $(selector) {
      const assign = (visible) => {
        rows[selector] = visible;
        writes[selector] = (writes[selector] ?? 0) + 1;
      };
      return { val: () => type, show: () => assign(true),
        hide: () => assign(false), toggle: assign };
    },
  };
  runInNewContext(`${script}\nsetRowVisibility();`, context);
  results[type] = { rows, writes };
}
console.log(JSON.stringify(results));
