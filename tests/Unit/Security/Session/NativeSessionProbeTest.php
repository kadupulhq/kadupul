<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-2.0-or-later

require_once dirname(__DIR__, 3) . '/Helpers/NativeSessionProbe.php';

test('native session probe requires the real child outcome after JSON', function (string $mode) {
	try {
		native_session_fixture('session-probe-outcome-native.php', array($mode));
		$this->fail('Incomplete or failed subprocess outcome was accepted.');
	} catch (RuntimeException|JsonException $failure) {
		expect($failure)->toBeInstanceOf(Throwable::class);
	}
})->with(array('late-failure', 'diagnostic', 'empty', 'truncated'));

test('native session probe retains a complete successful child outcome', function () {
	expect(native_session_fixture('session-probe-outcome-native.php', array('success')))->toBe(array('completed' => true));
});
