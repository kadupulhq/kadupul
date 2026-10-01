<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2026 The Kadupul project and contributors                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * With database sessions ($cacti_db_session), the write handler stored a
 * session only while it held a user. A session that dropped its login
 * without being destroyed or its row deleted kept its logged-in row, and
 * the next read restored the login. The change password page does this for
 * a session opened before the last password change and for an account that
 * may not change its password; after a change it deletes the rows itself.
 * The branch meant for that case tested for 'ses_user_id' and never ran.
 *
 * The shipped read and write handlers run in a child process against an
 * in-memory sessions table.
 */

require_once dirname(__DIR__, 3) . '/Helpers/AuthEntryProbe.php';

/**
 * Run a sequence of session writes. Each step is the $_SESSION the request
 * ends with; the handler gets it encoded, as PHP passes it.
 */
function db_session_run(array $rows, array $steps) : array {
	$session = file_get_contents(dirname(__DIR__, 4) . '/include/session.php');

	$source = <<<'PHP'
<?php
$scenario = json_decode(stream_get_contents(STDIN), true);

ini_set('session.use_cookies', '0');
ini_set('session.save_path', sys_get_temp_dir());
session_start();

$GLOBALS['rows'] = $scenario['rows'];

function cacti_db_session_check() {
}

function get_client_addr() {
	return '192.0.2.10';
}

function db_fetch_cell_prepared($sql, $params = array()) {
	return $GLOBALS['rows'][$params[0]]['data'] ?? false;
}

function db_execute_prepared($sql, $params = array()) {
	$sql = trim(preg_replace('/\s+/', ' ', $sql));

	if (strpos($sql, 'INSERT INTO sessions') === 0) {
		list($id, $addr, $access, $data) = $params;

		$user_id = count($params) == 6 ? $params[4] : 0;

		$GLOBALS['rows'][$id] = array('data' => $data, 'user_id' => $user_id);
	} elseif (strpos($sql, 'UPDATE sessions SET data = ?') === 0) {
		$id = end($params);

		if (isset($GLOBALS['rows'][$id])) {
			$GLOBALS['rows'][$id]['data']    = $params[0];
			$GLOBALS['rows'][$id]['user_id'] = 0;
		}
	} elseif (strpos($sql, 'UPDATE IGNORE sessions SET access = ?') !== 0) {
		throw new RuntimeException('unexpected query: ' . $sql);
	}

	return true;
}

PHP;

	foreach (array('cacti_db_session_read', 'cacti_db_session_write') as $name) {
		$source .= cacti_test_function_source($session, $name) . "\n\n";
	}

	$source .= <<<'PHP'
foreach ($scenario['steps'] as $step) {
	$_SESSION = $step;
	cacti_db_session_write('abc', session_encode());
}

$_SESSION = array();
session_decode((string) cacti_db_session_read('abc'));

print json_encode(array('rows' => $GLOBALS['rows'], 'restored' => $_SESSION));
PHP;

	return cacti_test_run_php_source($source, array('rows' => (object) $rows, 'steps' => $steps));
}

test('a logged in session is stored with its user', function () {
	$result = db_session_run(array(), array(array('sess_user_id' => 42, 'theme' => 'modern')));

	expect($result['rows']['abc']['user_id'])->toBe(42)
		->and($result['restored']['sess_user_id'] ?? null)->toBe(42);
});

test('a session that drops its login is stored without it', function () {
	$result = db_session_run(array(), array(
		array('sess_user_id' => 42, 'theme' => 'modern'),
		array('theme' => 'modern'),
	));

	expect($result['restored'])->not->toHaveKey('sess_user_id')
		->and($result['restored']['theme'] ?? null)->toBe('modern')
		->and($result['rows']['abc']['user_id'])->toBe(0);
});

test('a visitor who never logged in still gets no row', function () {
	$result = db_session_run(array(), array(array('theme' => 'modern')));

	expect($result['rows'])->toBe(array());
});
