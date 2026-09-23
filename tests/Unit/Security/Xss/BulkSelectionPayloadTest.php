<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/*
 * serialize() returns bytes with length prefixes. The confirmation pages escaped
 * that payload as text with ENT_SUBSTITUTE, which rewrites one invalid byte as
 * the three of U+FFFD and leaves the prefix saying one. The selection then
 * failed to unserialize and the bulk action silently did nothing, which bit
 * managers.php first because it keys its selection by name rather than by id.
 */

namespace BulkSelectionPayloadTest;

require_once dirname(__DIR__, 3) . '/Helpers/PhpSource.php';

$functions = file_get_contents(dirname(__DIR__, 4) . '/lib/functions.php');

foreach (array('selected_items_payload', 'selected_items_decode', 'sanitize_unserialize_selected_items') as $name) {
	eval('namespace ' . __NAMESPACE__ . ';' . \test_php_function_source($functions, $name)); // nosemgrep: php.lang.security.eval-use.eval-use
}

/**
 * Escapes a payload the way a confirmation page does.
 *
 * @param string $payload The value of the hidden field.
 *
 * @return string The value the browser sends back.
 */
function round_trip($payload) {
	$escaped = htmlspecialchars($payload, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	$escaped = str_replace('`', '&#96;', $escaped);

	return html_entity_decode($escaped, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

test('a selection of ids survives the confirmation page', function () {
	$items = array('1', '0042', 3);

	expect(unserialize(selected_items_decode(round_trip(selected_items_payload($items))), array('allowed_classes' => false)))->toBe($items);
});

test('a selection keyed by a non-UTF-8 name survives', function () {
	// managers.php keys by "mib__name", and a MIB name can carry Latin-1 bytes.
	$items = array("ifAlias Caf\xE9" => array('notify' => 1));
	$payload = selected_items_payload($items);

	expect($payload)->toMatch('/^[A-Za-z0-9+\/=]+$/')
		->and(unserialize(selected_items_decode(round_trip($payload)), array('allowed_classes' => false)))->toBe($items);
});

test('the old encoding is what this change fixes', function () {
	$items = array("ifAlias Caf\xE9" => array('notify' => 1));

	// The previous payload, escaped the same way: the length prefix no longer matches.
	expect(@unserialize(round_trip(serialize($items)), array('allowed_classes' => false)))->toBeFalse();
});

test('a payload from a form rendered before this change is still accepted', function () {
	$items = array('1', '2');

	expect(sanitize_unserialize_selected_items(serialize($items)))->toBe($items)
		->and(sanitize_unserialize_selected_items(selected_items_payload($items)))->toBe($items);
});

test('a payload that is neither encoding is refused', function () {
	expect(sanitize_unserialize_selected_items('not a payload'))->toBeFalse()
		->and(sanitize_unserialize_selected_items(base64_encode('O:8:"stdClass":0:{}')))->toBeFalse()
		->and(sanitize_unserialize_selected_items(''))->toBeFalse();
});
