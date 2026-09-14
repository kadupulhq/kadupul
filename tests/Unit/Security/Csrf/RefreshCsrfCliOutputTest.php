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
 * Runs cli/refresh_csrf.php in a child process with the database settings
 * stubbed. Wrappers read its messages and exit status, so both must match
 * 1.2.31 wherever the secret itself still lands where 1.2.x now keeps it.
 */

require_once dirname(__DIR__, 3) . '/Helpers/AuthEntryProbe.php';

if (!function_exists('refresh_csrf_cli_run')) {
	/**
	 * @param array<string, mixed> $scenario
	 *
	 * @return array{output: string, exit: int, settings: array<string, string>}
	 */
	function refresh_csrf_cli_run(array $scenario) : array {
		$root   = dirname(__DIR__, 4);
		$script = file_get_contents($root . '/cli/refresh_csrf.php');
		$csrf   = file_get_contents($root . '/include/csrf.php');
		$magic  = file_get_contents($root . '/include/vendor/csrf/csrf-magic.php');

		$script = preg_replace('/^#![^\n]*\n<\?php/', '', $script);
		$script = preg_replace('/^require(?:_once)?\(.*\);\n/m', '', $script);

		$helpers = '';

		foreach (array('cacti_csrf_external_secret_path', 'cacti_csrf_external_path_is_safe') as $name) {
			$helpers .= cacti_test_function_source($csrf, $name) . "\n";
		}

		foreach (array('csrf_generate_secret', 'csrf_write_secret_atomic') as $name) {
			$helpers .= cacti_test_function_source($magic, $name) . "\n";
		}

		$source = '<?php
			$scenario = json_decode(getenv("REFRESH_CSRF_SCENARIO"), true);
			$config   = array("base_path" => $scenario["base_path"]);
			$settings = array();

			if ($scenario["external"] !== "") {
				$config["path_csrf_secret"] = $scenario["external"];
			}

			$_SERVER["argv"] = array("refresh_csrf.php");

			define("COPYRIGHT_YEARS", "2004-2026");

			function cacti_sizeof($value) { return is_array($value) ? count($value) : 0; }
			function get_cacti_cli_version() { return "1.2.32"; }

			function set_config_option($name, $value, $remote = false) {
				global $settings, $scenario;

				if (empty($scenario["db_fails"])) {
					$settings[$name] = $value;
				}
			}

			function read_config_option($name, $force = false) {
				global $settings;

				return $settings[$name] ?? "";
			}

			register_shutdown_function(function () {
				global $settings, $scenario;

				file_put_contents($scenario["settings_file"], json_encode($settings));
			});
			' . $helpers . $script;

		$file     = tempnam(sys_get_temp_dir(), 'refresh_csrf_');
		$settings = tempnam(sys_get_temp_dir(), 'refresh_csrf_settings_');

		file_put_contents($file, $source);

		$scenario['settings_file'] = $settings;

		$pipes = array();
		$env   = array('REFRESH_CSRF_SCENARIO' => json_encode($scenario), 'PATH' => getenv('PATH'));
		$proc  = proc_open(
			array(PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'error_reporting=' . (E_ALL & ~E_DEPRECATED), $file),
			array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
			$pipes,
			null,
			$env
		);

		$output = stream_get_contents($pipes[1]);
		$errors = stream_get_contents($pipes[2]);

		fclose($pipes[1]);
		fclose($pipes[2]);

		$exit   = proc_close($proc);
		$stored = json_decode((string) file_get_contents($settings), true);

		unlink($file);
		unlink($settings);

		if ($errors !== '') {
			throw new RuntimeException('refresh_csrf.php wrote to stderr: ' . $errors);
		}

		return array('output' => $output, 'exit' => $exit, 'settings' => is_array($stored) ? $stored : array());
	}

	/**
	 * @return array{base: string, outside: string}
	 */
	function refresh_csrf_cli_dirs() : array {
		$top = sys_get_temp_dir() . '/refresh_csrf_' . bin2hex(random_bytes(6));

		mkdir($top . '/cacti/include/vendor/csrf', 0700, true);
		mkdir($top . '/outside', 0700, true);

		return array('base' => realpath($top . '/cacti'), 'outside' => realpath($top . '/outside'));
	}
}

$start = "NOTE: Updating csrf_secret file with new information\n";

test('rotating an existing external secret file prints the 1.2.31 messages', function () use ($start) {
	$dirs = refresh_csrf_cli_dirs();
	$path = $dirs['outside'] . '/csrf-secret.php';

	file_put_contents($path, str_repeat('a', 40));

	$run = refresh_csrf_cli_run(array('base_path' => $dirs['base'], 'external' => $path));

	expect($run['output'])->toBe($start . "NOTE: Removing old csrf_secret.php file.\nNOTE: New csrf_secret.php file written.\n")
		->and($run['exit'])->toBe(0)
		->and(trim(file_get_contents($path)))->toMatch('/^[a-f0-9]{64}$/');
});

test('a missing external secret file warns, then writes it', function () use ($start) {
	$dirs = refresh_csrf_cli_dirs();
	$path = $dirs['outside'] . '/csrf-secret.php';

	$run = refresh_csrf_cli_run(array('base_path' => $dirs['base'], 'external' => $path));

	expect($run['output'])->toBe($start . "WARNING: csrf_secret.php file does not exist!\nNOTE: New csrf_secret.php file written.\n")
		->and($run['exit'])->toBe(0)
		->and(is_file($path))->toBeTrue();
});

test('a read-only external secret file stops with the 1.2.31 fatal message', function () use ($start) {
	if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
		$this->markTestSkipped('root can write a read-only file');
	}

	$dirs = refresh_csrf_cli_dirs();
	$path = $dirs['outside'] . '/csrf-secret.php';

	file_put_contents($path, str_repeat('a', 40));
	chmod($path, 0400);

	$run = refresh_csrf_cli_run(array('base_path' => $dirs['base'], 'external' => $path));

	chmod($path, 0600);

	expect($run['output'])->toBe($start . "FATAL: unable to unlink csrf_secret.php!\n")
		->and($run['exit'])->toBe(1)
		->and(file_get_contents($path))->toBe(str_repeat('a', 40));
});

test('an unwritable external directory stops with the 1.2.31 write failure', function () use ($start) {
	if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
		$this->markTestSkipped('root can write a read-only directory');
	}

	$dirs = refresh_csrf_cli_dirs();
	$path = $dirs['outside'] . '/csrf-secret.php';

	chmod($dirs['outside'], 0500);

	$run = refresh_csrf_cli_run(array('base_path' => $dirs['base'], 'external' => $path));

	chmod($dirs['outside'], 0700);

	expect($run['output'])->toBe($start . "WARNING: csrf_secret.php file does not exist!\nFATAL: Unable to write new csrf_secret.php file.\n")
		->and($run['exit'])->toBe(1);
});

test('the default secret rotates in settings with the 1.2.31 messages', function () use ($start) {
	$dirs = refresh_csrf_cli_dirs();

	$run = refresh_csrf_cli_run(array('base_path' => $dirs['base'], 'external' => ''));

	expect($run['output'])->toBe($start . "WARNING: csrf_secret.php file does not exist!\nNOTE: New csrf_secret.php file written.\n")
		->and($run['exit'])->toBe(0)
		->and($run['settings']['csrf_secret'] ?? '')->toMatch('/^[a-f0-9]{64}$/');
});

test('a leftover vendor secret file is reported and removed', function () use ($start) {
	$dirs   = refresh_csrf_cli_dirs();
	$legacy = $dirs['base'] . '/include/vendor/csrf/csrf-secret.php';

	file_put_contents($legacy, '5829483104da972116dde2a1e1e444d52df679c0');

	$run = refresh_csrf_cli_run(array('base_path' => $dirs['base'], 'external' => ''));

	expect($run['output'])->toBe($start . "NOTE: Removing old csrf_secret.php file.\nNOTE: New csrf_secret.php file written.\n")
		->and($run['exit'])->toBe(0)
		->and(file_exists($legacy))->toBeFalse();
});

test('an external rotation removes a leftover vendor secret file', function () use ($start) {
	$dirs   = refresh_csrf_cli_dirs();
	$path   = $dirs['outside'] . '/csrf-secret.php';
	$legacy = $dirs['base'] . '/include/vendor/csrf/csrf-secret.php';

	file_put_contents($legacy, '5829483104da972116dde2a1e1e444d52df679c0');

	$run = refresh_csrf_cli_run(array('base_path' => $dirs['base'], 'external' => $path));

	expect($run['output'])->toBe($start . "WARNING: csrf_secret.php file does not exist!\nNOTE: New csrf_secret.php file written.\n")
		->and($run['exit'])->toBe(0)
		->and(file_exists($legacy))->toBeFalse();
});

test('a failed external rotation leaves the vendor secret file in place', function () use ($start) {
	if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
		$this->markTestSkipped('root can write a read-only directory');
	}

	$dirs   = refresh_csrf_cli_dirs();
	$path   = $dirs['outside'] . '/csrf-secret.php';
	$legacy = $dirs['base'] . '/include/vendor/csrf/csrf-secret.php';

	file_put_contents($legacy, '5829483104da972116dde2a1e1e444d52df679c0');
	chmod($dirs['outside'], 0500);

	$run = refresh_csrf_cli_run(array('base_path' => $dirs['base'], 'external' => $path));

	chmod($dirs['outside'], 0700);

	expect($run['output'])->toBe($start . "WARNING: csrf_secret.php file does not exist!\nFATAL: Unable to write new csrf_secret.php file.\n")
		->and($run['exit'])->toBe(1)
		->and(file_exists($legacy))->toBeTrue();
});

test('a failed settings rotation leaves the vendor secret file in place', function () use ($start) {
	$dirs   = refresh_csrf_cli_dirs();
	$legacy = $dirs['base'] . '/include/vendor/csrf/csrf-secret.php';

	file_put_contents($legacy, '5829483104da972116dde2a1e1e444d52df679c0');

	$run = refresh_csrf_cli_run(array('base_path' => $dirs['base'], 'external' => '', 'db_fails' => true));

	expect($run['output'])->toBe($start . "NOTE: Removing old csrf_secret.php file.\nFATAL: Unable to write new csrf_secret.php file.\n")
		->and($run['exit'])->toBe(1)
		->and(file_exists($legacy))->toBeTrue();
});

test('a settings write that does not stick fails with the 1.2.31 write failure', function () use ($start) {
	$dirs = refresh_csrf_cli_dirs();

	$run = refresh_csrf_cli_run(array('base_path' => $dirs['base'], 'external' => '', 'db_fails' => true));

	expect($run['output'])->toBe($start . "WARNING: csrf_secret.php file does not exist!\nFATAL: Unable to write new csrf_secret.php file.\n")
		->and($run['exit'])->toBe(1);
});

test('an external secret inside the document root is still refused', function () use ($start) {
	$dirs = refresh_csrf_cli_dirs();
	$path = $dirs['base'] . '/include/csrf-secret.php';

	$run = refresh_csrf_cli_run(array('base_path' => $dirs['base'], 'external' => $path));

	expect($run['output'])->toBe($start . "FATAL: The configured CSRF secret must be outside the Cacti document root.\n")
		->and($run['exit'])->toBe(1)
		->and(file_exists($path))->toBeFalse();
});
