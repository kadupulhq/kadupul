// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

// graph_realtime.php saves the real-time preferences only from a POST, which
// csrf-magic checks. realtime.js must post with the token when a choice
// changes and keep the periodic refresh a GET.

import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const source = readFileSync(new URL('../../include/realtime.js', import.meta.url), 'utf8');

function loadRealtime(controls) {
	const requests = [];
	const chain = { done: () => chain, fail: () => chain, always: () => chain };
	const element = (selector) => ({
		val: () => controls[selector],
		is: () => controls[selector] === true,
		length: 0,
		find: () => ({ length: 0, position: () => ({ top: 0, left: 0 }), width: () => 0, height: () => 0 }),
		position: () => ({ top: 0, left: 0 }),
		outerHeight: () => 0,
		outerWidth: () => 0,
		width: () => 800,
		height: () => 600,
	});
	const $ = (selector) => element(selector);

	$.ajax = (options) => {
		requests.push({
			type: options.type,
			url: options.url,
			data: options.data,
			dataType: options.dataType,
			// Settles the request through the callbacks realtimeRequest() passed.
			settle: (succeeded) => {
				if (succeeded && options.success) options.success();
				if (options.complete) options.complete();
			},
		});

		return chain;
	};
	$.getJSON = (url) => {
		requests.push({ type: 'GET', url, data: undefined, dataType: 'json' });

		return chain;
	};
	$.get = (url) => {
		requests.push({ type: 'GET', url, data: undefined, dataType: 'text' });

		return chain;
	};

	const context = vm.createContext({
		$,
		Pace: { stop: undefined, ignore: (fn) => fn() },
		csrfMagicToken: 'sid:token',
		urlPath: '/',
		navigator: { userAgent: 'Chrome' },
		window: {},
		document: {},
		console,
	});

	vm.runInContext(source, context);

	return { context, requests };
}

const controls = { '#graph_start': '300', '#ds_step': '30', '#size': '50', '#thumbnails': false, '#local_graph_id': '5' };

test('the first real-time request posts the choices with the CSRF token', () => {
	const { context, requests } = loadRealtime(controls);

	context.imageOptionsChanged('init');

	assert.equal(requests.length, 1);
	assert.equal(requests[0].type, 'POST');
	assert.deepEqual({ ...requests[0].data }, { __csrf_magic: 'sid:token' });
	assert.match(requests[0].url, /action=init/);
});

test('an unchanged refresh stays a GET and a changed choice posts again', () => {
	const { context, requests } = loadRealtime(controls);

	context.imageOptionsChanged('init');
	requests[0].settle(true);
	context.imageOptionsChanged('countdown');
	controls['#ds_step'] = '60';
	context.imageOptionsChanged('interval');
	controls['#ds_step'] = '30';

	assert.deepEqual(requests.map((request) => request.type), ['POST', 'GET', 'POST']);
	assert.equal(requests[1].data, undefined);
});

test('a refresh during the save stays a GET', () => {
	const { context, requests } = loadRealtime(controls);

	context.imageOptionsChanged('init');
	context.imageOptionsChanged('countdown');

	assert.deepEqual(requests.map((request) => request.type), ['POST', 'GET']);
});

test('a rejected save is posted again on the next refresh', () => {
	const { context, requests } = loadRealtime(controls);

	context.imageOptionsChanged('init');
	requests[0].settle(false);
	context.imageOptionsChanged('countdown');
	requests[1].settle(true);
	context.imageOptionsChanged('countdown');

	assert.deepEqual(requests.map((request) => request.type), ['POST', 'POST', 'GET']);
	assert.deepEqual({ ...requests[1].data }, { __csrf_magic: 'sid:token' });
});
