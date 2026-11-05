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
 * Inline script tags throughout a page ask CactiSecureHeaders for the CSP
 * nonce. emitHeaders() now generates it before any output, so when no secure
 * random source works the request ends with a short error page instead of a
 * page that stops partway through. The production class runs in a child
 * process whose random sources are replaced.
 */

function nonceFailurePageRun(bool $entropy, string $mode): array {
	$dir    = sys_get_temp_dir() . '/nonce-page-' . bin2hex(random_bytes(8));
	$source = file_get_contents(dirname(__DIR__, 4) . '/lib/headers_secure.php');
	mkdir($dir, 0700);

	$stubs = 'namespace NonceFailurePage;'
		. '$GLOBALS["entropy"] = ' . var_export($entropy, true) . '; $GLOBALS["mode"] = ' . var_export($mode, true) . ';' . <<<'PHP'
function function_exists($name) {
	return in_array($name, array('random_bytes', 'openssl_random_pseudo_bytes', 'cacti_log', 'read_config_option'), true);
}
function random_bytes($length) {
	if (!$GLOBALS['entropy']) { throw new \Exception('entropy unavailable'); }
	return str_repeat("\xfb", $length);
}
function openssl_random_pseudo_bytes($length, &$strong) {
	$strong = false;
	return false;
}
function cacti_log($message, ...$args) { file_put_contents($GLOBALS['log'], $message . "\n", FILE_APPEND); }
function read_config_option($name) { return $name === 'content_security_policy_script' ? $GLOBALS['mode'] : ''; }
PHP;

	$program = '<?php $GLOBALS["log"] = ' . var_export($dir . '/log', true) . ';'
		. 'eval(' . var_export($stubs . substr($source, 5), true) . ');'
		. '\NonceFailurePage\CactiSecureHeaders::emitHeaders();'
		. 'print "<script " . \NonceFailurePage\CactiSecureHeaders::getNonceAttribute() . "></script>BODY";';

	file_put_contents($dir . '/probe.php', $program);

	try {
		$process = proc_open(array(PHP_BINARY, '-d', 'display_errors=stderr', $dir . '/probe.php'), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
		$output  = stream_get_contents($pipes[1]);
		$error   = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		$status  = proc_close($process);
		$log     = is_file($dir . '/log') ? file_get_contents($dir . '/log') : '';
	} finally {
		foreach (glob($dir . '/*') as $file) {
			unlink($file);
		}

		rmdir($dir);
	}

	return array($status, $output, $error, $log);
}

test('a nonce failure ends the request with an error page before any output', function ($mode) {
	list($status, $output, $error, $log) = nonceFailurePageRun(false, $mode);

	expect($error)->toBe('')
		->and($status)->toBe(0)
		->and($output)->toBe('This page cannot be shown because the server could not generate secure random data. Ask the administrator to check the Cacti log.' . PHP_EOL)
		->and($log)->toContain('secure CSP nonce could not be generated');
})->with(array('default' => '', 'nonce' => 'nonce', 'nonce-report' => 'nonce-report'));

test('with a working random source the page is served as before', function ($mode) {
	list($status, $output, $error, $log) = nonceFailurePageRun(true, $mode);

	expect($error)->toBe('')
		->and($status)->toBe(0)
		->and($output)->toMatch('/^<script nonce="[A-Za-z0-9_-]{24}"><\/script>BODY$/')
		->and($log)->toBe('');
})->with(array('default' => '', 'nonce' => 'nonce'));
