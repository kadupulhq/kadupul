<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-2.0-or-later

require_once dirname(__DIR__, 3) . '/Helpers/NativeSessionProbe.php';

test('native session persistence confirms real storage and preserves transaction ownership', function (string $fixture, int $checks) {
	if (getenv('KADUPUL_TEST_MYSQL_DSN') === false) {
		$this->markTestSkipped('Set KADUPUL_TEST_MYSQL_DSN for isolated real-schema tests.');
	}
	$result = native_session_fixture($fixture, array(dirname(__DIR__, 4)));
	expect($result['checks'])->toBe($checks);
})->with(array(
	'lifecycle and facade' => array('session-store-native.php', 56),
	'transaction failures and storage guards' => array('session-store-fault-native.php', 73),
	'bounded SQL and native cache capacity' => array('session-store-budget-native.php', 10),
	'concurrent table metadata and GC rollback' => array('session-store-metadata-lock-native.php', 17),
	'registered native engine callbacks' => array('session-engine-native.php', 33)
));
