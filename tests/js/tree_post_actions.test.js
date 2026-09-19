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

'use strict';

/* Runs the tree.php page scripts against a recording jQuery stub and checks
 * that every state-changing request is a POST that carries the csrf token. */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const source = fs.readFileSync(process.env.TREE_PHP || path.join(__dirname, '..', '..', 'tree.php'), 'utf8');
const TOKEN = 'sid:token,1';

function scripts() {
	const out = [];
	const re = /<script type='text\/javascript' <\?php print CactiSecureHeaders::getNonceAttribute\(\);\s*\?>>([\s\S]*?)<\/script>/g;
	let m;
	while ((m = re.exec(source)) !== null) {
		out.push(m[1].replace(/<\?php[\s\S]*?\?>/g, ''));
	}
	return out;
}

function harness(attrs) {
	const requests = [];
	const handlers = {};
	const ready = [];
	const chain = { done: () => chain, fail: () => chain, always: () => chain };

	const elements = new WeakSet();
	const element = (selector) => {
		const el = new Proxy(function () {}, {
			get(target, prop) {
				if (prop === 'on' || prop === 'bind') {
					return (event, fn) => {
						handlers[selector + ' ' + event] = fn;
						return el;
					};
				}
				if (prop === 'attr') {
					return (name) => (attrs[selector] || {})[name];
				}
				if (prop === 'length') {
					return 1;
				}
				if (prop === 'val') {
					return () => '7';
				}
				if (prop === 'tableDnD') {
					return (options) => {
						handlers[selector + ' drop'] = options.onDrop;
						return el;
					};
				}
				if (prop === 'jstree') {
					return (arg) => (arg === 'instance' ? false : el);
				}
				if (prop === 'offset') {
					return () => ({ top: 0 });
				}
				return () => el;
			},
		});
		elements.add(el);
		return el;
	};

	const $ = (arg) => {
		if (elements.has(arg)) {
			return arg;
		}
		if (typeof arg === 'function') {
			ready.push(arg);
			return undefined;
		}
		return element(arg);
	};
	$.get = (url, data) => {
		requests.push({ type: 'GET', url, data });
		return chain;
	};
	$.post = (url, data) => {
		requests.push({ type: 'POST', url, data });
		return chain;
	};
	$.tableDnD = { serialize: () => 'tree_ids[]=line3&tree_ids[]=line2' };
	$.jstree = { reference: () => element('#ctree') };

	const context = vm.createContext({
		$,
		csrfMagicToken: TOKEN,
		reset: false,
		loadPageNoHeader: (url) => requests.push({ type: 'GET', url }),
		loadPageUsingPost: (url, data) => requests.push({ type: 'POST', url, data }),
		getPresentHTTPError: () => undefined,
		applySkin: () => undefined,
		DOMPurify: { sanitize: (s) => s },
		window: {},
		document: {},
		setTimeout: () => 0,
		clearTimeout: () => undefined,
		encodeURIComponent,
	});

	return { requests, handlers, ready, context };
}

function carriesToken(request) {
	if (typeof request.data === 'string') {
		return request.data.includes('__csrf_magic=' + encodeURIComponent(TOKEN));
	}
	return request.data !== undefined && request.data.__csrf_magic === TOKEN;
}

function assertPosted(request, action) {
	assert.equal(request.type, 'POST', action + ' must be sent by POST: ' + JSON.stringify(request));
	assert.ok(carriesToken(request), action + ' must carry the csrf token');
	const inUrl = request.url.includes('action=' + action);
	const inBody = typeof request.data === 'object' && request.data.action === action;
	assert.ok(inUrl || inBody, action + ' must name the action');
}

test('tree editor sends node and branch mutations by POST with the token', () => {
	const [editor] = scripts().filter((s) => s.includes('function drawTree()'));
	assert.ok(editor, 'editor script not found');

	const h = harness({});
	vm.runInContext(editor + '\neditable = true; drawTree();', h.context);

	const node = { id: 'tbranch:5', parent: 'tbranch:4', text: 'Leaf' };
	const instance = { get_state: () => ({}), load_node: () => undefined, get_parent: () => 'tbranch:4', set_id() {}, set_text() {}, edit() {} };
	const events = {
		delete_node: { node, instance },
		create_node: { node, instance, position: 0 },
		rename_node: { node, instance, text: 'Renamed' },
		move_node: { node, instance, parent: 'tbranch:4', position: 1 },
		copy_node: { node, instance, original: { id: 'thost:9' }, parent: 'tbranch:4', position: 1 },
	};

	for (const [action, data] of Object.entries(events)) {
		const handler = h.handlers['#ctree ' + action + '.jstree'];
		assert.equal(typeof handler, 'function', action + ' handler');
		h.requests.length = 0;
		handler({}, data);
		assert.equal(h.requests.length, 1, action);
		assertPosted(h.requests[0], action);
	}

	for (const [fn, action] of [['setBranchSortOrder', 'set_branch_sort'], ['setHostSortOrder', 'set_host_sort']]) {
		h.requests.length = 0;
		vm.runInContext(fn + "('alpha', 'tbranch:5');", h.context);
		assert.equal(h.requests.length, 1, fn);
		assertPosted(h.requests[0], action);
		assert.equal(h.requests[0].data.nodeid, 'tbranch:5');
		assert.equal(h.requests[0].data.type, 'alpha');
	}
});

test('tree editor lock and unlock stay on POST with the token', () => {
	const [editor] = scripts().filter((s) => s.includes('function drawTree()'));
	const h = harness({});
	vm.runInContext(editor + '\ninitializeTreeEdit();', h.context);

	for (const action of ['lock', 'unlock']) {
		h.requests.length = 0;
		h.handlers['#' + action + ' click']();
		assertPosted(h.requests[0], action);
	}
});

test('tree list sorts, arrows and drag and drop post with the token', () => {
	const list = scripts().filter((s) => s.includes('clearFilter') || s.includes('tableDnD'));
	const h = harness({ '#sorta': { id: 'sorta' }, '#sortd': { id: 'sortd' } });
	for (const s of list) {
		vm.runInContext(s, h.context);
	}
	h.ready.forEach((fn) => fn());

	const click = (key, self) => {
		const handler = h.handlers[key];
		assert.equal(typeof handler, 'function', key);
		h.requests.length = 0;
		handler.call(h.context.$(self), { preventDefault() {}, stopPropagation() {} });
		assert.equal(h.requests.length, 1, key);
		return h.requests[0];
	};

	/* The sort buttons are links; before the fix no handler exists and the
	   link itself is followed by GET. */
	assert.ok(!/href'\s*=>\s*'tree\.php\?action=sort(asc|desc)'/.test(source), 'sort buttons must not link by GET');
	assertPosted(click('#sorta, #sortd click', '#sorta'), 'sortasc');
	assertPosted(click('#sorta, #sortd click', '#sortd'), 'sortdesc');

	/* layout.js posts a cactiPostAction link with the token. */
	assert.equal((source.match(/moveArrow cactiPostAction" href="#" data-url="' \. html_escape\('tree\.php\?action=tree_(up|down)&id=/g) || []).length, 4);
	assert.ok(!/href="' \. (html_escape|htmlspecialchars)\('tree\.php\?action=tree_(up|down)/.test(source), 'reorder arrows must not link by GET');

	h.requests.length = 0;
	h.handlers['#tree_ids drop']();
	assertPosted(h.requests[0], 'ajax_dnd');
	assert.ok(h.requests[0].data.includes('tree_ids[]=line3'));
});
