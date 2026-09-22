<?php

/* Copyright (C) 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later */

namespace FilterAccessibleLabelTest;

function __($text) { return $text; }

$baseline = json_decode(file_get_contents(__DIR__ . '/filter-label-baseline.json'), true, 512, JSON_THROW_ON_ERROR);
$cases = array();
foreach ($baseline['issues'] as $issue) { $cases[$issue['key']] = array($issue); }
dataset('filter accessible labels', $cases);

function render($fragment) {
	ob_start();
	try {
		eval('namespace FilterAccessibleLabelTest; ?><table><tr><td>' . $fragment . '<option value="7" selected>7</option></select></td></tr></table>');
		return ob_get_contents();
	} finally { ob_end_clean(); }
}

test('each filter fix maps to a unique baseline finding', function () use ($baseline) {
	expect(count($baseline['issues']))->toBe(25);
	expect(count(array_unique(array_column($baseline['issues'], 'key'))))->toBe(25);
});

test('filter controls have associated visible names without changing other markup', function ($issue) {
	$source = file_get_contents(dirname(__DIR__, 4) . '/' . $issue['file']);
	expect(substr_count($source, $issue['after']))->toBe(1);
	$position = strpos($source, $issue['after']);
	$output = render(substr($source, $position, strlen($issue['after'])));
	$doc = new \DOMDocument();
	$doc->loadHTML('<!doctype html><html><body>' . $output . '</body></html>');
	$label = $doc->getElementsByTagName('label')->item(0);
	$select = $doc->getElementsByTagName('select')->item(0);
	expect($doc->getElementsByTagName('label')->length)->toBe(1);
	expect($label->getAttribute('for'))->toBe($select->getAttribute('id'));
	expect($label->textContent)->toBe($issue['label']);
	expect($select->getAttribute('id'))->toBe($issue['id']);
	expect($doc->getElementsByTagName('option')->item(0)->getAttribute('value'))->toBe('7');
	expect($doc->getElementsByTagName('option')->item(0)->hasAttribute('selected'))->toBeTrue();
	// PHP consumes a newline immediately after a closing tag; the new HTML
	// label changes only that insignificant whitespace. Compare source instead.
	expect(str_replace(array("<label for='" . $issue['id'] . "'>", '</label>'), '', $issue['after']))->toBe($issue['before']);
})->with('filter accessible labels');
