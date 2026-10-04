<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2026 The Kadupul project and contributors                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * The change password page checks the host in its ref parameter. It used to
 * resolve that host, and the server's own name, through DNS, so any visitor
 * could make the server send lookups for a name of their choosing. The host
 * is now compared by name through validate_redirect_url(). The ref block of
 * the shipped page runs in a child process with the shipped redirect check.
 */

require_once dirname(__DIR__, 3) . '/Helpers/AuthEntryProbe.php';

function change_password_ref_source() : string {
	$source = file_get_contents(dirname(__DIR__, 4) . '/auth_changepassword.php');

	expect($source)->not->toBeFalse();

	return $source;
}

function change_password_ref_block() : string {
	$source = change_password_ref_source();
	$start  = strpos($source, "if (isset_request_var('ref')) {");

	expect($start)->not->toBeFalse();

	$end = strpos($source, "\n}\n", strpos($source, 'if (!$valid) {', $start));

	return substr($source, $start, $end + 3 - $start);
}

function change_password_ref_run(string $ref, string $server_name = 'cacti.example.com') : array {
	$root      = dirname(__DIR__, 4);
	$functions = file_get_contents($root . '/lib/functions.php');
	$html      = file_get_contents($root . '/lib/html_utility.php');

	$source = <<<'PHP'
<?php
$scenario = json_decode(stream_get_contents(STDIN), true);

$config  = array('url_path' => '/cacti/', 'trusted_hosts' => array());
$_SERVER = array('SERVER_NAME' => $scenario['server_name'], 'SERVER_ADDR' => '192.0.2.1', 'SERVER_PORT' => '443');
$GLOBALS['logged'] = array();

define('MESSAGE_LEVEL_ERROR', 3);

function isset_request_var($name) {
	return $name == 'ref';
}

function get_nfilter_request_var($name, $default = '') {
	return $name == 'ref' ? $GLOBALS['scenario']['ref'] : $default;
}

function cacti_log($message, ...$args) {
	$GLOBALS['logged'][] = $message;
}

function raise_message(...$args) {
}

function __($text, ...$args) {
	return vsprintf($text, $args);
}

function cacti_sizeof($array) {
	return is_array($array) ? count($array) : 0;
}

register_shutdown_function(function () {
	print json_encode(array('accepted' => !empty($GLOBALS['accepted']), 'logged' => $GLOBALS['logged']));
});

PHP;

	$source .= cacti_test_function_source($functions, 'sanitize_uri') . "\n\n";
	$source .= cacti_test_function_source($functions, 'is_urlencoded') . "\n\n";
	$source .= cacti_test_function_source($html, 'validate_redirect_url') . "\n\n";
	$source .= cacti_test_function_source($html, 'cacti_trusted_host_header') . "\n\n";
	$source .= change_password_ref_block() . "\n";
	$source .= "\$GLOBALS['accepted'] = true;\n";

	return cacti_test_run_php_source($source, array('ref' => $ref, 'server_name' => $server_name));
}

test('the change password page makes no DNS lookups', function () {
	$calls = array();

	foreach (token_get_all(change_password_ref_source()) as $token) {
		if (is_array($token) && $token[0] === T_STRING) {
			$calls[] = strtolower($token[1]);
		}
	}

	expect(array_intersect($calls, array('gethostbyname', 'gethostbynamel', 'dns_get_record', 'checkdnsrr', 'getmxrr', 'gethostbyaddr')))->toBe(array());
});

test('a local ref path is accepted as before', function () {
	$result = change_password_ref_run('/cacti/graph_view.php?action=tree');

	expect($result['accepted'])->toBeTrue()
		->and($result['logged'])->toBe(array());
});

test('a ref on the server\'s own host is accepted', function () {
	$result = change_password_ref_run('https://cacti.example.com/cacti/index.php');

	expect($result['accepted'])->toBeTrue();
});

test('a ref on another host is refused', function () {
	$result = change_password_ref_run('https://elsewhere.example.net/cacti/index.php');

	expect($result['accepted'])->toBeFalse()
		->and($result['logged'])->toBe(array('WARNING: User attempted to access Cacti from unknown URL'));
});

test('a ref carrying credentials is refused', function () {
	$result = change_password_ref_run('https://user:secret@cacti.example.com/cacti/index.php');

	expect($result['accepted'])->toBeFalse();
});
