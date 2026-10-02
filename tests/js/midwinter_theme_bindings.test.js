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
const themeSource = fs.readFileSync(path.join(root, 'include', 'themes', 'midwinter', 'main.js'), 'utf8');
const hotkeysPath = 'include/themes/midwinter/vendor/hotkeys/hotkeys.js';
const hotkeysSource = fs.readFileSync(path.join(root, hotkeysPath), 'utf8');

function listenerStore() {
	const listeners = [];

	return {
		listeners,
		addEventListener: (type, fn) => listeners.push({ type, fn }),
		removeEventListener: (type, fn) => {
			const index = listeners.findIndex((entry) => entry.type === type && entry.fn === fn);

			if (index !== -1) {
				listeners.splice(index, 1);
			}
		},
		count: (type) => listeners.filter((entry) => entry.type === type).length,
		fire: (type, event) => listeners
			.filter((entry) => entry.type === type)
			.forEach((entry) => entry.fn(event)),
	};
}

/* Elements persist between calls, as the console menu and document do when
 * applySkin() runs themeReady() again after an AJAX update. */
function loadMidwinter(options = {}) {
	const elements = new Map();
	const calls = { alerts: [], pages: [], requested: 0, exited: 0, graphs: 0, scripts: [] };
	const storage = new Map([['midWinter_Color_Mode_Auto', 'on']]);
	const cookies = new Map();
	const documentListeners = listenerStore();
	const mediaListeners = listenerStore();
	const attributes = new Map();
	let fullscreenElement = null;

	function element(selector) {
		if (typeof selector !== 'string') {
			return element('#unknown');
		}

		if (!elements.has(selector)) {
			const handlers = [];
			const node = new Proxy({ handlers }, {
				get(target, property) {
					if (property in target) {
						return target[property];
					}

					if (property === 'on') {
						return (events, fn) => {
							handlers.push({ event: events, fn });
							return node;
						};
					}

					if (property === 'off') {
						return (events) => {
							for (let i = handlers.length - 1; i >= 0; i--) {
								const [boundType, boundNamespace] = handlers[i].event.split('.');
								const [type, namespace] = (events || '').split('.');

								if (events === undefined || (boundType === type && (namespace === undefined || boundNamespace === namespace))) {
									handlers.splice(i, 1);
								}
							}

							return node;
						};
					}

					if (property === 'length') {
						return 0;
					}

					return () => node;
				},
			});

			elements.set(selector, node);
		}

		return elements.get(selector);
	}

	const jquery = (selector) => element(selector);
	jquery.extend = (target, source) => Object.assign(target, source);
	jquery.cookie = (name, value) => {
		if (value === undefined) {
			return cookies.get(name);
		}

		cookies.set(name, value);
	};
	jquery.ajax = (request) => {
		calls.scripts.push(request.url);
		const ok = options.scriptFails !== true;

		/* $.ajax with dataType script evaluates the body on every call */
		if (ok && request.url.endsWith(hotkeysPath)) {
			vm.runInContext(hotkeysSource, context);
		}

		const promise = {
			done(fn) {
				if (ok) {
					fn('', options.scriptStatus || 'success');
				}

				return promise;
			},
			fail(fn) {
				if (!ok) {
					fn();
				}

				return promise;
			},
		};

		return promise;
	};

	const document = {
		...documentListeners,
		get fullscreenElement() {
			return fullscreenElement;
		},
		documentElement: {
			getAttribute: (name) => attributes.get(name),
			setAttribute: (name, value) => attributes.set(name, value),
			requestFullscreen: () => {
				calls.requested++;
				return Promise.resolve();
			},
		},
		exitFullscreen: () => {
			calls.exited++;
			return Promise.resolve();
		},
		getElementById: () => null,
	};

	const context = {
		$: jquery,
		jQuery: jquery,
		Storages: {
			localStorage: {
				get: (key) => storage.get(key),
				set: (key, value) => storage.set(key, value),
				isSet: (key) => storage.has(key),
			},
		},
		alert: (value) => calls.alerts.push(value),
		console,
		document,
		initializeGraphs: () => calls.graphs++,
		loadPage: (url) => calls.pages.push(url),
		matchMedia: () => ({ matches: options.systemDark === true, ...mediaListeners }),
		location: { protocol: 'https:' },
		urlPath: '/cacti/',
		addEventListener: () => undefined,
	};
	context.window = context;

	vm.createContext(context);
	vm.runInContext(themeSource, context);

	return {
		calls,
		context,
		documentListeners,
		element,
		mediaListeners,
		storage,
		attributes,
		setFullscreen: (value) => {
			fullscreenElement = value;
		},
	};
}

function keyEvent(type, keyCode) {
	const target = { tagName: 'BODY', isContentEditable: false, readOnly: false };

	return {
		type,
		keyCode,
		which: keyCode,
		target,
		srcElement: target,
		shiftKey: false,
		ctrlKey: false,
		altKey: false,
		metaKey: false,
		getModifierState: () => false,
		preventDefault: () => undefined,
		stopPropagation: () => undefined,
	};
}

function press(harness, ...keyCodes) {
	keyCodes.forEach((code) => harness.documentListeners.fire('keydown', keyEvent('keydown', code)));
	keyCodes.reverse().forEach((code) => harness.documentListeners.fire('keyup', keyEvent('keyup', code)));
}

test('repeated setHotKeys calls leave one hotkeys keydown listener', () => {
	const harness = loadMidwinter();

	harness.context.setHotKeys();
	harness.context.setHotKeys();
	harness.context.setHotKeys();

	assert.equal(harness.documentListeners.count('keydown'), 1);
	assert.equal(harness.calls.scripts.filter((url) => url.endsWith(hotkeysPath)).length, 1);
});

test('one shortcut press after several page loads loads its page once', () => {
	const harness = loadMidwinter();

	harness.context.setHotKeys();
	harness.context.setHotKeys();
	harness.context.setHotKeys();
	press(harness, 67, 84);

	assert.deepEqual(harness.calls.pages, ['/cacti/graph_view.php?action=tree']);
});

test('a failed hotkeys load is retried on the next page load', () => {
	const harness = loadMidwinter({ scriptFails: true });

	harness.context.setHotKeys();
	harness.context.setHotKeys();

	assert.equal(harness.calls.scripts.length, 2);
	assert.equal(harness.documentListeners.count('keydown'), 0);
});

test('ESC outside fullscreen neither throws nor requests fullscreen', () => {
	const harness = loadMidwinter();

	harness.context.setHotKeys();

	assert.doesNotThrow(() => press(harness, 27));
	assert.equal(harness.calls.requested, 0);
	assert.equal(harness.calls.exited, 0);
});

test('ESC in fullscreen leaves fullscreen', () => {
	const harness = loadMidwinter();

	harness.context.setHotKeys();
	harness.setFullscreen({});
	press(harness, 27);

	assert.equal(harness.calls.exited, 1);
	assert.equal(harness.calls.requested, 0);
});

test('c+F1 no longer opens an alert', () => {
	const harness = loadMidwinter();

	harness.context.setHotKeys();
	press(harness, 67, 112);

	assert.deepEqual(harness.calls.alerts, []);
});

test('repeated themeReady calls add one fullscreen dblclick listener', () => {
	const harness = loadMidwinter();
	const noop = () => undefined;

	['initStorageItem', 'setThemeColor', 'setupTheme', 'setupTree', 'setupDefaultElements',
		'setMenuVisibility', 'setHotKeys', 'ajaxAnchors', 'extendAnchorActions',
		'searchToHighlight', 'updateNavigation', 'themeLoader'].forEach((name) => {
		harness.context[name] = noop;
	});
	harness.context.midwinterInitialized = () => true;

	harness.context.themeReady();
	harness.context.themeReady();
	harness.context.themeReady();

	assert.equal(harness.documentListeners.count('dblclick'), 1);

	harness.documentListeners.fire('dblclick', {});
	assert.equal(harness.calls.requested, 1);
});

test('menu and keyword bindings do not stack, and unrelated handlers survive', () => {
	const harness = loadMidwinter();
	const keyword = harness.element("input[name='keyword']");
	const menuItems = harness.element('a[role="menuitem"]');

	keyword.on('input', () => undefined);

	harness.context.searchToHighlight();
	harness.context.searchToHighlight();
	harness.context.extendAnchorActions();
	harness.context.extendAnchorActions();

	assert.equal(keyword.handlers.filter((entry) => entry.event === 'input.midwinter').length, 1);
	assert.equal(keyword.handlers.filter((entry) => entry.event === 'input').length, 1);
	assert.equal(menuItems.handlers.filter((entry) => entry.event === 'click.midwinter').length, 1);
});

test('the system colour listener is added once', () => {
	const harness = loadMidwinter();

	harness.context.setThemeColor();
	harness.context.setThemeColor();
	harness.context.setThemeColor();

	assert.equal(harness.mediaListeners.count('change'), 1);
});

test('a system colour change follows the OS only while auto mode is on', () => {
	const harness = loadMidwinter();

	harness.context.setThemeColor();
	const graphsAfterSetup = harness.calls.graphs;

	harness.storage.set('midWinter_Color_Mode_Auto', 'off');
	harness.mediaListeners.fire('change', { matches: true });

	assert.equal(harness.attributes.get('data-theme-color'), 'light');
	assert.equal(harness.calls.graphs, graphsAfterSetup);

	harness.storage.set('midWinter_Color_Mode_Auto', 'on');
	harness.mediaListeners.fire('change', { matches: true });

	assert.equal(harness.attributes.get('data-theme-color'), 'dark');
	assert.equal(harness.calls.graphs, graphsAfterSetup + 1);
});


test('cache revalidation binds hotkeys once and preserves one shortcut action', () => {
	const harness = loadMidwinter({ scriptStatus: 'notmodified' });
	harness.context.setHotKeys();
	harness.context.setHotKeys();
	harness.context.setHotKeys();
	assert.equal(harness.documentListeners.count('keydown'), 1);
	assert.equal(harness.calls.scripts.filter((url) => url.endsWith(hotkeysPath)).length, 1);
	press(harness, 67, 84);
	assert.deepEqual(harness.calls.pages, ['/cacti/graph_view.php?action=tree']);
});
