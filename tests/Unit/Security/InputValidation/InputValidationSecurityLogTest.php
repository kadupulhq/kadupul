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
 * @param bool $json Whether the request asked for a JSON reply.
 *
 * @return array<int, array{0: string, 1: string}> Log lines as [facility, text].
 */
function run_die_html_input_error($json) {
	$library = dirname(__DIR__, 4) . '/lib/html_validate.php';
	$code    = 'namespace { const CACTI_CLI = false;'
		. '$_REQUEST = array("graph_id" => "7<x", "json" => ' . ($json ? '"1"' : 'null') . ');'
		. 'if (' . ($json ? 'false' : 'true') . ') { unset($_REQUEST["json"]); }'
		. '$_SERVER["REQUEST_METHOD"] = "GET"; $_SERVER["SCRIPT_NAME"] = "/cacti/graphs.php";'
		. 'function __esc($m) { return $m; } function __($m) { return $m; }'
		. 'function html_escape($s) { return htmlspecialchars((string) $s, ENT_QUOTES, "UTF-8"); }'
		. 'function get_client_addr() { return "192.0.2.10"; }'
		. 'function isset_request_var($n) { return isset($_REQUEST[$n]); }'
		. 'function bottom_footer() {}'
		. 'function cacti_log($m, $o = false, $e = "") { fwrite(STDERR, json_encode(array($e, $m)) . "\n"); }'
		. 'function cacti_debug_backtrace($m, $h = false) { fwrite(STDERR, json_encode(array("BACKTRACE", $m)) . "\n"); }'
		. '$source = file_get_contents(' . var_export($library, true) . ');'
		. 'preg_match("/^function security_log_input_validation_failure\\\\(.*?^}\\\\R/ms", $source, $a);'
		. 'preg_match("/^function die_html_input_error\\\\(.*?^}\\\\R/ms", $source, $b);'
		. 'eval($a[0] . $b[0]);'
		. 'ob_start(); die_html_input_error("graph_id", "7<x"); }';
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

test('validation failures log the SECURITY event and the 1.2.31 backtrace line', function (bool $json) {
	$lines   = run_die_html_input_error($json);
	$request = $json ? '{"graph_id":"7<x","json":"1"}' : '{"graph_id":"7<x"}';

	expect($lines)->toHaveCount(2)
		->and($lines[0][0])->toBe('SECURITY')
		->and(json_decode($lines[0][1], true)['event'])->toBe('input_validation_failure')
		->and($lines[1])->toBe(array('BACKTRACE', 'Validation Error, Variable:graph_id, Value:7&lt;x, Source: 192.0.2.10, Request: ' . $request));
})->with(array('html reply' => false, 'json reply' => true));

test('the backtrace line carries no correlation id', function () {
	$source = file_get_contents(dirname(__DIR__, 4) . '/lib/html_validate.php');

	expect($source)->toContain('security_log_input_validation_failure($variable);')
		->and($source)->not->toContain('Validation Error, Event:')
		->and(substr_count($source, "cacti_debug_backtrace('Validation Error' . (\$variable != ''"))->toBe(2)
		->and($source)->toContain("\$source_address = CACTI_CLI ? '' : get_client_addr();");
});
