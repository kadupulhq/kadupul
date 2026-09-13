<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2026 The Kadupul project and contributors                 |
 |                                                                         |
 | This program is free software; you can redistribute it and/or           |
 | modify it under the terms of the GNU General Public License             |
 | as published by the Free Software Foundation; either version 2          |
 | of the License, or (at your option) any later version.                  |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * A permission, group or realm edit flags the affected users with reset_perms
 * so their sessions reload permissions, as 1.2.31 did. It does not delete their
 * remember-me tokens; a restored cookie session loads the new permissions.
 * Disabling an account still revokes its tokens.
 */

require_once dirname(__DIR__, 3) . '/Helpers/AuthEntryProbe.php';

function permission_reset_release_source() : ?string {
	static $source = false;

	if ($source === false) {
		$output = shell_exec('git -C ' . escapeshellarg(dirname(__DIR__, 4)) . ' show release/1.2.31:lib/auth.php 2>/dev/null');
		$source = (is_string($output) && strpos($output, "\nfunction reset_user_perms(") !== false) ? $output : null;
	}

	return $source;
}

/**
 * Run functions extracted from a lib/auth.php source in a child process and
 * return the SQL each call issued.
 *
 * @param array<int, array{0: string, 1: array<int, mixed>}> $calls
 *
 * @return array<int, array<int, array{sql: string, params: array<int, mixed>}>>
 */
function permission_reset_run(array $calls, array $members = array(), ?string $source = null) : array {
	$auth = $source ?? file_get_contents(dirname(__DIR__, 4) . '/lib/auth.php');

	$child = <<<'PHP'
<?php
$scenario = json_decode(stream_get_contents(STDIN), true);

$GLOBALS['members']  = $scenario['members'];
$GLOBALS['executed'] = array();
$_SESSION            = array('sess_user_id' => 1);

function db_execute($sql, $log = true) {
	$GLOBALS['executed'][] = array('sql' => trim(preg_replace('/\s+/', ' ', $sql)), 'params' => array());

	return true;
}

function db_execute_prepared($sql, $params = array(), $log = true) {
	$GLOBALS['executed'][] = array('sql' => trim(preg_replace('/\s+/', ' ', $sql)), 'params' => array_values($params));

	return true;
}

function db_fetch_assoc_prepared($sql, $params = array(), $log = true) {
	return $GLOBALS['members'];
}

function array_rekey($array, $key, $key_value) {
	$ret = array();

	foreach ($array as $row) {
		$ret[$row[$key]] = $row[$key_value];
	}

	return $ret;
}

function cacti_sizeof($array) {
	return is_array($array) ? count($array) : 0;
}

function kill_session_var($name) {
	unset($_SESSION[$name]);
}

function input_validate_input_number($value, $variable = '') {
}

PHP;

	foreach (array('reset_group_perms', 'reset_user_perms', 'user_disable') as $function) {
		$child .= cacti_test_function_source($auth, $function) . "\n\n";
	}

	$child .= <<<'PHP'
$results = array();

foreach ($scenario['calls'] as $call) {
	$GLOBALS['executed'] = array();

	call_user_func_array($call[0], $call[1]);

	$results[] = $GLOBALS['executed'];
}

print json_encode($results);
PHP;

	return cacti_test_run_php_source($child, array('calls' => $calls, 'members' => $members));
}

/**
 * @param array<int, array{sql: string, params: array<int, mixed>}> $executed
 *
 * @return array{tokens: bool, reset: array<int, int>}
 */
function permission_reset_effect(array $executed) : array {
	$tokens = false;
	$reset  = array();

	foreach ($executed as $query) {
		if (strpos($query['sql'], 'user_auth_cache') !== false) {
			$tokens = true;
		}

		if (strpos($query['sql'], 'UPDATE user_auth SET reset_perms') === 0) {
			if (cacti_sizeof_or_zero($query['params'])) {
				$ids = $query['params'];
			} elseif (preg_match('/IN \(([0-9,]+)\)/', $query['sql'], $match) === 1) {
				$ids = explode(',', $match[1]);
			} else {
				$ids = array();
			}

			foreach ($ids as $id) {
				$reset[] = (int) $id;
			}
		}
	}

	return array('tokens' => $tokens, 'reset' => $reset);
}

function cacti_sizeof_or_zero($value) : int {
	return is_array($value) ? count($value) : 0;
}

test('permission resets keep remember-me tokens', function () {
	$results = permission_reset_run(array(
		array('reset_user_perms', array(42)),
		array('reset_group_perms', array(5)),
	), array(array('user_id' => 10), array('user_id' => 11)));

	foreach ($results as $executed) {
		foreach ($executed as $query) {
			expect($query['sql'])->not->toContain('user_auth_cache');
		}
	}

	expect(permission_reset_effect($results[0])['reset'])->toBe(array(42))
		->and(permission_reset_effect($results[1])['reset'])->toBe(array(10, 11));
});

test('permission resets flag the same users as 1.2.31 and leave tokens alone as it did', function () {
	$calls = array(
		array('reset_user_perms', array(42)),
		array('reset_user_perms', array(1)),
		array('reset_group_perms', array(5)),
	);

	$members = array(array('user_id' => 10), array('user_id' => 11), array('user_id' => 12));

	$current = array_map('permission_reset_effect', permission_reset_run($calls, $members));
	$release = array_map('permission_reset_effect', permission_reset_run($calls, $members, permission_reset_release_source()));

	expect($current)->toBe($release);

	$empty_current = permission_reset_run(array(array('reset_group_perms', array(6))));
	$empty_release = permission_reset_run(array(array('reset_group_perms', array(6))), array(), permission_reset_release_source());

	expect($empty_current)->toBe(array(array()))
		->and($empty_release)->toBe(array(array()));
})->skip(function () {
	return permission_reset_release_source() === null;
}, 'release/1.2.31 is not available in this clone');

test('disabling an account still revokes its remember-me tokens', function () {
	$executed = permission_reset_run(array(array('user_disable', array(7))))[0];

	expect($executed)->toContain(array('sql' => 'DELETE FROM user_auth_cache WHERE user_id = ?', 'params' => array(7)))
		->and($executed)->toContain(array('sql' => 'DELETE FROM sessions WHERE user_id = ?', 'params' => array(7)));
});
