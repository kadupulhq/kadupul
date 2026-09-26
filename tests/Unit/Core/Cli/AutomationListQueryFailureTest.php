<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace AutomationListQueryFailureTest;

require_once dirname(__DIR__, 3) . '/Helpers/PhpSource.php';

function db_fetch_assoc($sql) {
	return false;
}

function db_fetch_assoc_prepared($sql, $params) {
	return false;
}

function cacti_sizeof($value) {
	return is_array($value) ? count($value) : 0;
}

$source = file_get_contents(dirname(__DIR__, 4) . '/lib/api_automation_tools.php');
if (!is_string($source)) {
	throw new \RuntimeException('Cannot read lib/api_automation_tools.php.');
}

foreach (array('displayGraphTemplates', 'displayHosts', 'displayTrees', 'displayHostGraphs', 'displayUsers') as $function) {
	eval('namespace AutomationListQueryFailureTest; ' . test_php_function_source($source, $function));
}

test('automation listing helpers distinguish query failures from empty lists', function () {
	expect(displayGraphTemplates(false))->toBeFalse()
		->and(displayHosts(false))->toBeFalse()
		->and(displayTrees())->toBeFalse()
		->and(displayHostGraphs(1))->toBeFalse()
		->and(displayUsers())->toBeFalse();
});
