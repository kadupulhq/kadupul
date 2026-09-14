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

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const root = path.join(__dirname, '..', '..');
const layoutSource = fs.readFileSync(path.join(root, 'include', 'layout.js'), 'utf8');

function extractFunction(source, name) {
	const start = source.indexOf(`function ${name}(`);
	assert.notEqual(start, -1, `${name}() must exist`);

	const bodyStart = source.indexOf('{', start);
	let depth = 0;

	for (let offset = bodyStart; offset < source.length; offset++) {
		if (source[offset] === '{') {
			depth++;
		} else if (source[offset] === '}' && --depth === 0) {
			return source.slice(start, offset + 1);
		}
	}

	throw new Error(`Unable to extract ${name}()`);
}

/* 1.2.31 sent these links through loadPage() and replaced #main in place.
 * The handlers answer with a redirect to a header=false page, so a full form
 * submit leaves the console without its header and menu. */
function loadLayout(options = {}) {
	const pageUrl = 'https://cacti.example/cacti/cdef.php?action=edit&id=4';
	const calls = {get: [], post: [], main: [], pushState: [], redirects: [], errors: [], submit: [], formStatus: []};
	const handlers = {};
	const requests = [];

	function node(selector) {
		const proxy = new Proxy(function () {}, {
			get(target, prop) {
				if (prop === 'length') {
					return 0;
				}

				if (prop === 'html') {
					return (value) => {
						if (value === undefined) {
							return undefined;
						}

						if (selector === '#main') {
							calls.main.push(value);
						}

						return proxy;
					};
				}

				if (prop === 'on') {
					return (events, fn) => {
						handlers[selector] = fn;
						return proxy;
					};
				}

				return () => proxy;
			},
		});

		return proxy;
	}

	function $(selector) {
		if (selector && selector.isElement) {
			return selector;
		}

		return node(typeof selector === 'string' ? selector : null);
	}

	function request(kind) {
		return (...args) => {
			calls[kind].push(args);

			const deferred = {
				done(fn) {
					deferred.onDone = fn;
					return deferred;
				},
				fail(fn) {
					deferred.onFail = fn;
					return deferred;
				},
			};

			requests.push(deferred);

			return deferred;
		};
	}

	$.ajaxQ = {abortAll: () => undefined};
	$.get = request('get');
	$.post = request('post');
	$.extend = (target, sourceValue) => Object.assign(target, sourceValue);
	$.param = (fields) => new URLSearchParams(fields.map(({name, value}) => [name, value])).toString();

	const context = {
		$,
		URL,
		URLSearchParams,
		csrfMagicToken: 'trusted-token',
		window: {location: {href: pageUrl, origin: 'https://cacti.example'}, scrollTo: () => undefined},
		document: {location: {href: pageUrl}},
		statePushed: false,
		cont: false,
		myTitle: '',
		myHref: '',
		thref: '',
		pageName: '',
		checkFormStatus: (href, type) => {
			calls.formStatus.push(type);
			return options.formChanged !== true;
		},
		closeDateFilters: () => undefined,
		clearAllTimeouts: () => undefined,
		checkForRedirects: (html, href) => calls.redirects.push(href),
		cleanHeader: (href) => href,
		pushState: (title, href) => calls.pushState.push(href),
		stripHeaderSuppression: (href) => href,
		basename: (value) => value.split('/').pop(),
		escapeString: (value) => value,
		applySkin: () => undefined,
		isMobile: {any: () => null},
		handleConsole: () => undefined,
		Pace: {stop: () => undefined},
		DOMPurify: {sanitize: (value) => value},
		getPresentHTTPErrorOrRedirect: (data, url) => calls.errors.push(url),
		submitPageUsingPost: (url) => calls.submit.push(url),
		cactiReturnTo: () => undefined,
		selectAll: () => undefined,
		selectAllRealms: () => undefined,
	};

	vm.runInNewContext([
		'cactiPreparePostRequest',
		'cactiPreparePostRequestFromUrl',
		'loadPage',
		'handleTableNav',
	].map((name) => extractFunction(layoutSource, name)).join('\n'), context);

	return {context, calls, handlers, requests};
}

function element(url) {
	return {
		isElement: true,
		data: (key) => (key === 'url' ? url : undefined),
		attr: () => '#',
		hasClass: () => false,
	};
}

test('a post action link replaces #main in place over a token-bearing POST', () => {
	const {context, calls, handlers, requests} = loadLayout();

	context.handleTableNav();

	const link = element('cdef.php?action=item_moveup&id=7&cdef_id=4');
	handlers['.cactiPostAction'].call(link, {preventDefault: () => undefined});

	assert.deepEqual(calls.submit, []);
	assert.equal(calls.get.length, 0);
	assert.equal(calls.post.length, 1);

	const [url, data] = calls.post[0];
	const fields = new URLSearchParams(data);

	assert.equal(url, '/cacti/cdef.php');
	assert.equal(fields.get('action'), 'item_moveup');
	assert.equal(fields.get('id'), '7');
	assert.equal(fields.get('__csrf_magic'), 'trusted-token');

	requests[0].onDone('<div>cdef items</div>');

	assert.deepEqual(calls.main, ['<div>cdef items</div>']);
});

test('a post action neither pushes nor redirects to its own action URL', () => {
	const {context, calls, requests} = loadLayout();

	context.loadPage('cdef.php?action=item_remove&id=7&cdef_id=4', false, true);
	requests[0].onDone('<div>cdef items</div>');
	requests[0].onFail({status: 0, statusText: 'error'});

	/* A reload or redirect to the action URL would repeat it by GET. */
	assert.deepEqual(calls.pushState, []);
	assert.deepEqual(calls.redirects, [undefined]);
	assert.deepEqual(calls.errors, ['https://cacti.example/cacti/cdef.php?action=edit&id=4']);
});

test('an ordinary page load keeps the 1.2.31 GET, history entry and redirect target', () => {
	const {context, calls, requests} = loadLayout();

	context.loadPage('cdef.php?action=edit&id=4');
	requests[0].onDone('<div>cdef edit</div>');

	assert.deepEqual(calls.get, [['cdef.php?action=edit&id=4']]);
	assert.equal(calls.post.length, 0);
	assert.deepEqual(calls.formStatus, ['loadpage']);
	assert.deepEqual(calls.pushState, ['cdef.php?action=edit&id=4']);
	assert.deepEqual(calls.redirects, ['cdef.php?action=edit&id=4']);
	assert.deepEqual(calls.main, ['<div>cdef edit</div>']);
});

test('unsaved form changes stop a post action until the dialog replays it as a POST', () => {
	const {context, calls} = loadLayout({formChanged: true});

	context.loadPage('cdef.php?action=item_remove&id=7&cdef_id=4', false, true);

	assert.deepEqual(calls.formStatus, ['post']);
	assert.equal(calls.post.length + calls.get.length, 0);

	const htmlForm = fs.readFileSync(path.join(root, 'lib', 'html_form.php'), 'utf8');

	assert.match(htmlForm, /\} else if \(type == 'post'\) \{\s+loadPage\(href, true, true\);/);
});

test('a post action anchor matched by ajaxAnchors takes the same in-page POST', () => {
	const body = extractFunction(layoutSource, 'ajaxAnchors');
	const branch = body.slice(body.indexOf("hasClass('cactiPostAction')"), body.indexOf('/* update menu selection */'));

	assert.match(branch, /loadPage\(\$\(this\)\.data\('url'\) \|\| href, false, true\);/);
	assert.doesNotMatch(branch, /submitPageUsingPost/);
});
