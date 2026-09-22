<?php

/* Copyright (C) 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later */

namespace FilterTableSemanticsTest;

$baseline = json_decode(file_get_contents(__DIR__ . '/filter-table-baseline.json'), true, 512, JSON_THROW_ON_ERROR);
$cases = array();
foreach ($baseline as $entry) { $cases[$entry['key']] = array($entry); }
dataset('filter table semantics', $cases);

test('all 55 filter-layout findings are accounted for', function () use ($baseline) {
	expect(count($baseline))->toBe(55);
	expect(count(array_unique(array_column($baseline, 'key'))))->toBe(55);
});

test('filter tables retain styling and expose their controls rather than data-grid semantics', function ($entry) use ($baseline) {
	$source = file_get_contents(dirname(__DIR__, 4) . '/' . $entry['file']);
	expect($source)->not->toBeFalse();
	$expected = array_filter($baseline, function ($candidate) use ($entry) {
		return $candidate['file'] === $entry['file'] && $candidate['after'] === $entry['after'];
	});
	expect(substr_count($source, $entry['after']))->toBe(count($expected));
	expect(str_replace(" role='presentation'", '', $entry['after']))->toBe($entry['before']);
	$doc = new \DOMDocument();
	$doc->loadHTML('<!doctype html><html><body>' . $entry['after']
		. '<tr><td><label for="fixture">Search</label></td><td>'
		. '<input id="fixture" name="filter" value="unchanged"><button type="submit">Go</button>'
		. '</td></tr></table></body></html>');
	$table = $doc->getElementsByTagName('table')->item(0);
	expect($table->getAttribute('role'))->toBe('presentation');
	expect($table->getAttribute('class'))->toBe('filterTable');
	expect($table->hasAttribute('aria-hidden'))->toBeFalse();
	expect($table->hasAttribute('tabindex'))->toBeFalse();
	expect($doc->getElementsByTagName('input')->item(0)->getAttribute('value'))->toBe('unchanged');
	expect($doc->getElementsByTagName('label')->item(0)->getAttribute('for'))->toBe('fixture');
	expect($doc->getElementsByTagName('button')->item(0)->getAttribute('type'))->toBe('submit');
})->with('filter table semantics');
