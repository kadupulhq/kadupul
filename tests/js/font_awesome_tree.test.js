/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2026 The Kadupul project and contributors                 |
 |                                                                         |
 | This program is free software; you can redistribute it and/or           |
 | modify it under the terms of the GNU General Public License             |
 | as published by the Free Software Foundation; either version 2          |
 | of the License, or (at your option) any later version.                  |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * sync.mjs only checks the files the pinned package contains, so files left
 * behind by an older Font Awesome release never fail it. Hashing the tree on
 * disk the same way sync.mjs hashes the package catches those leftovers.
 */

'use strict';

const assert = require('node:assert/strict');
const crypto = require('node:crypto');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const test = require('node:test');

const root = path.join(__dirname, '..', '..');
const manifest = JSON.parse(fs.readFileSync(path.join(root, 'tools', 'dependencies', 'assets.json'), 'utf8'));
const fontAwesome = manifest.find((entry) => entry.file === 'include/fa');

function vendorFiles(base) {
	const files = [];

	function walk(directory) {
		for (const item of fs.readdirSync(path.join(base, directory), { withFileTypes: true })) {
			const child = directory === '' ? item.name : `${directory}/${item.name}`;

			if (item.isDirectory()) {
				walk(child);
			} else if (item.name !== 'index.php') {
				files.push(child);
			}
		}
	}

	walk('');

	return files.sort((left, right) => (left < right ? -1 : Number(left > right)));
}

function treeHash(base, files) {
	const hash = crypto.createHash('sha256');

	for (const file of files) {
		const digest = crypto.createHash('sha256').update(fs.readFileSync(path.join(base, file))).digest('hex');

		hash.update(`${file}\0${digest}\n`);
	}

	return hash.digest('hex');
}

function withTree(files, run) {
	const base = fs.mkdtempSync(path.join(os.tmpdir(), 'fa-tree-'));

	try {
		for (const [file, contents] of Object.entries(files)) {
			fs.mkdirSync(path.dirname(path.join(base, file)), { recursive: true });
			fs.writeFileSync(path.join(base, file), contents);
		}

		run(base);
	} finally {
		fs.rmSync(base, { recursive: true, force: true });
	}
}

test('include/fa holds exactly the pinned Font Awesome package', () => {
	const base = path.join(root, 'include', 'fa');
	const files = vendorFiles(base);

	assert.ok(fontAwesome, 'assets.json must pin include/fa');
	assert.equal(files.length, fontAwesome.count, 'include/fa has files the pinned package does not');
	assert.equal(treeHash(base, files), fontAwesome.treeSha256);
});

test('index.php guards are not counted as package files', () => {
	withTree({ 'index.php': '<?php', 'css/index.php': '<?php', 'css/all.css': 'a' }, (base) => {
		assert.deepEqual(vendorFiles(base), ['css/all.css']);
	});
});

test('a leftover file from an older release changes the tree hash', () => {
	withTree({ 'css/all.css': 'a', 'less/solid.less': 'b' }, (clean) => {
		withTree({ 'css/all.css': 'a', 'less/solid.less': 'b', 'less/fa-solid.less': 'c' }, (stale) => {
			assert.notEqual(treeHash(stale, vendorFiles(stale)), treeHash(clean, vendorFiles(clean)));
		});
	});
});
