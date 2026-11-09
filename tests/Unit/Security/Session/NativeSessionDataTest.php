<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-2.0-or-later

require_once dirname(__DIR__, 3) . '/Helpers/NativeSessionProbe.php';

$nativeSessionCases = array();
foreach (array('php', 'php_binary', 'php_serialize') as $serializer) {
	foreach (array('note', 'array', 'reference', 'objects', 'opaque', 'opaque-reference', 'nested', 'large', 'plugin-state') as $scenario) {
		$nativeSessionCases[$serializer . '/' . $scenario] = array($serializer, $scenario);
	}
}

test('native session framing matches the PHP serializer without instantiating payload objects', function (string $serializer, string $scenario) {
	$result = native_session_fixture('session-data-native.php', array($serializer, $scenario));

	expect($result['parser_awoken'])->toBe(0);
	if ($scenario === 'opaque-reference') {
		expect($result['refused'])->toBeTrue();
		return;
	}

	expect($result['matches'])->toBeTrue()
		->and($result['decoded'])->toBeTrue()
		->and($result['decoded_activity'])->toBe(2000)
		->and($result['note_preserved'])->toBeTrue();
})->with($nativeSessionCases);
