// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

// Compares two theme-contrast.spec.ts runs, for example main against a
// branch, and lists what the second run breaks that the first did not.
//
//   node theme-contrast/compare.js main current [--list]

'use strict';

const fs = require('fs');
const path = require('path');

const [base, head] = process.argv.slice(2, 4);
const list = process.argv.includes('--list');
if (!base || !head) {
	console.error('usage: node theme-contrast/compare.js <base-label> <head-label> [--list]');
	process.exit(2);
}

const dir = path.join(__dirname, '../theme-contrast-results');

function load(label) {
	const runs = {};
	const prefix = `theme-contrast-${label}-`;
	for (const file of fs.readdirSync(dir)) {
		if (file.startsWith(prefix) && file.endsWith('.json')) {
			runs[file.slice(prefix.length, -5)] = JSON.parse(fs.readFileSync(path.join(dir, file), 'utf8'));
		}
	}
	return runs;
}

const failing = (f) => !f.disabled && !f.image && f.ratio + 0.005 < f.required;
const id = (f) => [f.page, f.state, f.kind, f.key, f.text].join('|');
const describe = (f) => `${f.page} [${f.state}] ${f.kind} ${f.key} "${f.text}" ${f.fg} on ${f.bg} = ${f.ratio} < ${f.required}`;

const before = load(base);
const after = load(head);
let regressions = 0;

// A theme missing from either run is an incomplete measurement, not a clean one.
const themes = Object.keys(before).sort();
if (themes.length === 0 || themes.join() !== Object.keys(after).sort().join()) {
	console.error(`${base} measured [${themes.join(', ')}] but ${head} measured [${Object.keys(after).sort().join(', ')}]`);
	process.exit(2);
}

for (const theme of themes) {
	const was = new Map((before[theme] || []).map((f) => [id(f), f]));
	const now = after[theme] || [];
	const failed = now.filter(failing);
	const broken = failed.filter((f) => was.has(id(f)) && !failing(was.get(id(f))));
	regressions += broken.length;
	console.log(`${theme}: ${(before[theme] || []).filter(failing).length} -> ${failed.length} failures, ${broken.length} new`);
	for (const f of list ? failed : broken) {
		console.log('  ' + (broken.includes(f) ? 'NEW ' : '') + describe(f));
	}
}

process.exit(regressions ? 1 : 0);
