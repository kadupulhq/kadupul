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
 * faIcons feeds the multiselect widgets their button icons. A class name the
 * bundled Font Awesome 5 stylesheet does not define draws nothing, which is
 * how the Collapse All and Expand All buttons ended up blank.
 */

'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const root = path.join(__dirname, '..', '..');
const layoutSource = fs.readFileSync(path.join(root, 'include', 'layout.js'), 'utf8');
const faCss = fs.readFileSync(path.join(root, 'include', 'fa', 'css', 'all.css'), 'utf8');

function loadFaIcons() {
	const start = layoutSource.indexOf('var faIcons = {');

	assert.notEqual(start, -1, 'layout.js must declare faIcons');

	const end = layoutSource.indexOf('\n};', start);
	const context = {};

	vm.runInNewContext(layoutSource.slice(start, end + 3), context);

	return context.faIcons;
}

function iconNames(markup) {
	const match = markup.match(/class="([^"]*)"/);

	assert.ok(match, `icon markup has no class attribute: ${markup}`);

	return match[1].split(/\s+/).filter((name) => name.startsWith('fa-'));
}

function glyphDefined(name) {
	return faCss.includes(`.${name}:before`);
}

test('every multiselect icon names a glyph the bundled Font Awesome defines', () => {
	const icons = loadFaIcons();

	assert.ok(Object.keys(icons).length > 0);

	for (const [key, value] of Object.entries(icons)) {
		const names = iconNames(value.icon);

		assert.equal(names.length, 1, `${key} must name exactly one glyph`);
		assert.ok(glyphDefined(names[0]), `${key} uses ${names[0]}, which include/fa/css/all.css does not define`);
	}
});

test('collapse all and expand all use the Font Awesome 5 double angle glyphs', () => {
	const icons = loadFaIcons();

	assert.deepEqual(iconNames(icons.collapseAll.icon), ['fa-angle-double-down']);
	assert.deepEqual(iconNames(icons.expandAll.icon), ['fa-angle-double-right']);
	assert.match(icons.collapseAll.icon, /class="fas /);
	assert.match(icons.expandAll.icon, /class="fas /);
});

test('the glyph check rejects the names Font Awesome never shipped', () => {
	assert.equal(glyphDefined('fa-double-angle-down'), false);
	assert.equal(glyphDefined('fa-double-angle-right'), false);
	assert.equal(glyphDefined('fa-angle-double-down'), true);
});
