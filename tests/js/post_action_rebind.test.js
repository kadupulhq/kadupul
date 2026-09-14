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

const layoutSource = fs.readFileSync(
	path.join(__dirname, '..', '..', 'include', 'layout.js'),
	'utf8'
);

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

/* Elements persist between calls, as links outside a refreshed fragment do
 * when applySkin() runs again after an AJAX update. */
function loadHandleTableNav() {
	const elements = new Map();
	const posts = [];

	function element(selector) {
		if (!elements.has(selector)) {
			const handlers = [];
			const node = {
				handlers,
				find: () => ({ each: () => undefined }),
				data: (key) => (key === 'url' ? 'host.php?action=item_remove&id=3' : undefined),
				attr: () => '#',
				is: () => false,
				on(events, fn) {
					handlers.push({ event: events, fn });
					return node;
				},
				off(events) {
					const [type, namespace] = events.split('.');

					for (let i = handlers.length - 1; i >= 0; i--) {
						const [boundType, boundNamespace] = handlers[i].event.split('.');

						if (boundType === type && (namespace === undefined || boundNamespace === namespace)) {
							handlers.splice(i, 1);
						}
					}

					return node;
				},
				click() {
					const event = { preventDefault: () => undefined };

					handlers
						.filter((handler) => handler.event.split('.')[0] === 'click')
						.forEach((handler) => handler.fn.call(node, event));
				},
			};

			elements.set(selector, node);
		}

		return elements.get(selector);
	}

	const context = {
		$: element,
		loadPage: (url) => posts.push(url),
	};

	vm.runInNewContext(extractFunction('handleTableNav'), context);

	return { context, element, posts };
}

test('repeated handleTableNav calls submit a post action once per click', () => {
	const { context, element, posts } = loadHandleTableNav();

	context.handleTableNav();
	context.handleTableNav();
	context.handleTableNav();

	element('.cactiPostAction').click();

	assert.deepEqual(posts, ['host.php?action=item_remove&id=3']);
});

test('rebinding the post action leaves other click handlers in place', () => {
	const { context, element, posts } = loadHandleTableNav();
	let other = 0;

	element('.cactiPostAction').on('click', () => other++);

	context.handleTableNav();
	context.handleTableNav();

	element('.cactiPostAction').click();

	assert.equal(other, 1);
	assert.equal(posts.length, 1);
});
