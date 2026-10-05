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

const source = fs.readFileSync(
	path.join(__dirname, '..', '..', 'include', 'vendor', 'csrf', 'csrf-magic.js'),
	'utf8'
);
const token = 'sid:0123456789abcdef,1700000000';
const field = '__csrf_magic';
const page = 'https://kadupul.example/cacti/graphs.php';
// XHR bodies carry the token URL-encoded; the jQuery fallback adds it as is.
const withToken = `${field}=${encodeURIComponent(token)}&action=save`;
const withRawToken = `${field}=${token}&action=save`;

const sameOrigin = ['graphs.php', '/cacti/graphs.php', '?action=save', '//kadupul.example/cacti/graphs.php',
	'https://kadupul.example/cacti/graphs.php', 'https://KADUPUL.example:443/cacti/graphs.php'];
const crossOrigin = ['https://other.example/', '//other.example/collect', 'https://kadupul.example:8443/cacti/graphs.php',
	'http://kadupul.example/cacti/graphs.php', 'https://kadupul.example.other.example/', '\\\\other.example/x',
	'/\\other.example/x', '\t//other.example/x', 'javascript:void(0)'];

function load(scope) {
	const window = Object.assign({ location: new URL(page) }, scope);
	window.window = window;
	vm.runInNewContext(`var csrfMagicName = ${JSON.stringify(field)}; var csrfMagicToken = ${JSON.stringify(token)};\n${source}`,
		Object.assign(window, { URL }));
	return window;
}

function requestClass() {
	return class Request {
		open() {}
		send(data) { this.sent = data; }
		setRequestHeader() {}
	};
}

function xhrBody(method, url, body = 'action=save', scope = {}) {
	const Request = requestClass();
	load(Object.assign({ XMLHttpRequest: Request }, scope));
	const request = new Request();
	request.open(method, url, true);
	request.send(body);
	return request.sent;
}

test('same-origin XHR posts carry the token', () => {
	for (const url of sameOrigin) {
		assert.equal(xhrBody('POST', url), withToken, url);
	}
});

test('cross-origin XHR posts do not carry the token', () => {
	for (const url of crossOrigin) {
		assert.equal(xhrBody('POST', url), 'action=save', url);
	}
});

test('relative XHR URLs resolve against the document base', () => {
	assert.equal(xhrBody('POST', 'graphs.php', 'action=save', { document: { baseURI: 'https://other.example/' } }), 'action=save');
	assert.equal(xhrBody('POST', 'graphs.php', 'action=save', { document: { baseURI: page } }), withToken);
});

test('an element named baseURI does not hide the document base', () => {
	// A page containing <img name="baseURI"> makes document.baseURI return the element.
	const clobbered = base => {
		class Node { get baseURI() { return base; } }
		const document = Object.create(Node.prototype);
		Object.defineProperty(document, 'baseURI', { value: { tagName: 'IMG' } });
		return { Node, document };
	};

	assert.equal(xhrBody('POST', 'graphs.php', 'action=save', clobbered(page)), withToken);
	assert.equal(xhrBody('POST', 'graphs.php', 'action=save', clobbered('https://other.example/')), 'action=save');
	// Without the prototype getter a shadowed value is not trusted.
	assert.equal(xhrBody('POST', 'graphs.php', 'action=save', { document: { baseURI: { tagName: 'IMG' } } }), 'action=save');
});

test('loading the script twice keeps one submit listener and one XHR decoration', () => {
	const Request = requestClass();
	const listeners = [];
	const window = load({ XMLHttpRequest: Request, document: { baseURI: page, addEventListener: type => listeners.push(type) } });
	vm.runInContext(source, window);
	const request = new Request();
	request.open('POST', 'graphs.php', true);
	request.send('action=save');
	assert.equal(request.sent, withToken);
	assert.deepEqual(listeners, ['submit']);
});

test('the jQuery fallback adds the token to same-origin posts only', () => {
	const calls = [];
	const jQuery = {
		ajax: settings => calls.push(settings.data),
		ajaxSettings: {},
		extend: (_deep, target, ...rest) => Object.assign(target, ...rest),
		param: data => new URLSearchParams(data).toString(),
	};
	const window = load({ jQuery, document: { baseURI: page } });
	window.jQuery.ajax({ type: 'POST', url: '/cacti/graphs.php', data: 'action=save' });
	window.jQuery.ajax({ type: 'POST', url: 'https://other.example/', data: 'action=save' });
	window.jQuery.ajax({ type: 'GET', url: '/cacti/graphs.php', data: 'action=save' });
	assert.deepEqual(calls, [withRawToken, 'action=save', 'action=save']);
});

// A small DOM with the parts the form pass uses. The reflected getters mirror
// the properties the 1.2.31 script read directly, so a test fails if the
// script goes back to trusting them.
function pageDom({ submitter = true, base = page } = {}) {
	const nodes = [];
	const listeners = [];
	const submitted = [];
	const attribute = (node, name) => Element.prototype.getAttribute.call(node, name);
	class Element {
		constructor(tagName, attributes = {}, form = null) {
			Object.assign(this, { tagName, attributes: { ...attributes }, form });
			nodes.push(this);
		}
		getAttribute(name) { return Object.hasOwn(this.attributes, name) ? this.attributes[name] : null; }
		setAttribute(name, value) { this.attributes[name] = String(value); }
		appendChild(child) { child.form = this; return child; }
		get method() { return (attribute(this, 'method') || 'get').toLowerCase(); }
		get action() { return new URL(attribute(this, 'action') || page, page).href; }
		get elements() {
			return Object.fromEntries(nodes.filter(node => node.form === this).map(node => [attribute(node, 'name'), node]));
		}
	}
	class HTMLFormElement extends Element {
		submit() { submitted.push(this); }
	}
	const state = { base };
	class Node { get baseURI() { return state.base; } }
	const document = Object.create(Node.prototype);
	Object.assign(document, {
		getElementsByTagName: tag => nodes.filter(node => node.tagName === tag),
		getElementsByName: name => nodes.filter(node => attribute(node, 'name') === name),
		querySelectorAll(selector) {
			assert.equal(selector, '[formaction]');
			return nodes.filter(node => attribute(node, 'formaction') !== null);
		},
		createElement: tag => new Element(tag),
		addEventListener: (type, listener) => listeners.push([type, listener]),
	});
	const scope = { document, Element, Node, HTMLFormElement };
	if (submitter) {
		scope.SubmitEvent = class { get submitter() { return null; } };
	}
	// What a submission would carry: associated, enabled token fields.
	const carries = form => nodes.some(node => node.form === form && attribute(node, 'name') === field && !node.disabled);
	return {
		state,
		listeners,
		load: () => load(scope),
		form: (attributes, controls = []) => {
			const form = new HTMLFormElement('form', { method: 'post', ...attributes });
			for (const control of controls) {
				new Element(control.tag || 'input', control, form);
			}
			return form;
		},
		submit(form, button = null) {
			for (const [type, listener] of listeners) {
				if (type === 'submit') listener({ target: form, submitter: button });
			}
			return carries(form);
		},
		// A submit event from a browser without SubmitEvent.submitter.
		submitUnknown(form) {
			for (const [type, listener] of listeners) {
				if (type === 'submit') listener({ target: form });
			}
			return carries(form);
		},
		// HTMLFormElement.prototype.submit() as a page script would call it.
		scriptSubmit(form) {
			HTMLFormElement.prototype.submit.call(form);
			assert.equal(submitted.at(-1), form);
			return carries(form);
		},
		button: (form, attributes) => new Element('button', { type: 'submit', ...attributes }, form),
	};
}

test('the load-time form pass adds the token to same-origin POST forms only', () => {
	const dom = pageDom();
	const local = [...sameOrigin, null, ''].map(action => dom.form(action === null ? {} : { action }));
	const foreign = crossOrigin.map(action => dom.form({ action }));
	const get = dom.form({ method: 'get', action: 'graphs.php' });
	const upper = dom.form({ method: 'POST', action: 'graphs.php' });
	dom.load().CsrfMagic.end();
	[...local, upper].forEach(form => assert.equal(dom.submit(form), true, form.getAttribute('action')));
	[...foreign, get].forEach(form => assert.equal(dom.submit(form), false, form.getAttribute('action')));
});

test('a control named action does not hide a cross-origin form action', () => {
	const dom = pageDom();
	const foreign = dom.form({ action: 'https://evil.example/collect' }, [{ name: 'action', value: 'save' }]);
	const local = dom.form({ action: 'graphs.php' }, [{ name: 'action', value: 'save' }]);
	// The browser returns the control, whose string form is a relative path.
	for (const form of [foreign, local]) {
		Object.defineProperty(form, 'action', { value: { toString: () => '[object HTMLInputElement]' } });
	}
	// Controls named getAttribute and elements shadow those members as well.
	Object.defineProperty(local, 'getAttribute', { value: {} });
	Object.defineProperty(local, 'elements', { value: {} });
	dom.load().CsrfMagic.end();
	assert.equal(dom.submit(foreign), false);
	assert.equal(dom.submit(local), true);
});

test('a control named method does not stop the pass for later forms', () => {
	const dom = pageDom();
	const first = dom.form({ action: 'graphs.php' }, [{ name: 'method', value: 'x' }]);
	Object.defineProperty(first, 'method', { value: { tagName: 'INPUT' } });
	const second = dom.form({ action: 'host.php' });
	dom.load().CsrfMagic.end();
	assert.equal(dom.submit(first), true);
	assert.equal(dom.submit(second), true);
});

test('a cross-origin formaction submitter does not carry the token', () => {
	const dom = pageDom();
	const form = dom.form({ action: 'graphs.php' });
	const foreign = dom.button(form, { formaction: 'https://evil.example/collect' });
	const local = dom.button(form, { formaction: '/cacti/graphs.php' });
	const empty = dom.button(form, { formaction: '' });
	const plain = dom.button(form, {});
	dom.load().CsrfMagic.end();
	assert.equal(dom.submit(form, foreign), false);
	assert.equal(dom.submit(form, local), true);
	assert.equal(dom.submit(form, empty), true);
	assert.equal(dom.submit(form, plain), true);
	assert.equal(dom.submit(form), true);
});

test('without SubmitEvent.submitter a form with a cross-origin formaction gets no token', () => {
	const dom = pageDom({ submitter: false });
	const form = dom.form({ action: 'graphs.php' });
	dom.button(form, { formaction: 'https://evil.example/collect' });
	const rendered = dom.form({ action: 'graphs.php' }, [{ name: field, value: token, type: 'hidden' }]);
	dom.button(rendered, { formaction: 'https://evil.example/collect' });
	const other = dom.form({ action: 'graphs.php' });
	dom.button(other, { formaction: 'graphs.php' });
	dom.load().CsrfMagic.end();
	assert.equal(dom.submitUnknown(form), false);
	assert.equal(dom.submitUnknown(rendered), false);
	assert.equal(dom.submitUnknown(other), true);
});

// Markup such as an unclosed <plaintext>, <textarea>, <title>, <xmp> or
// comment after a form keeps the parser from reaching CsrfMagic.end().
test('the submit check works when CsrfMagic.end() never runs', () => {
	const dom = pageDom();
	const form = dom.form({ action: 'graphs.php' }, [{ name: field, value: token, type: 'hidden' }]);
	const inside = dom.button(form, { formaction: '//evil.example/collect' });
	// <button form=...> outside the form is associated with it the same way.
	const outside = dom.button(form, { formaction: 'https://evil.example/collect', form: 'f' });
	const plain = dom.button(form, {});
	dom.load();
	assert.equal(dom.listeners.length, 1);
	assert.equal(dom.submit(form, inside), false);
	assert.equal(dom.submit(form, outside), false);
	assert.equal(dom.submit(form, plain), true);
});

test('a server-rendered token is withheld from a cross-origin submission', () => {
	const dom = pageDom();
	const form = dom.form({ action: 'https://evil.example/collect' }, [{ name: field, value: token, type: 'hidden' }]);
	dom.load().CsrfMagic.end();
	assert.equal(dom.submit(form), false);
});

test('a target changed after the page loaded is checked at submit time', () => {
	const dom = pageDom();
	const form = dom.form({ action: 'graphs.php' });
	dom.load().CsrfMagic.end();
	form.setAttribute('action', 'https://evil.example/collect');
	assert.equal(dom.submit(form), false);
	assert.equal(dom.scriptSubmit(form), false);
	form.setAttribute('action', 'graphs.php');
	assert.equal(dom.submit(form), true);
	assert.equal(dom.scriptSubmit(form), true);
	// A <base href> added after load moves every relative action with it.
	dom.state.base = 'https://evil.example/';
	assert.equal(dom.submit(form), false);
	assert.equal(dom.scriptSubmit(form), false);
});

test('the pass and the submit listener are installed once', () => {
	const dom = pageDom();
	const window = dom.load();
	window.CsrfMagic.end();
	window.CsrfMagic.end();
	assert.equal(dom.listeners.length, 1);
});
