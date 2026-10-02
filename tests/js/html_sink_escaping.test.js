/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2026 The Kadupul project and contributors                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const root = path.join(__dirname, '..', '..');
const layoutSource = fs.readFileSync(path.join(root, 'include', 'layout.js'), 'utf8');

// Records every string handed to $() and every value set through .val(),
// which is all the color dropdown needs to show whether a name was parsed
// as markup.
function recordingJquery() {
	const parsed = [];
	const values = [];

	function chain() {
		const self = new Proxy({}, {
			get(target, prop) {
				if (prop === 'val') {
					return (value) => {
						values.push(value);
						return self;
					};
				}

				return () => self;
			}
		});

		return self;
	}

	function $(arg) {
		if (typeof arg === 'string') {
			parsed.push(arg);
		}

		return chain();
	}

	$.proxy = () => () => {};

	return { $, parsed, values };
}

function dropcolorPrototype($) {
	const start = layoutSource.indexOf("$.widget('custom.dropcolor'");

	assert.notEqual(start, -1, 'the dropcolor widget must exist');

	let prototype;
	$.widget = (name, proto) => {
		prototype = proto;
	};

	// The widget's regular expressions contain brackets, so the call is cut at
	// the first statement that closes at column zero instead of by counting.
	const end = layoutSource.indexOf('\n});', start);

	assert.notEqual(end, -1, 'the dropcolor widget must be closed');

	vm.runInContext(layoutSource.slice(start, end + 4), vm.createContext({ $ }), { filename: 'include/layout.js' });

	return prototype;
}

function createDropcolorInput(name) {
	const jquery = recordingJquery();
	const widget = dropcolorPrototype(jquery.$);
	const selected = { val: () => '12', text: () => name };

	widget._createAutocomplete.call({
		element: { children: () => selected },
		wrapper: jquery.$(),
		_on: () => {}
	});

	return jquery;
}

test('color dropdown sets a decoded color name as the input value, not as markup', () => {
	const names = [
		'Evil" autofocus onfocus=window.pwn=1 x="',
		'"><img src=x onerror=window.pwn=1>'
	];

	for (const name of names) {
		const { parsed, values } = createDropcolorInput(name);
		const input = parsed.filter(html => html.startsWith('<input'));

		assert.equal(input.length, 1);
		assert.ok(!input[0].includes(name), 'the color name must not be part of the parsed markup');
		assert.ok(!/\svalue=/.test(input[0]), 'the input markup must not carry a value attribute');
		assert.deepEqual(values, [name]);
	}
});

test('color dropdown keeps an ordinary color name as the input value', () => {
	const { values } = createDropcolorInput('Red (FF0000)');

	assert.deepEqual(values, ['Red (FF0000)']);
});
