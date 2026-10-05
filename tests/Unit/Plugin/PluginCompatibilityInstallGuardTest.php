<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-2.0-or-later

test('plugin install rejects incompatible or invalid core metadata before setup and database writes', function () {
	$application = dirname(__DIR__, 3);
	$temporary = sys_get_temp_dir() . '/plugin-compatibility-' . bin2hex(random_bytes(8));
	$pluginRoot = $temporary . '/plugins';
	mkdir($pluginRoot, 0700, true);

	$bootstrap = $temporary . '/bootstrap.php';
	$bootstrapSource = <<<'PHP'
<?php
define('CACTI_VERSION', '1.2.31');
function __($message, ...$arguments) { return $arguments ? vsprintf($message, $arguments) : $message; }
function cacti_sizeof($value) { return is_array($value) ? count($value) : 0; }
function cacti_log(...$arguments) {}
function cacti_version_compare($left, $right, $operator = '>') { return version_compare($left, $right, $operator); }
function db_fetch_row_prepared(...$arguments) { return false; }
function db_execute_prepared(...$arguments) { file_put_contents(getenv('PLUGIN_TEST_WRITES'), "write\n", FILE_APPEND); return true; }
$config = ['base_path' => getenv('PLUGIN_TEST_ROOT')];
require getenv('PLUGIN_TEST_APPLICATION') . '/lib/plugins.php';
PHP;
	file_put_contents($bootstrap, $bootstrapSource);

	$cliSource = file_get_contents($application . '/cli/plugin_manage.php');
	$originalRequire = "require(__DIR__ . '/../include/cli_check.php');";
	expect($cliSource)->toContain($originalRequire);
	$cliSource = str_replace($originalRequire, "require getenv('PLUGIN_TEST_BOOTSTRAP');", $cliSource);
	$cli = $temporary . '/plugin_manage.php';
	file_put_contents($cli, $cliSource);

	$processEnvironment = array_merge($_ENV, [
		'PLUGIN_TEST_APPLICATION' => $application,
		'PLUGIN_TEST_BOOTSTRAP' => $bootstrap,
		'PLUGIN_TEST_ROOT' => $temporary,
		'PLUGIN_TEST_WRITES' => $temporary . '/database-writes.log',
	]);

	$run = static function (array $command) use ($processEnvironment): array {
		$process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $processEnvironment);
		if (!is_resource($process)) {
			throw new RuntimeException('Could not start isolated plugin process');
		}
		fclose($pipes[0]);
		$stdout = stream_get_contents($pipes[1]);
		fclose($pipes[1]);
		$stderr = stream_get_contents($pipes[2]);
		fclose($pipes[2]);
		return ['status' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
	};

	try {
		foreach ([
			'core_too_new_fixture' => "[info]\nname = core_too_new_fixture\ncompat = 99.0.0\n",
			'core_missing_fixture' => "[info]\nname = core_missing_fixture\n",
			'core_malformed_fixture' => "[info]\nname = core_malformed_fixture\ncompat = 1.bad\n",
		] as $plugin => $metadata) {
			$directory = $pluginRoot . '/' . $plugin;
			mkdir($directory, 0700);
			file_put_contents($directory . '/INFO', $metadata);
			file_put_contents($directory . '/setup.php', "<?php file_put_contents(" . var_export($temporary . '/' . $plugin . '-setup-ran', true) . ", 'ran');\n");

			$result = $run([PHP_BINARY, $cli, '--plugin=' . $plugin, '--install']);
			expect($result['status'])->toBe(1);
			expect($result['stdout'])->toContain('can not install');
			expect($result['stderr'])->toBe('');
			expect(file_exists($temporary . '/' . $plugin . '-setup-ran'))->toBeFalse();
		}

		expect(file_exists($temporary . '/database-writes.log'))->toBeFalse();

		$compatible = $pluginRoot . '/core_compatible_fixture';
		mkdir($compatible, 0700);
		file_put_contents($compatible . '/INFO', "[info]\nname = core_compatible_fixture\ncompat = 1.2\n");
		$result = $run([PHP_BINARY, '-r', "require getenv('PLUGIN_TEST_BOOTSTRAP'); \$message = ''; exit(api_plugin_can_install('core_compatible_fixture', \$message) ? 0 : 1);"]);
		expect($result['status'])->toBe(0);
		expect($result['stderr'])->toBe('');

		$result = $run([PHP_BINARY, '-r', "require getenv('PLUGIN_TEST_BOOTSTRAP'); exit(api_plugin_install('core_too_new_fixture') === false ? 0 : 1);"]);
		expect($result['status'])->toBe(0);
		expect(file_exists($temporary . '/core_too_new_fixture-setup-ran'))->toBeFalse();
		expect(file_exists($temporary . '/database-writes.log'))->toBeFalse();
	} finally {
		foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temporary, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
			$entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
		}
		rmdir($temporary);
	}
});
