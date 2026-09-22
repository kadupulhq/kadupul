<?php

/* Copyright (C) 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later */

namespace ControlAccessibleLabelTest;

class State {
	public static $reverse = 1;
}
function __($text) { return $text; }
function get_request_var($name) { return State::$reverse; }
function html_escape_request_var($name) { return 'fixture'; }
function display_tooltip($text) { return ''; }

function render($fragment) {
	$secpass_tooltip = 'fixture';
	$ending = strpos($fragment, '<select') !== false
		? '<option value="7" selected>Fixture</option></select>' : '';
	ob_start();
	try {
		// Only committed production fragments are evaluated; no request data is executable.
		eval('namespace ControlAccessibleLabelTest; ?>' . $fragment . $ending);
		return ob_get_contents();
	} finally {
		ob_end_clean();
	}
}

function document($markup) {
	$doc = new \DOMDocument();
	$previous = libxml_use_internal_errors(true);
	try {
		$doc->loadHTML('<!doctype html><html><body><table><tr><td>' . $markup . '</td></tr></table></body></html>');
	} finally {
		libxml_clear_errors();
		libxml_use_internal_errors($previous);
	}
	return $doc;
}

$baseline = json_decode(file_get_contents(__DIR__ . '/control-label-baseline.json'), true, 512, JSON_THROW_ON_ERROR);
$cases = array();
foreach ($baseline as $entry) { $cases[$entry['key']] = array($entry); }
dataset('control label baseline', $cases);

test('twenty distinct label findings are covered', function () use ($baseline) {
	expect(count($baseline))->toBe(20);
	expect(count(array_unique(array_column($baseline, 'key'))))->toBe(20);
});

test('visible captions label their controls without changing fields or output text', function ($entry, $reverse) {
	$source = file_get_contents(dirname(__DIR__, 4) . '/' . $entry['file']);
	expect($source)->not->toBeFalse();
	expect(substr_count($source, $entry['after']))->toBe(1);
	State::$reverse = $reverse;
	$before = render($entry['before']);
	$after = render($entry['after']);
	$oldDoc = document($before);
	$newDoc = document($after);
	$labels = $newDoc->getElementsByTagName('label');
	expect($labels->length)->toBe(1);
	$label = $labels->item(0);
	expect($label->getAttribute('for'))->toBe($entry['id']);
	expect(trim($label->textContent))->not->toBe('');
	$selector = '//*[@id="' . $entry['id'] . '"]';
	$oldControl = (new \DOMXPath($oldDoc))->query($selector);
	$newControl = (new \DOMXPath($newDoc))->query($selector);
	expect($oldControl->length)->toBe(1);
	expect($newControl->length)->toBe(1);
	expect($newDoc->saveHTML($newControl->item(0)))->toBe($oldDoc->saveHTML($oldControl->item(0)));
	$unwrapped = preg_replace('/<label\\b[^>]*>|<\/label>/', '', $after);
	expect(preg_replace('/\s+/', ' ', trim($unwrapped)))->toBe(preg_replace('/\s+/', ' ', trim($before)));
	if ($entry['id'] === 'tail_lines') {
		expect($label->textContent)->toBe($reverse === 1 ? 'Tail Lines' : 'Head Lines');
	}
})->with('control label baseline')->with(array(1, 2));

test('log search text field shares the visible search caption with its operator', function () {
	$source = file_get_contents(dirname(__DIR__, 4) . '/lib/clog_webapi.php');
	expect(substr_count($source, "id='log-search-label'"))->toBe(1);
	expect(preg_match('/<input[^>]*id=\x27rfilter\x27[^>]*aria-labelledby=\x27log-search-label\x27/s', $source))->toBe(1);
});
