<?php

declare(strict_types=1);

/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2026 The Kadupul project and contributors                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

require_once dirname(__DIR__, 3) . '/Helpers/NativeSessionProbe.php';

/** Actual native encoding, production callbacks and shipped sessions table. */
function db_session_native_outcomes(): array {
	static $result = null;
	if ($result !== null) {
		return $result;
	}
	$result = native_session_fixture('session-store-native.php', array(dirname(__DIR__, 4)));
	return $result;
}

beforeEach(function () {
	if (getenv('KADUPUL_TEST_MYSQL_DSN') === false) {
		$this->markTestSkipped('Set KADUPUL_TEST_MYSQL_DSN for isolated real-schema tests.');
	}
});

test('a logged in session is stored with its user', function () {
	$result = db_session_native_outcomes();
	expect($result['passed'])->toContain('fresh authentication', 'actual facade write');
});

test('a session that drops its login is stored without it', function () {
	$result = db_session_native_outcomes();
	expect($result['passed'])->toContain('logout update', 'logout bytes retained', 'logout metadata', 'late login cannot replace logout');
});

test('a visitor who never logged in still gets no row', function () {
	$result = db_session_native_outcomes();
	expect($result['passed'])->toContain('anonymous acknowledgement', 'anonymous no row');
});
