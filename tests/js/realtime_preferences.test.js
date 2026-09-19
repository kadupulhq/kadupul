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
const clientSource = fs.readFileSync(path.join(root, 'include', 'realtime.js'), 'utf8');

/* realtime.js runs against a page with jQuery; record each request it makes
 * and answer with an empty real-time reply */
function loadRealtime(controls) {
	const requests = [];
	const chain = {
		done: () => chain,
		fail: () => chain,
		always: () => chain,
	};
	const element = (selector) => ({
		val: (value) => (value === undefined ? controls[selector] : undefined),
		is: () => controls[selector] === true,
		length: 0,
		find: () => ({ length: 0, position: () => ({ top: 0, left: 0 }), width: () => 0, height: () => 0 }),
		position: () => ({ top: 0, left: 0 }),
		outerHeight: () => 0,
		outerWidth: () => 0,
		width: () => 800,
		height: () => 600,
		selectmenu: () => undefined,
	});
	const $ = (selector) => element(selector);

	$.ajax = (options) => {
		requests.push({ type: options.type, url: options.url, data: options.data === undefined ? undefined : { ...options.data }, dataType: options.dataType });

		return chain;
	};
	$.get = (url) => {
		requests.push({ type: 'GET', url: url, legacy: true });

		return chain;
	};
	$.getJSON = (url) => {
		requests.push({ type: 'GET', url: url, legacy: true });

		return chain;
	};

	const context = vm.createContext({
		$,
		Pace: { stop: null, ignore: (callback) => callback() },
		csrfMagicToken: 'sid:token,1',
		urlPath: '/',
		window: { innerHeight: 600, innerWidth: 800 },
		navigator: { userAgent: '' },
		setTimeout: () => 0,
		clearTimeout: () => undefined,
		refreshMSeconds: 0,
		myRefresh: 0,
		parseInt,
		Math,
	});

	vm.runInContext(clientSource, context);

	return { context, requests };
}

test('the pop-out saves by POST with the token only when a choice changes', () => {
	const controls = { '#graph_start': '60', '#ds_step': '10', '#size': '100', '#thumbnails': false, '#local_graph_id': '5' };
	const { context, requests } = loadRealtime(controls);

	context.imageOptionsChanged('init');
	context.imageOptionsChanged('countdown');
	controls['#ds_step'] = '30';
	context.imageOptionsChanged('interval');
	context.imageOptionsChanged('countdown');

	assert.deepEqual(requests.map((request) => request.type), ['POST', 'GET', 'POST', 'GET']);
	assert.deepEqual(requests[0].data, { __csrf_magic: 'sid:token,1' });
	assert.equal(requests[1].data, undefined);
	assert.ok(requests.every((request) => request.dataType === 'json' && !request.legacy));
	assert.match(requests[2].url, /action=interval&.*ds_step=30/);
});

test('inline real-time saves by POST once and refreshes by GET', () => {
	const controls = { '#graph_start': '60', '#ds_step': '10', '#size': '100', '#thumbnails': false };
	const { context, requests } = loadRealtime(controls);

	context.realtimeArray[5] = true;
	context.realtimeGrapher();
	context.graphsRendered = null;
	context.realtimeGrapher();
	controls['#graph_start'] = '300';
	context.graphsRendered = null;
	context.realtimeGrapher();

	assert.deepEqual(requests.map((request) => request.type), ['POST', 'GET', 'POST']);
	assert.ok(requests.every((request) => request.dataType === 'text' && /action=countdown&.*local_graph_id=5/.test(request.url)));
	assert.deepEqual(requests[2].data, { __csrf_magic: 'sid:token,1' });
});
