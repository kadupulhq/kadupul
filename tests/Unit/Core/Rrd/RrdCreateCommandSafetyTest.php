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
 * RRD create and update commands written to 'rrdtool -'.
 *
 * A local create writes a temporary file beside the destination and renames
 * it into place, so a symbolic link that appears at the destination after the
 * caller's link check is replaced instead of written through. The fixture
 * rrdtool below opens the path it is given, as RRDtool releases before 1.5
 * do, so the test does not depend on the installed RRDtool's own behaviour.
 *
 * Create and update give the file path as one argument, and a gradient AREA
 * legend stays inside its own command.
 */

require_once dirname(__DIR__, 3) . '/Helpers/PhpSource.php';

function rrdCreateSafetyBinary(): string {
	$candidates = array_filter(array(getenv('RRDTOOL_TEST_BINARY') ?: '', '/usr/bin/rrdtool', '/usr/local/bin/rrdtool', '/opt/homebrew/bin/rrdtool'));

	foreach ($candidates as $candidate) {
		if (is_file($candidate) && is_executable($candidate)) {
			return $candidate;
		}
	}

	return '';
}

/* An rrdtool stand-in that opens the create path itself and acknowledges each command. */
function rrdCreateSafetyFixtureRrdtool(string $dir): string {
	$path = $dir . '/fixture-rrdtool';

	file_put_contents($path, '#!' . PHP_BINARY . "\n" . <<<'PHP'
<?php
while (($line = fgets(STDIN)) !== false) {
	$line = trim($line);
	if ($line === 'quit') {
		exit(0);
	}
	if ($line === '') {
		continue;
	}
	/* rrdtool splits on blanks and groups quoted runs; there is no escape */
	$tokens = array();
	$token  = null;
	$quote  = '';
	foreach (str_split($line) as $char) {
		if ($quote !== '') {
			if ($char === $quote) {
				$quote = '';
			} else {
				$token .= $char;
			}
		} elseif ($char === '"' || $char === "'") {
			$quote = $char;
			$token = (string) $token;
		} elseif ($char === ' ' || $char === "\t") {
			if ($token !== null) {
				$tokens[] = $token;
			}
			$token = null;
		} else {
			$token .= $char;
		}
	}
	if ($token !== null) {
		$tokens[] = $token;
	}
	file_put_contents(__DIR__ . '/commands', json_encode($tokens) . "\n", FILE_APPEND);
	if ($tokens[0] === 'create') {
		file_put_contents($tokens[1], 'created');
	}
	echo "OK u:0.00 s:0.00 r:0.00\n";
}
PHP);
	chmod($path, 0700);

	return $path;
}

function rrdCreateSafetyRun(string $dir, string $rrdtool, string $path, string $body): array {
	$root      = dirname(__DIR__, 4);
	$functions = file_get_contents($root . '/lib/functions.php');
	$bootstrap = '';

	foreach (array('cacti_has_control_chars', 'cacti_log_safe_value', 'cacti_rrdtool_valid_path', 'cacti_rrdtool_valid_path_token', 'cacti_rrdtool_valid_bound', 'cacti_rrd_corrected_maximum', 'cacti_rrdtool_valid_ds_name', 'cacti_rrdtool_valid_ds_template', 'cacti_escapeshellarg', 'cacti_rrd_owned_path') as $function) {
		$bootstrap .= test_php_function_source($functions, $function) . "\n";
	}

	file_put_contents($dir . '/global_arrays.php', '<?php $data_source_types = array(1 => "GAUGE"); $consolidation_functions = array(1 => "AVERAGE");');

	$program = '<?php ' . $bootstrap
		. '$root = ' . var_export($root, true) . '; $rrdtool = ' . var_export($rrdtool, true) . '; $path = ' . var_export($path, true) . ';' . <<<'PHP'
$config = array('cacti_server_os' => 'unix', 'rra_path' => __DIR__ . '/rra', 'include_path' => __DIR__, 'is_web' => false, 'rrd_command_timeout' => 20);
require $root . '/include/global_constants.php';
if (!defined('CACTI_LOCALE')) { define('CACTI_LOCALE', 'en-US'); }
function read_config_option($name) { return $name === 'path_rrdtool' ? $GLOBALS['rrdtool'] : ''; }
function cacti_log($message, ...$args) { file_put_contents(__DIR__ . '/log', $message . "\n", FILE_APPEND); }
function cacti_sizeof($value) { return is_array($value) ? count($value) : 0; }
function cacti_session_close() {}
function get_data_source_path($id, $expand) { return $GLOBALS['path']; }
function get_data_source_item_name($id) { return 'value'; }
function get_rrdtool_version() { return '1.7.2'; }
function cacti_version_compare($a, $b, $operator) { return version_compare($a, $b, $operator); }
function db_fetch_cell_prepared(...$args) { return 0; }
function db_fetch_assoc_prepared($sql, $params = array()) {
	if (strpos($sql, 'data_source_profiles') !== false) {
		/* the moment between the caller's link check and the create */
		if (!empty($GLOBALS['link_target'])) {
			symlink($GLOBALS['link_target'], $GLOBALS['path']);
		}
		return array(array('rrd_step' => 300, 'x_files_factor' => 0.5, 'steps' => 1, 'rows' => 10, 'consolidation_function_id' => 1, 'rra_order' => 10));
	}
	return array(array('id' => 1, 'data_source_name' => 'value', 'rrd_heartbeat' => 600, 'rrd_minimum' => '0', 'rrd_maximum' => 'U', 'data_source_type_id' => 1));
}
require $root . '/lib/rrd.php';
require $root . '/lib/boost.php';
PHP . "\n" . $body;

	file_put_contents($dir . '/probe.php', $program);

	$process = proc_open(array(PHP_BINARY, '-d', 'display_errors=stderr', $dir . '/probe.php'), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
	$output  = stream_get_contents($pipes[1]);
	$error   = stream_get_contents($pipes[2]);
	fclose($pipes[1]);
	fclose($pipes[2]);
	$status  = proc_close($process);

	expect($error)->toBe('')
		->and($status)->toBe(0);

	return json_decode($output, true, 512, JSON_THROW_ON_ERROR);
}

function rrdCreateSafetyDirectory(): string {
	$dir = sys_get_temp_dir() . '/rrd-create-safety-' . bin2hex(random_bytes(8));
	mkdir($dir . '/rra', 0700, true);

	return $dir;
}

function rrdCreateSafetyRemove(string $dir): void {
	$items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);

	foreach ($items as $item) {
		($item->isDir() && !$item->isLink()) ? rmdir($item->getPathname()) : unlink($item->getPathname());
	}

	rmdir($dir);
}

test('a create replaces a link that appears at the destination instead of writing through it', function ($caller, $piped) {
	$dir = rrdCreateSafetyDirectory();

	try {
		$rrdtool = rrdCreateSafetyFixtureRrdtool($dir);
		$path    = $dir . '/rra/1.rrd';
		$target  = $dir . '/outside.txt';
		file_put_contents($target, 'unrelated');

		$call = $caller === 'boost' ? 'boost_rrdtool_function_create(1, false, $pipe)' : 'rrdtool_function_create(1, false, $pipe)';
		$open = $piped ? '$pipe = rrd_init(false, false, true);' : '$pipe = false;';

		$result = rrdCreateSafetyRun($dir, $rrdtool, $path, '$GLOBALS["link_target"] = ' . var_export($target, true) . ';' . $open
			. '$result = ' . $call . '; if (is_resource($pipe)) { rrd_close($pipe); }'
			. 'clearstatcache(); echo json_encode(array($result, is_link($path), is_file($path) ? file_get_contents($path) : null, glob(dirname($path) . "/.*.tmp")));');

		expect($result[0])->not->toBeFalse()
			->and($result[1])->toBeFalse()
			->and($result[2])->toBe('created')
			->and($result[3])->toBe(array())
			->and(file_get_contents($target))->toBe('unrelated');
	} finally {
		rrdCreateSafetyRemove($dir);
	}
})->with(array('poller' => 'poller', 'boost' => 'boost'))->with(array('one-shot' => false, 'acknowledged pipe' => true));

test('a create that leaves no file reports failure and removes nothing else', function () {
	$dir = rrdCreateSafetyDirectory();

	try {
		/* acknowledges the create without writing anything */
		file_put_contents($dir . '/silent-rrdtool', '#!/bin/sh' . "\n" . 'while read line; do [ "$line" = quit ] && exit 0; echo "OK u:0.00 s:0.00 r:0.00"; done' . "\n");
		chmod($dir . '/silent-rrdtool', 0700);
		$path = $dir . '/rra/1.rrd';

		$result = rrdCreateSafetyRun($dir, $dir . '/silent-rrdtool', $path, '$pipe = false; $result = rrdtool_function_create(1, false, $pipe);'
			. 'echo json_encode(array($result, file_exists($path), glob(dirname($path) . "/.*.tmp")));');

		expect($result)->toBe(array(false, false, array()))
			->and(file_get_contents($dir . '/log'))->toContain('RRDfile was not created');
	} finally {
		rrdCreateSafetyRemove($dir);
	}
});

test('a create path with a parent directory segment is refused before rrdtool runs', function () {
	$dir = rrdCreateSafetyDirectory();

	try {
		$rrdtool = rrdCreateSafetyFixtureRrdtool($dir);
		$path    = $dir . '/rra/../outside.rrd';

		$result = rrdCreateSafetyRun($dir, $rrdtool, $path, '$result = rrdtool_create_file($path, " --step 300", false, false, "POLLER");'
			. 'echo json_encode(array($result, file_exists($path)));');

		expect($result)->toBe(array(false, false))
			->and(file_exists($dir . '/commands'))->toBeFalse();
	} finally {
		rrdCreateSafetyRemove($dir);
	}
});

test('create and update give a path with a space to rrdtool as one argument', function ($piped) {
	$binary = rrdCreateSafetyBinary();

	if ($binary === '') {
		$this->markTestSkipped('RRDtool is required; set RRDTOOL_TEST_BINARY.');
	}

	$dir = rrdCreateSafetyDirectory();

	try {
		mkdir($dir . '/rra/a b', 0700);
		$path = $dir . '/rra/a b/1.rrd';
		$open = $piped ? '$pipe = rrd_init(false, false, true);' : '$pipe = false;';
		/* some RRDtool releases read --start 0 as now, so sample after it */
		$time = (intdiv(time(), 300) + 2) * 300;

		$result = rrdCreateSafetyRun($dir, $binary, $path, $open
			. '$created = rrdtool_function_create(1, false, $pipe);'
			. '$updated = rrdtool_function_update(array($path => array("local_data_id" => 1, "data_template_id" => 0, "times" => array(' . $time . ' => array("value" => "5")))), $pipe, $completed);'
			. 'if (is_resource($pipe)) { rrd_close($pipe); }'
			. 'echo json_encode(array($created !== false, $updated, $completed));');

		expect($result[0])->toBeTrue()
			->and($result[1])->toBe(1)
			->and($result[2])->toBe(array($path => array($time => true)))
			->and(is_file($dir . '/rra/a'))->toBeFalse();

		$last = shell_exec(escapeshellarg($binary) . ' lastupdate ' . escapeshellarg($path));

		expect($last)->toContain($time . ': 5');
	} finally {
		rrdCreateSafetyRemove($dir);
	}
})->with(array('one-shot' => false, 'acknowledged pipe' => true));

/* Run the production gradient() with its quoting helpers under local names. */
function rrdCreateSafetyGradient(string $label): string {
	$root = dirname(__DIR__, 4);

	if (!function_exists('rrdCreateSafety_gradient')) {
		$source = file_get_contents($root . '/lib/rrd.php');

		foreach (array('gradient', 'rrdtool_quote_argument') as $name) {
			// nosemgrep: php.lang.security.eval-use.eval-use -- repository source under test
			eval(preg_replace('/\b(gradient|rrdtool_quote_argument|cacti_escapeshellarg)\(/', 'rrdCreateSafety_$1(', test_php_function_source($source, $name)));
		}

		// nosemgrep: php.lang.security.eval-use.eval-use -- repository source under test
		eval(preg_replace('/\bfunction cacti_escapeshellarg\(/', 'function rrdCreateSafety_cacti_escapeshellarg(', test_php_function_source(file_get_contents($root . '/lib/functions.php'), 'cacti_escapeshellarg')));
	}

	if (!defined('RRD_NL')) {
		define('RRD_NL', " \\\n");
	}

	$saved = $GLOBALS['config'] ?? null;
	$GLOBALS['config'] = array('cacti_server_os' => 'unix');

	try {
		return rrdCreateSafety_gradient('a', '#0000a0', '#f0f0f0', $label);
	} finally {
		$GLOBALS['config'] = $saved;
	}
}

/* Split a command line as rrdtool does: blanks separate, quoted runs group, no escapes. */
function rrdCreateSafetyTokens(string $line): array {
	$tokens = array();
	$token  = null;
	$quote  = '';

	foreach (str_split($line) as $char) {
		if ($quote !== '') {
			if ($char === $quote) {
				$quote = '';
			} else {
				$token .= $char;
			}
		} elseif ($char === '"' || $char === "'") {
			$quote = $char;
			$token = (string) $token;
		} elseif ($char === ' ' || $char === "\t") {
			if ($token !== null) {
				$tokens[] = $token;
			}

			$token = null;
		} else {
			$token .= $char;
		}
	}

	if ($token !== null) {
		$tokens[] = $token;
	}

	return $tokens;
}

test('a gradient AREA legend stays one argument of one command', function ($label, $rest) {
	/* __rrd_execute() folds backslash-newline continuations; any other line break starts a command */
	$line = str_replace("\\\n", ' ', rrdCreateSafetyGradient($label));

	expect(strpbrk($line, "\r\n\0"))->toBeFalse();

	$legends = array_values(array_filter(rrdCreateSafetyTokens($line), fn($token) => str_contains($token, 'legend')));

	expect($legends)->toHaveCount(1)
		->and($legends[0])->toStartWith('AREA:')
		->and($legends[0])->toEndWith($rest);
})->with(array(
	'line break'      => array("legend\nsecond line", 'second line'),
	'carriage return' => array("legend\r\nsecond line", 'second line'),
	'double quote'    => array('legend \" more text', 'more text'),
));

test('a plain gradient legend keeps the 1.2.31 quoting', function () {
	expect(rrdCreateSafetyGradient('Inbound traffic'))->toContain(':"Inbound traffic" ');
});
