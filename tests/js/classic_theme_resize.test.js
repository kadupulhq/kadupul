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
const themeSource = fs.readFileSync(path.join(root, 'include', 'themes', 'classic', 'main.js'), 'utf8');
const layoutSource = fs.readFileSync(path.join(root, 'include', 'layout.js'), 'utf8');

function extractFunction(name) {
	const start = layoutSource.indexOf(`function ${name}(`);

	assert.notEqual(start, -1, `${name}() must exist`);

	const bodyStart = layoutSource.indexOf('{', start);
	let depth = 0;

	for (let offset = bodyStart; offset < layoutSource.length; offset++) {
		if (layoutSource[offset] === '{') {
			depth++;
		} else if (layoutSource[offset] === '}') {
			depth--;

			if (depth === 0) {
				return layoutSource.slice(start, offset + 1);
			}
		}
	}

	throw new Error(`Unable to extract ${name}()`);
}

/* Follows jQuery 3 event naming: types split on whitespace, namespaces
 * follow the first dot, and a type without a namespace matches them all. */
function parseTypes(types) {
	return types.match(/[^\x20\t\r\n\f]+/g).map((entry) => {
		const [type, ...namespaces] = entry.split('.');

		return { type, namespaces };
	});
}

function windowEvents() {
	const handlers = [];

	function off(types) {
		if (types === undefined) {
			handlers.length = 0;
			return;
		}

		for (const wanted of parseTypes(types)) {
			for (let i = handlers.length - 1; i >= 0; i--) {
				const bound = handlers[i];

				if ((wanted.type === '' || wanted.type === bound.type)
					&& wanted.namespaces.every((namespace) => bound.namespaces.includes(namespace))) {
					handlers.splice(i, 1);
				}
			}
		}
	}

	return {
		handlers,
		on: (types, fn) => parseTypes(types).forEach((parsed) => handlers.push({ ...parsed, fn })),
		off,
		count: (type) => handlers.filter((entry) => entry.type === type).length,
		trigger: (type, event) => handlers
			.filter((entry) => entry.type === type)
			.forEach((entry) => entry.fn(event)),
	};
}

/* window keeps its jQuery handlers across AJAX loads while applySkin() runs
 * again after each one. */
function loadClassic() {
	const events = windowEvents();
	const calls = { finalEvents: 0, slideUp: [] };
	const window = {};
	const elements = new Map();

	function element(key) {
		if (key === window) {
			const node = {
				on: (types, fn) => {
					events.on(types, fn);
					return node;
				},
				off: (types) => {
					events.off(types);
					return node;
				},
				resize: (fn) => node.on('resize', fn),
				trigger: (type) => {
					events.trigger(type, { target: {} });
					return node;
				},
				height: () => 100,
			};

			node.unbind = node.off;

			return node;
		}

		if (!elements.has(key)) {
			const node = new Proxy({}, {
				get(target, property) {
					if (property === 'length') {
						return 0;
					}

					if (property === 'is') {
						return () => true;
					}

					if (property === 'hasClass') {
						return () => false;
					}

					if (property === 'attr') {
						return () => '/cacti/graph_view.php';
					}

					if (['prop', 'height', 'width'].includes(property)) {
						return () => 100;
					}

					if (property === 'slideUp') {
						return () => {
							calls.slideUp.push(key);
							return node;
						};
					}

					return () => node;
				},
			});

			elements.set(key, node);
		}

		return elements.get(key);
	}

	const context = {
		$: element,
		basename: (value) => path.posix.basename(value),
		location: {},
		window,
		waitForFinalEvent: () => calls.finalEvents++,
		responsiveUI: () => undefined,
	};

	vm.createContext(context);
	vm.runInContext(extractFunction('keepWindowSize'), context);
	vm.runInContext(extractFunction('setupResponsiveMenuAndTabs'), context);
	vm.runInContext(extractFunction('setupEllipsis'), context);
	vm.runInContext(themeSource, context);

	/* The window bindings applySkin() makes, in its order */
	function applySkin() {
		context.themeReady();
		context.setupResponsiveMenuAndTabs();
		context.keepWindowSize();
	}

	/* The document ready block in layout.js on a mobile browser */
	function ready() {
		applySkin();
		context.setupEllipsis();
		events.on('touchstart', () => undefined);
		events.on('load', () => undefined);
	}

	return { applySkin, calls, events, ready, window };
}

test('classic keeps the window handler count constant across page loads', () => {
	const harness = loadClassic();
	const seen = [];

	harness.ready();

	for (let load = 0; load < 5; load++) {
		harness.applySkin();

		seen.push(['resize', 'orientationchange,', 'fullscreenchange', 'click', 'touchstart', 'load']
			.map((type) => harness.events.count(type))
			.join(' '));
	}

	/* two resize handlers: classic's own and the keepWindowSize() bound
	 * after themeReady(), the same pair 1.2.31 left after unbind() */
	assert.deepEqual(new Set(seen), new Set(['2 1 1 1 1 1']));
	assert.equal(harness.events.handlers.length, 7);
});

test('one resize runs the layout.js resize work once after many loads', () => {
	const harness = loadClassic();

	harness.ready();

	for (let load = 0; load < 5; load++) {
		harness.applySkin();
	}

	const before = harness.calls.finalEvents;

	assert.doesNotThrow(() => harness.events.trigger('resize', { target: {} }));
	assert.equal(harness.calls.finalEvents - before, 1);
});

test('classic keeps the layout.js outside-click handler that closes menus', () => {
	const harness = loadClassic();

	harness.ready();
	harness.applySkin();
	harness.applySkin();

	harness.events.trigger('click', { target: {} });

	assert.deepEqual(harness.calls.slideUp, ['.submenuoptions', '.menuoptions']);
});

test('classic keeps unrelated namespaced window handlers', () => {
	const harness = loadClassic();

	harness.ready();
	harness.events.on('scroll.jqs', () => undefined);
	harness.applySkin();

	assert.equal(harness.events.count('scroll'), 1);
});
