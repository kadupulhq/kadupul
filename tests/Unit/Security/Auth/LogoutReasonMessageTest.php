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
*/

/*
 * logout.php explains an automatic logout for three reasons: a session
 * timeout, an account suspension and a Remote Data Collector state change.
 * The third compared the action against 'remove', so clog.php's
 * logout.php?action=remote showed an empty reason. The shipped page runs in a
 * child process from a directory whose include/auth.php is a stub.
 */

require_once dirname(__DIR__, 3) . '/Helpers/AuthEntryProbe.php';

function logout_reason_run(string $action) : array {
	$work = sys_get_temp_dir() . '/kadupul-logout-' . bin2hex(random_bytes(6));

	mkdir($work . '/include', 0700, true);

	file_put_contents($work . '/include/global_session.php', "<?php\n");
	file_put_contents($work . '/include/auth.php', <<<'PHP'
<?php
$GLOBALS['scenario'] = json_decode(stream_get_contents(STDIN), true);

define('OPER_MODE_NATIVE', 0);
define('OPER_MODE_RESKIN', 2);
define('COPYRIGHT_YEARS_SHORT', '2004-2026');

register_shutdown_function(function () {
	$output = '';

	while (ob_get_level() > 0) {
		$output = ob_get_clean() . $output;
	}

	print json_encode(array('output' => $output));
});

ob_start();

function set_default_action($default = '') {
}

function get_request_var($name, $default = '') {
	return $GLOBALS['scenario']['request'][$name] ?? $default;
}

function api_plugin_hook($name) {
}

function api_plugin_hook_function($name, $parm = null) {
	return $parm;
}

function clear_auth_cookie() {
}

function cacti_cookie_logout() {
}

function cacti_session_destroy() {
}

function get_cacti_version() {
	return '1.2.32';
}

function html_common_header($title, $selectedTheme = '') {
}

function __($text, ...$args) {
	return vsprintf($text, $args);
}

class CactiSecureHeaders {
	public static function getNonceAttribute() {
		return 'nonce="probe"';
	}
}
PHP);

	copy(dirname(__DIR__, 4) . '/logout.php', $work . '/logout.php');

	$cwd = getcwd();
	chdir($work);

	try {
		return cacti_test_run_php_file($work . '/logout.php', array('request' => array('action' => $action)));
	} finally {
		chdir($cwd);

		foreach (array('include/global_session.php', 'include/auth.php', 'logout.php') as $file) {
			unlink($work . '/' . $file);
		}

		rmdir($work . '/include');
		rmdir($work);
	}
}

test('a logout after a Remote Data Collector state change explains why', function () {
	$result = logout_reason_run('remote');

	expect($result['output'])->toContain('<p>You have been logged out of Cacti due to a Remote Data Collector state change</p>');
});

test('the timeout and suspension reasons are unchanged', function () {
	expect(logout_reason_run('timeout')['output'])->toContain('<p>You have been logged out of Cacti due to a session timeout.</p>')
		->and(logout_reason_run('disabled')['output'])->toContain('<p>You have been logged out of Cacti due to an account suspension.</p>');
});

test('an unknown action gets no automatic logout page', function () {
	$result = logout_reason_run('remove');

	expect($result['output'])->not->toContain('Automatic Logout')
		->and($result['output'])->not->toContain('Remote Data Collector');
});
