<?php

/* Copyright (C) 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later */

namespace LayoutTableSemanticsTest;

$baseline = json_decode(file_get_contents(__DIR__ . '/layout-table-baseline.json'), true, 512, JSON_THROW_ON_ERROR);
$cases = array();
foreach ($baseline as $entry) { $cases[$entry['key']] = array($entry); }
dataset('layout table semantics', $cases);

test('all 41 remaining layout-table findings are accounted for', function () use ($baseline) {
	expect(count($baseline))->toBe(41);
	expect(count(array_unique(array_column($baseline, 'key'))))->toBe(41);
});

test('layout table changes preserve all original attributes and embedded PHP', function ($entry) use ($baseline) {
	$source = file_get_contents(dirname(__DIR__, 4) . '/' . $entry['file']);
	expect($source)->toContain($entry['context']);
	$expected = array_filter($baseline, function ($candidate) use ($entry) {
		return $candidate['file'] === $entry['file'] && $candidate['after'] === $entry['after'];
	});
	expect(substr_count("\n" . $source, "\n" . $entry['after'] . "\n"))->toBe(count($expected));
	$withoutRole = str_replace(" role='presentation'", '', $entry['after']);
	// Long graph opening tags wrap between attributes; embedded PHP remains byte-identical.
	$withoutRole = preg_replace('/\n\t+data-disabled/', ' data-disabled', $withoutRole);
	expect($withoutRole)->toBe($entry['before']);
	// This is a markup contract, not a full page or assistive-technology integration test.
	$markup = preg_replace('/<\?php.*?\?>/s', 'fixture', $entry['after']);
	preg_match('/<table\b[^>]*>/', $markup, $match);
	$doc = new \DOMDocument();
	$doc->loadHTML('<!doctype html><html><body>' . $match[0]
		. '<tr><td><label for="fixture">Action</label><button id="fixture" type="submit">Go</button>'
		. '</td></tr></table></body></html>');
	$table = $doc->getElementsByTagName('table')->item(0);
	expect($table->getAttribute('role'))->toBe('presentation');
	expect($table->hasAttribute('aria-hidden'))->toBeFalse();
	expect($table->hasAttribute('tabindex'))->toBeFalse();
	expect($doc->getElementsByTagName('button')->item(0)->getAttribute('type'))->toBe('submit');
	expect($doc->getElementsByTagName('label')->item(0)->getAttribute('for'))->toBe('fixture');
})->with('layout table semantics');
