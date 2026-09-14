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
 * get_basic_auth_username() returns the same user as 1.2.31 for every server
 * variable a web server sets. The HTTP_* request header copies that 1.2.31
 * also read are still ignored.
 */

require_once dirname(__DIR__, 3) . '/Helpers/AuthEntryProbe.php';

function basic_username_release_source() : ?string {
	static $source = false;

	if ($source === false) {
		$output = shell_exec('git -C ' . escapeshellarg(dirname(__DIR__, 4)) . ' show release/1.2.31:lib/auth.php 2>/dev/null');
		$source = (is_string($output) && strpos($output, "\nfunction get_basic_auth_username(") !== false) ? $output : null;
	}

	return $source;
}

/**
 * @param array<string, string> $server
 *
 * @return string|false
 */
function basic_username_run(array $server, int $auth_method = 2, ?string $source = null) {
	$auth = $source ?? file_get_contents(dirname(__DIR__, 4) . '/lib/auth.php');

	$child = <<<'PHP'
<?php
$scenario = json_decode(stream_get_contents(STDIN), true);

foreach (array('PHP_AUTH_USER', 'REMOTE_USER', 'REDIRECT_REMOTE_USER', 'HTTP_PHP_AUTH_USER', 'HTTP_REMOTE_USER', 'HTTP_REDIRECT_REMOTE_USER') as $key) {
	unset($_SERVER[$key]);
}

foreach ($scenario['server'] as $key => $value) {
	$_SERVER[$key] = $value;
}

function read_config_option($name, $force = false) {
	return $name == 'auth_method' ? $GLOBALS['scenario']['auth_method'] : '';
}

function cacti_log($string, $output = false, $environ = 'CMDPHP', $level = '') {
}

function cacti_sizeof($array) {
	return is_array($array) ? count($array) : 0;
}

PHP;

	$child .= cacti_test_function_source($auth, 'get_basic_auth_username') . "\n\n";
	$child .= "print json_encode(array('username' => get_basic_auth_username()));\n";

	return cacti_test_run_php_source($child, array('server' => $server, 'auth_method' => $auth_method))['username'];
}

/**
 * @return array<string, array<string, string>>
 */
function basic_username_server_variables() : array {
	return array(
		'PHP_AUTH_USER only'                 => array('PHP_AUTH_USER' => 'alice'),
		'REMOTE_USER only'                   => array('REMOTE_USER' => 'alice'),
		'REDIRECT_REMOTE_USER only'          => array('REDIRECT_REMOTE_USER' => 'alice'),
		'PHP_AUTH_USER and REMOTE_USER'      => array('PHP_AUTH_USER' => 'alice', 'REMOTE_USER' => 'bob'),
		'REMOTE_USER and REDIRECT'           => array('REMOTE_USER' => 'alice', 'REDIRECT_REMOTE_USER' => 'bob'),
		'Kerberos principal'                 => array('PHP_AUTH_USER' => 'alice@EXAMPLE.COM'),
		'Windows domain user'                => array('PHP_AUTH_USER' => 'CORP\\alice'),
		'PHP_AUTH_USER with a header copy'   => array('PHP_AUTH_USER' => 'alice', 'HTTP_REMOTE_USER' => 'admin'),
		'no user'                            => array(),
	);
}

test('PHP_AUTH_USER is the Web Basic user when the server passes only that', function () {
	expect(basic_username_run(array('PHP_AUTH_USER' => 'alice')))->toBe('alice')
		->and(basic_username_run(array('PHP_AUTH_USER' => 'alice', 'REMOTE_USER' => 'bob')))->toBe('alice');
});

test('server variables give the same Web Basic user as 1.2.31', function () {
	$release = basic_username_release_source();

	foreach (basic_username_server_variables() as $name => $server) {
		expect(basic_username_run($server))->toBe(basic_username_run($server, 2, $release), $name);
	}

	expect(basic_username_run(array('PHP_AUTH_USER' => 'alice'), 1))->toBe(basic_username_run(array('PHP_AUTH_USER' => 'alice'), 1, $release));
})->skip(function () {
	return basic_username_release_source() === null;
}, 'release/1.2.31 is not available in this clone');

test('request header copies are still ignored', function () {
	foreach (array('HTTP_PHP_AUTH_USER', 'HTTP_REMOTE_USER', 'HTTP_REDIRECT_REMOTE_USER') as $key) {
		expect(basic_username_run(array($key => 'admin')))->toBeFalse($key);
	}
});

test('no Web Basic user is read unless Web Basic authentication is configured', function () {
	expect(basic_username_run(array('PHP_AUTH_USER' => 'alice'), 1))->toBeFalse()
		->and(basic_username_run(array('PHP_AUTH_USER' => 'alice'), 3))->toBeFalse();
});
