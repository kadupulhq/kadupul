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
 * Runs csrf_startup() from include/csrf.php in a child process. A bad
 * $path_csrf_secret used to answer every page with HTTP 500; 1.2.31 kept
 * serving pages, so the database secret takes over with a warning.
 */

require_once dirname(__DIR__, 3) . '/Helpers/AuthEntryProbe.php';

if (!function_exists('csrf_external_secret_fallback_run')) {
	/**
	 * @param array<string, mixed> $scenario
	 *
	 * @return array<string, mixed>
	 */
	function csrf_external_secret_fallback_run(array $scenario) : array {
		$csrf = file_get_contents(dirname(__DIR__, 4) . '/include/csrf.php');

		$functions = array(
			'csrf_startup',
			'cacti_csrf_install_pending',
			'cacti_csrf_read_external_secret',
			'cacti_csrf_secret_is_valid',
			'cacti_csrf_parse_secret_contents',
			'cacti_csrf_external_secret_path',
			'cacti_csrf_external_path_is_safe',
		);

		$source = '<?php
			ob_start();

			$scenario = json_decode(stream_get_contents(STDIN), true);
			$config   = array("is_web" => true, "base_path" => $scenario["base_path"], "url_path" => "/cacti/");
			$state    = array("conf" => array(), "logs" => array());
			$_SESSION = array();

			if ($scenario["external"] !== "") {
				$config["path_csrf_secret"] = $scenario["external"];
			}

			function csrf_conf($key, $value) { global $state; $state["conf"][$key] = $value; }
			function read_config_option($name, $force = false) { global $scenario; return $name === "csrf_secret" ? $scenario["db_secret"] : ""; }
			function cacti_log($message, $output = false, $environ = "CMDPHP") { global $state; $state["logs"][] = $environ . ": " . $message; }
			function csrf_generate_secret() { return str_repeat("b", 64); }

			register_shutdown_function(function () {
				global $state;

				$state["body"] = ob_get_clean();
				$state["code"] = http_response_code();

				print json_encode($state);
			});
		';

		foreach ($functions as $name) {
			$source .= cacti_test_function_source($csrf, $name) . "\n";
		}

		$source .= '
			foreach ($scenario["sessions"] as $requests) {
				$_SESSION = array();

				for ($i = 0; $i < $requests; $i++) {
					csrf_startup();
				}
			}
		';

		return cacti_test_run_php_source($source, $scenario);
	}

	/**
	 * @return array{base: string, outside: string}
	 */
	function csrf_external_secret_fallback_dirs() : array {
		$top = sys_get_temp_dir() . '/csrf_fallback_' . bin2hex(random_bytes(6));

		mkdir($top . '/cacti/include', 0700, true);
		mkdir($top . '/outside', 0700, true);

		return array('base' => realpath($top . '/cacti'), 'outside' => realpath($top . '/outside'));
	}
}

test('a missing external secret falls back to the database secret instead of HTTP 500', function () {
	$dirs = csrf_external_secret_fallback_dirs();

	$run = csrf_external_secret_fallback_run(array(
		'base_path' => $dirs['base'],
		'external'  => $dirs['outside'] . '/missing/csrf-secret.php',
		'db_secret' => str_repeat('c', 64),
		'sessions'  => array(1),
	));

	expect($run['code'])->toBeFalse()
		->and($run['body'])->toBe('')
		->and($run['conf']['secret'] ?? null)->toBe(str_repeat('c', 64))
		->and($run['conf']['callback'] ?? null)->toBe('csrf_error_callback');
});

test('the fallback warning is logged once per session', function () {
	$dirs = csrf_external_secret_fallback_dirs();

	$run = csrf_external_secret_fallback_run(array(
		'base_path' => $dirs['base'],
		'external'  => $dirs['outside'] . '/missing/csrf-secret.php',
		'db_secret' => str_repeat('c', 64),
		'sessions'  => array(3, 2),
	));

	expect($run['logs'])->toHaveCount(2)
		->and($run['logs'][0])->toStartWith('SYSTEM: WARNING: ')
		->and($run['logs'][0])->toContain('external CSRF secret')
		->and($run['logs'][1])->toBe($run['logs'][0]);
});

test('an external secret inside the document root or too short also falls back', function () {
	$dirs = csrf_external_secret_fallback_dirs();

	file_put_contents($dirs['base'] . '/include/csrf-secret.php', str_repeat('a', 64));
	file_put_contents($dirs['outside'] . '/short-secret.php', 'short');

	foreach (array($dirs['base'] . '/include/csrf-secret.php', $dirs['outside'] . '/short-secret.php') as $path) {
		$run = csrf_external_secret_fallback_run(array(
			'base_path' => $dirs['base'],
			'external'  => $path,
			'db_secret' => str_repeat('c', 64),
			'sessions'  => array(1),
		));

		expect($run['code'])->toBeFalse()
			->and($run['conf']['secret'] ?? null)->toBe(str_repeat('c', 64))
			->and($run['logs'])->toHaveCount(1);
	}
});

test('with no usable database secret either, the session bootstrap secret keeps pages up', function () {
	$dirs = csrf_external_secret_fallback_dirs();

	$run = csrf_external_secret_fallback_run(array(
		'base_path' => $dirs['base'],
		'external'  => $dirs['outside'] . '/missing/csrf-secret.php',
		'db_secret' => '',
		'sessions'  => array(1),
	));

	expect($run['code'])->toBeFalse()
		->and($run['conf']['secret'] ?? null)->toBe(str_repeat('b', 64));
});

test('the warning names the database secret when that is the secret in use', function () {
	$dirs = csrf_external_secret_fallback_dirs();

	$run = csrf_external_secret_fallback_run(array(
		'base_path' => $dirs['base'],
		'external'  => $dirs['outside'] . '/missing/csrf-secret.php',
		'db_secret' => str_repeat('c', 64),
		'sessions'  => array(2),
	));

	expect($run['logs'])->toBe(array(
		'SYSTEM: WARNING: The configured external CSRF secret is unavailable or invalid, using the database secret instead',
	));
});

test('the warning names the session bootstrap secret when the database secret is unusable too', function () {
	$dirs = csrf_external_secret_fallback_dirs();

	$run = csrf_external_secret_fallback_run(array(
		'base_path' => $dirs['base'],
		'external'  => $dirs['outside'] . '/missing/csrf-secret.php',
		'db_secret' => 'short',
		'sessions'  => array(3),
	));

	expect($run['conf']['secret'] ?? null)->toBe(str_repeat('b', 64))
		->and($run['logs'])->toBe(array(
			'SYSTEM: WARNING: The configured external CSRF secret is unavailable or invalid, using the session bootstrap secret instead',
		));
});

test('a good external secret still wins over the database secret without a warning', function () {
	$dirs = csrf_external_secret_fallback_dirs();
	$path = $dirs['outside'] . '/csrf-secret.php';

	file_put_contents($path, str_repeat('a', 64) . "\n");

	$run = csrf_external_secret_fallback_run(array(
		'base_path' => $dirs['base'],
		'external'  => $path,
		'db_secret' => str_repeat('c', 64),
		'sessions'  => array(2),
	));

	expect($run['code'])->toBeFalse()
		->and($run['conf']['secret'] ?? null)->toBe(str_repeat('a', 64))
		->and($run['logs'])->toBe(array());
});
