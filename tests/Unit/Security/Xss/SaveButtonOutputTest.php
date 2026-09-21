<?php

/*
 * Copyright (C) 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace SaveButtonOutputTest;

class Fixture {
	public static $existing = false;
	public static $ajax = null;
}

foreach (array(
	'form_save_button' => 'html_form.php', 'form_save_buttons' => 'html_form.php',
	'sanitize_uri' => 'functions.php', 'is_urlencoded' => 'functions.php',
) as $helper => $file) {
	$source = file_get_contents(dirname(__DIR__, 4) . '/lib/' . $file);
	if (!preg_match('/function ' . $helper . '\\(.*?^\\}/ms', $source, $match)) {
		throw new \RuntimeException('Missing helper: ' . $helper);
	}
	eval('namespace SaveButtonOutputTest; ' . $match[0]);
}

function __($text) {
	return $text . ' \'"<&';
}

function __esc($text) {
	return htmlspecialchars(__($text), ENT_QUOTES | ENT_HTML5, 'UTF-8', false);
}

function isempty_request_var($key) {
	expect($key)->toBe('record_id');
	return !Fixture::$existing;
}

function form_end($ajax) {
	Fixture::$ajax = $ajax;
}

function render_buttons($callback) {
	ob_start();
	try {
		$callback();
		$output = ob_get_contents();
	} finally {
		ob_end_clean();
	}
	$document = new \DOMDocument();
	$document->loadHTML('<!doctype html><html><head><meta charset="UTF-8"></head><body>' . $output . '</body></html>');
	expect($document->getElementsByTagName('script')->length)->toBe(0);
	expect($document->getElementsByTagName('img')->length)->toBe(0);
	foreach ($document->getElementsByTagName('*') as $element) {
		foreach ($element->attributes as $attribute) {
			expect(strncmp($attribute->name, 'on', 2))->not->toBe(0);
		}
	}
	expect($output)->not->toContain(chr(96));
	return $document->getElementsByTagName('input');
}

test('save controls retain mode, labels, hidden action and form end behavior', function ($mode, $existing, $ajax) {
	Fixture::$existing = $existing;
	Fixture::$ajax = null;
	$inputs = render_buttons(function () use ($mode, $ajax) {
		form_save_button('sites.php?one=1&two=2', $mode, 'record_id', $ajax);
	});
	$hasCancel = !in_array($mode, array('import', 'export', 'save', 'close'), true);
	expect($inputs->length)->toBe($hasCancel ? 3 : 2);
	expect($inputs->item(0)->getAttribute('name'))->toBe('action');
	expect($inputs->item(0)->getAttribute('value'))->toBe('save');
	$submit = $inputs->item($inputs->length - 1);
	$label = $mode === '' || $mode === 'return' ? ($existing ? 'Save' : 'Create') : ucfirst($mode);
	expect($submit->getAttribute('value'))->toBe(__($label));
	expect($submit->getAttribute('class'))->toBe($mode . ' ui-button ui-corner-all ui-widget');
	expect($submit->getAttribute('type'))->toBe('submit');
	expect($submit->getAttribute('id'))->toBe('submit');
	if ($hasCancel) {
		expect($inputs->item(1)->getAttribute('data-url'))->toBe('sites.php?one=1&two=2');
		expect($inputs->item(1)->getAttribute('value'))->toBe(__($existing && $mode === 'return' ? 'Return' : 'Cancel'));
		expect($inputs->item(1)->getAttribute('class'))->toContain('cactiReturnTo');
	}
	expect(Fixture::$ajax)->toBe($ajax);
})->with(array('', 'return', 'save', 'create', 'close', 'import', 'export'))
	->with(array(false, true))->with(array(false, true));

test('custom buttons retain order and escaped values without attribute injection', function ($payload) {
	$inputs = render_buttons(function () use ($payload) {
		form_save_buttons(array(array('id' => $payload, 'value' => $payload), array('id' => 'return', 'value' => 'Return')));
	});
	expect($inputs->length)->toBe(3);
	expect($inputs->item(0)->getAttribute('value'))->toBe('save');
	$decoded = html_entity_decode($payload, ENT_QUOTES | ENT_HTML5, 'UTF-8');
	expect($inputs->item(1)->getAttribute('id'))->toBe($decoded);
	expect($inputs->item(1)->getAttribute('value'))->toBe($decoded);
	expect($inputs->item(1)->attributes->length)->toBe(4);
	expect($inputs->item(2)->getAttribute('id'))->toBe('return');
})->with(array('ordinary', 'réseau 日本語', '\'" autofocus onfocus="alert(1)',
	'\'><img src=x onerror=alert(1)>', '&#39;&quot;&amp;', chr(96), ''));

test('cancel controls preserve legacy URL sanitization and empty URL visibility', function ($url) {
	Fixture::$existing = false;
	$inputs = render_buttons(function () use ($url) {
		form_save_button($url, '', 'record_id', false);
	});
	expect($inputs->length)->toBe($url === '' ? 2 : 3);
	if ($url !== '') {
		$expected = html_entity_decode(sanitize_uri($url), ENT_QUOTES | ENT_HTML5, 'UTF-8');
		expect($inputs->item(1)->getAttribute('data-url'))->toBe($expected);
	}
})->with(array('', 'sites.php?a=1&b=2', 'sites.php?q=%22%3E%3Cscript%3E',
	'sites.php?q=&#39;&amp;', 'sites.php?q=\'"<>`', 'sites.php?q=réseau'));
