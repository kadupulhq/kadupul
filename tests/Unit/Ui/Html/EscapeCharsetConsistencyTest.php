<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/*
 * html_escape() escapes with the configured character set. The inline
 * htmlspecialchars() calls that replaced it must do the same: with
 * ENT_SUBSTITUTE, escaping Latin-1 bytes as UTF-8 turns each one into U+FFFD,
 * and saving the form writes that back to the database.
 */

namespace EscapeCharsetConsistencyTest;

/**
 * Lines that escape with a hardcoded character set rather than the configured one.
 *
 * @param string $file The library to scan.
 *
 * @return array<int, string> The offending lines, keyed by line number.
 */
function hardcoded_escapes($file) {
	$found = array();

	foreach (explode("\n", (string) file_get_contents(dirname(__DIR__, 4) . '/' . $file)) as $index => $line) {
		// The corrected form keeps UTF-8 as the fallback of the configured charset.
		if (strpos(str_replace("ini_get('default_charset') ?: 'UTF-8'", '', $line), "'UTF-8'") === false) {
			continue;
		}

		if (strpos($line, 'htmlspecialchars(') !== false || strpos($line, 'html_entity_decode(') !== false) {
			$found[$index + 1] = trim($line);
		}
	}

	return $found;
}

test('the escaping libraries resolve the character set at runtime', function () {
	expect(hardcoded_escapes('lib/html.php'))->toBe(array())
		->and(hardcoded_escapes('lib/html_form.php'))->toBe(array());
});

test('a Latin-1 value survives escaping when the charset is configured', function () {
	$value = "Caf\xE9 Br\xFCnn";
	$flags = ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5;

	// What the inline calls did, against what html_escape() does.
	expect(bin2hex(htmlspecialchars($value, $flags, 'UTF-8', false)))->toBe('436166efbfbd204272efbfbd6e6e')
		->and(bin2hex(htmlspecialchars($value, $flags, 'ISO-8859-1', false)))->toBe(bin2hex($value));
});

test('the escaping calls read the charset the way the corrected files do', function () {
	// lib/html_utility.php and graph.php were corrected first; same expression.
	foreach (array('lib/html.php', 'lib/html_form.php') as $file) {
		$source = file_get_contents(dirname(__DIR__, 4) . '/' . $file);

		expect(substr_count($source, "ini_get('default_charset') ?: 'UTF-8'"))->toBeGreaterThan(0, $file);
	}
});
