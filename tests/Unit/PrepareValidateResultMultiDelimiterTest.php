<?php
/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

require_once dirname(__DIR__, 2) . '/include/global_constants.php';

if (!function_exists('read_config_option')) {
	function read_config_option($name) {
		return '';
	}
}

require_once dirname(__DIR__, 2) . '/lib/functions.php';

test('prepare_validate_result normalizes bang-separated multi-value fields', function () {
	$result = 'users!14 load!0.42';

	expect(prepare_validate_result($result))->toBeTrue();
	expect($result)->toBe('users:14 load:0.42');
});

test('prepare_validate_result accepts mixed multi-value delimiters consistently', function () {
	$result = 'users:14 load!0.42';

	expect(prepare_validate_result($result))->toBeTrue();
	expect($result)->toBe('users:14 load:0.42');
});
