<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 | Copyright (C) 2026 The Kadupul project and contributors                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

namespace InputValidationSecurityLogTest;

const CACTI_CLI = false;

$GLOBALS['validation_security_logs'] = array();
$GLOBALS['validation_client_addr']   = '192.0.2.10';
$GLOBALS['validation_random_failure'] = false;

/**
 * Returns deterministic bytes for correlation-ID assertions.
 *
 * @param int $length Requested byte length.
 *
 * @return string Deterministic byte string.
 */
function random_bytes($length) {
	if ($GLOBALS['validation_random_failure']) {
		throw new \Exception('No entropy available');
	}

	return str_repeat("\x2a", $length);
}

/**
 * Returns a fixed client address for security-event assertions.
 *
 * @return string|false Test client address.
 */
function get_client_addr() {
	return $GLOBALS['validation_client_addr'];
}

/**
 * Captures structured security log entries.
 *
 * @param string $message Log message.
 * @param bool   $output  Whether to print the message.
 * @param string $environ Log subsystem.
 *
 * @return bool Always true for the unit test.
 */
function cacti_log($message, $output, $environ) {
	$GLOBALS['validation_security_logs'][] = array($message, $output, $environ);

	return true;
}

$source = file_get_contents(dirname(__DIR__, 4) . '/lib/html_validate.php');

if ($source === false) {
	throw new \RuntimeException('Unable to read lib/html_validate.php for the validation security-log test.');
}

if (preg_match('/function security_log_input_validation_failure\(.*?^}\R/ms', $source, $matches) !== 1) {
	throw new \RuntimeException('Unable to extract security_log_input_validation_failure() for the validation security-log test.');
}

eval('namespace InputValidationSecurityLogTest;' . $matches[0]);

beforeEach(function () {
	$GLOBALS['validation_security_logs'] = array();
	$GLOBALS['validation_client_addr']   = '192.0.2.10';
	$GLOBALS['validation_random_failure'] = false;
	$_SERVER['REQUEST_METHOD']            = 'POST';
	$_SERVER['SCRIPT_NAME']               = '/cacti/graphs.php';
});

test('validation failures produce structured correlated security events', function () {
	$event_id = security_log_input_validation_failure('graph_id');
	$event    = json_decode($GLOBALS['validation_security_logs'][0][0], true);

	expect($event_id)->toBe(str_repeat('2a', 16))
		->and($event)->toBe(array(
			'event'          => 'input_validation_failure',
			'event_id'       => $event_id,
			'variable'       => 'graph_id',
			'source_address' => '192.0.2.10',
			'request_method' => 'POST',
			'script'         => 'graphs.php'
		))
		->and($GLOBALS['validation_security_logs'][0][1])->toBeFalse()
		->and($GLOBALS['validation_security_logs'][0][2])->toBe('SECURITY');
});

test('non-scalar variables and missing request metadata are handled safely', function () {
	unset($_SERVER['REQUEST_METHOD'], $_SERVER['SCRIPT_NAME']);
	$GLOBALS['validation_client_addr'] = false;

	security_log_input_validation_failure(array('secret' => 'not logged'));
	$event = json_decode($GLOBALS['validation_security_logs'][0][0], true);

	expect($event['variable'])->toBe('array')
		->and($event['source_address'])->toBe('')
		->and($event['request_method'])->toBe(PHP_SAPI)
		->and($event['script'])->toBe('')
		->and($GLOBALS['validation_security_logs'][0][0])->not->toContain('not logged');
});

test('entropy failures retain a usable correlation identifier', function () {
	$GLOBALS['validation_random_failure'] = true;

	$event_id = security_log_input_validation_failure('graph_id');
	$event    = json_decode($GLOBALS['validation_security_logs'][0][0], true);

	expect($event_id)->toMatch('/^[a-f0-9]{32}$/')
		->and($event['event_id'])->toBe($event_id);
});

/**
 * Runs the real die_html_input_error() in a child process, because it exits.
 *
 * The child loads the real redaction helpers from lib/functions.php. Before
 * the call it also evaluates the release/1.2.31 log expression against the
 * same request, so the parity check compares against that code, not a copy
 * of its output.
 *
 * @param array<string, string> $request  The request the failure happens in.
 * @param string                $variable The variable that failed validation.
 * @param string                $value    Its value.
 *
 * @return array<int, array{0: string, 1: string}> Log lines as [facility, text].
 */
function run_die_html_input_error(array $request, $variable = 'graph_id', $value = '7<x') {
	$root    = dirname(__DIR__, 4);
	$code    = 'namespace { const CACTI_CLI = false;'
		. '$_REQUEST = ' . var_export($request, true) . ';'
		. '$_SERVER["REQUEST_METHOD"] = "POST"; $_SERVER["SCRIPT_NAME"] = "/cacti/auth_login.php";'
		. 'function __esc($m) { return $m; } function __($m) { return $m; }'
		. 'function html_escape($s) { return htmlspecialchars((string) $s, ENT_QUOTES, "UTF-8"); }'
		. 'function get_client_addr() { return "192.0.2.10"; }'
		. 'function isset_request_var($n) { return isset($_REQUEST[$n]); }'
		. 'function bottom_footer() {}'
		. 'function cacti_log($m, $o = false, $e = "") { fwrite(STDERR, json_encode(array($e, $m)) . "\n"); }'
		. 'function cacti_debug_backtrace($m, $h = false) { fwrite(STDERR, json_encode(array("BACKTRACE", $m)) . "\n"); }'
		. '$functions = file_get_contents(' . var_export($root . '/lib/functions.php', true) . ');'
		. 'foreach (array("cacti_is_sensitive_key", "cacti_redact_sensitive", "cacti_redact_value") as $name) {'
		. '  if (preg_match("/^function " . $name . "\\\\(.*?^}\\\\R/ms", $functions, $m)) { eval($m[0]); }'
		. '}'
		. '$source = file_get_contents(' . var_export($root . '/lib/html_validate.php', true) . ');'
		. 'preg_match("/^function security_log_input_validation_failure\\\\(.*?^}\\\\R/ms", $source, $a);'
		. 'preg_match("/^function die_html_input_error\\\\(.*?^}\\\\R/ms", $source, $b);'
		. 'eval($a[0] . $b[0]);'
		. '$variable = ' . var_export($variable, true) . '; $value = ' . var_export($value, true) . ';'
		. 'fwrite(STDERR, json_encode(array("RELEASE_1.2.31", "Validation Error" . ($variable != "" ? ", Variable:" . html_escape($variable):"") . ($value != "" ? ", Value:" . html_escape($value):"") . ", Source: " . get_client_addr() . ", Request: " . json_encode($_REQUEST))) . "\n");'
		. 'ob_start(); die_html_input_error($variable, $value); }';
	$pipes   = array();
	$process = proc_open(array(PHP_BINARY, '-r', $code), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);

	expect($process)->not->toBeFalse();

	stream_get_contents($pipes[1]);
	$errors = stream_get_contents($pipes[2]);

	fclose($pipes[1]);
	fclose($pipes[2]);
	proc_close($process);

	$lines = array();

	foreach (array_filter(explode("\n", $errors)) as $line) {
		$decoded = json_decode($line, true);

		expect($decoded)->toBeArray($line);

		$lines[] = $decoded;
	}

	return $lines;
}

test('a failure without secrets logs the release/1.2.31 line byte for byte', function (bool $json) {
	$request = $json ? array('graph_id' => '7<x', 'json' => '1') : array('graph_id' => '7<x');
	$lines   = run_die_html_input_error($request);
	$text    = 'Validation Error, Variable:graph_id, Value:7&lt;x, Source: 192.0.2.10, Request: ' . json_encode($request);

	expect($lines)->toHaveCount(3)
		->and($lines[0])->toBe(array('RELEASE_1.2.31', $text))
		->and($lines[1][0])->toBe('SECURITY')
		->and(json_decode($lines[1][1], true)['event'])->toBe('input_validation_failure')
		->and($lines[2])->toBe(array('BACKTRACE', $text));
})->with(array('html reply' => false, 'json reply' => true));

test('a failure during login masks the password and CSRF token in every log line', function () {
	$request = array(
		'action'         => 'login',
		'login_username' => 'admin',
		'login_password' => 'hunter2',
		'__csrf_magic'   => 'sid:abc',
		'graph_id'       => '7<x',
	);
	$lines   = run_die_html_input_error($request);
	$logged  = implode("\n", array_map(function ($line) {
		return $line[1];
	}, array_slice($lines, 1)));

	expect($lines[2])->toBe(array('BACKTRACE', 'Validation Error, Variable:graph_id, Value:7&lt;x, Source: 192.0.2.10, Request: {"action":"login","login_username":"admin","login_password":"[REDACTED]","__csrf_magic":"[REDACTED]","graph_id":"7<x"}'))
		->and($logged)->not->toContain('hunter2')
		->and($logged)->not->toContain('sid:abc')
		->and(json_decode($lines[1][1], true))->not->toHaveKey('request');
});

test('a failing sensitive variable does not log its value', function () {
	$lines  = run_die_html_input_error(array('password' => 'hunter2'), 'password', 'hunter2');
	$logged = implode("\n", array_map(function ($line) {
		return $line[1];
	}, array_slice($lines, 1)));

	expect($lines[2])->toBe(array('BACKTRACE', 'Validation Error, Variable:password, Value:[REDACTED], Source: 192.0.2.10, Request: {"password":"[REDACTED]"}'))
		->and($logged)->not->toContain('hunter2');
});

test('the SECURITY event carries no request values', function () {
	$lines = run_die_html_input_error(array('login_password' => 'hunter2', 'note' => 'plain-value'), 'note', 'plain-value');
	$event = json_decode($lines[1][1], true);

	expect(array_keys($event))->toBe(array('event', 'event_id', 'variable', 'source_address', 'request_method', 'script'))
		->and($lines[1][1])->not->toContain('hunter2')
		->and($lines[1][1])->not->toContain('plain-value');
});

test('login, password change and token fields are treated as secrets', function () {
	$functions = file_get_contents(dirname(__DIR__, 4) . '/lib/functions.php');

	preg_match('/^function cacti_is_sensitive_key\(.*?^}\R/ms', $functions, $matches);

	expect($matches)->toHaveKey(0);

	$check = eval('return function ($key) {' . preg_replace('/^function cacti_is_sensitive_key\(\$key\) \{/', '', rtrim($matches[0])) . ';');

	foreach (array('login_password', 'password', 'password_confirm', 'current_password', 'ldap_password', 'dbpass', 'token', '__csrf_magic', 'snmp_password', 'snmp_priv_passphrase', 'snmp_auth_passphrase', 'path_csrf_secret', 'settings_ldap_password', 'snmp_community') as $key) {
		expect($check($key))->toBeTrue($key);
	}

	foreach (array('graph_id', 'action', 'login_username', 'host_id', 'rfilter', 'json') as $key) {
		expect($check($key))->toBeFalse($key);
	}
});

test('both Validation Error lines pass the request and value through the redaction helpers', function () {
	$source = file_get_contents(dirname(__DIR__, 4) . '/lib/html_validate.php');

	expect(substr_count($source, "', Request: ' . json_encode(cacti_redact_sensitive(\$_REQUEST))"))->toBe(2)
		->and(substr_count($source, "', Value:' . html_escape(cacti_redact_value(\$variable, \$value))"))->toBe(2)
		->and($source)->not->toContain('json_encode($_REQUEST)')
		->and($source)->not->toContain('Validation Error, Event:')
		->and($source)->toContain('security_log_input_validation_failure($variable);');
});
