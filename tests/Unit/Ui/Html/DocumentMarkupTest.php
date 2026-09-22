<?php

/* Copyright (C) 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later */

namespace DocumentMarkupTest;

function __($text) { return $text; }
function __esc($text) { return htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); }
function html_escape($text) { return htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); }
function get_cacti_version_text() { return 'LTS fixture'; }
const CACTI_LOCALE = 'fr-FR';

function render($markup, $file) {
	$snmp_query = array('id' => 42);
	ob_start();
	try {
		if ($file === 'graphs_new.php') {
			eval('namespace DocumentMarkupTest; print "' . $markup . '";');
		} else {
			eval('namespace DocumentMarkupTest; ?>' . $markup);
		}
		return ob_get_contents();
	} finally {
		ob_end_clean();
	}
}

$baseline = json_decode(file_get_contents(__DIR__ . '/document-markup-baseline.json'), true, 512, JSON_THROW_ON_ERROR);
$cases = array();
foreach ($baseline as $index => $entry) { $cases[$entry['file'] . ':' . $index] = array($entry); }
dataset('document markup', $cases);

test('document corrections use production fragments and retain visible text', function ($entry) {
	$source = file_get_contents(dirname(__DIR__, 4) . '/' . $entry['file']);
	expect($source)->toContain($entry['after']);
	$before = render($entry['before'], $entry['file']);
	$after = render($entry['after'], $entry['file']);
	expect(trim(strip_tags($after)))->toBe(trim(strip_tags($before)));
	if (strpos($entry['after'], '<html lang=') !== false) {
		expect($after)->toContain("lang='fr-FR'");
	}
	if (strpos($entry['before'], '<select') !== false) {
		expect($after)->toMatch('/aria-label=\x27[^\x27]+\x27/');
		$restored = preg_replace('/\s+aria-label=\x27[^\x27]+\x27/', '', $after);
		expect($restored)->toBe($before);
	}
	if ($entry['file'] === 'graphs_new.php') {
		expect($after)->toContain("for='sgg_42'");
	}
	if (strpos($entry['before'], '<legend>') !== false) {
		expect($after)->toContain("<h1 class='loginHeading'>");
	}
	if (strpos($entry['before'], '<iframe') !== false) {
		expect($after)->toContain("title='Cacti website'");
	}
	if (strpos($entry['before'], '<font') !== false || strpos($entry['before'], '<tt>') !== false) {
		expect($after)->not->toMatch('/<(?:font|tt)\b/');
		expect($after)->toContain('<span');
	}
})->with('document markup');
