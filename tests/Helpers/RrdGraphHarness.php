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

/**
 * Return the source of one top-level function so a child process can define
 * the shipped implementation instead of a hand-written double.
 */
function cacti_test_rrd_function_source(string $src, string $name) : string {
	$start = strpos($src, "\nfunction " . $name . '(');

	if ($start === false) {
		throw new RuntimeException($name . '() not found');
	}

	$start++;
	$depth = 0;
	$len   = strlen($src);

	for ($i = strpos($src, '{', $start); $i < $len; $i++) {
		if ($src[$i] === '{') {
			$depth++;
		} elseif ($src[$i] === '}') {
			$depth--;

			if ($depth === 0) {
				return substr($src, $start, $i - $start + 1);
			}
		}
	}

	throw new RuntimeException($name . '() is unbalanced');
}

function cacti_test_rrdtool_binary() : string {
	foreach (array('/opt/homebrew/bin/rrdtool', '/usr/local/bin/rrdtool', '/usr/bin/rrdtool') as $candidate) {
		if (is_executable($candidate)) {
			return $candidate;
		}
	}

	return '';
}

/**
 * Feed command lines to 'rrdtool -' the way __rrd_execute() does and return
 * everything rrdtool printed.
 */
function cacti_test_rrdtool_batch(string $commands, string $cwd) : string {
	$process = proc_open(array(cacti_test_rrdtool_binary(), '-'), array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes, $cwd);

	if (!is_resource($process)) {
		throw new RuntimeException('unable to start rrdtool');
	}

	fwrite($pipes[0], $commands . "\r\nquit\r\n");
	fclose($pipes[0]);

	$output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);

	fclose($pipes[1]);
	fclose($pipes[2]);
	proc_close($process);

	return $output;
}

/**
 * Create a one-DS RRD in a fresh directory and return the directory.
 */
function cacti_test_rrdtool_workdir() : string {
	$dir = sys_get_temp_dir() . '/cacti-rrd-quote-' . bin2hex(random_bytes(6));
	mkdir($dir, 0700);

	register_shutdown_function(function () use ($dir) {
		array_map('unlink', glob($dir . '/*'));
		rmdir($dir);
	});

	cacti_test_rrdtool_batch('create t.rrd --start 1700000000 --step 300 DS:a:GAUGE:600:U:U RRA:AVERAGE:0.5:1:100', $dir);

	return $dir;
}

/**
 * Load lib/rrd.php in a child process with the database and settings layer
 * stubbed and run one scenario against it.
 *
 * Scenario keys: root, action (quote|graph_options|font), values, config,
 * user, substitutions, graph, graph_data_array, type, no_legend, themefonts.
 *
 * @param array<string, mixed> $scenario
 *
 * @return array<string, mixed>
 */
function cacti_test_rrd_harness_run(array $scenario) : array {
	$root   = $scenario['root'] ?? dirname(__DIR__, 2);
	$fsrc   = file_get_contents($root . '/lib/functions.php');
	$hsrc   = file_get_contents($root . '/lib/html.php');
	$shipped = '';

	foreach (array('cacti_escapeshellarg', 'cacti_version_compare', 'version_to_decimal', 'cacti_sizeof') as $name) {
		$shipped .= cacti_test_rrd_function_source($fsrc, $name) . "\n\n";
	}

	$shipped .= cacti_test_rrd_function_source($hsrc, 'html_escape') . "\n\n";
	$shipped .= cacti_test_rrd_function_source(file_get_contents($root . '/lib/rrdcheck.php'), 'rrdcheck_rrdtool_execute') . "\n";

	$work = sys_get_temp_dir() . '/cacti-rrd-harness-' . bin2hex(random_bytes(6));
	mkdir($work, 0700);

	file_put_contents($work . '/global_arrays.php', "<?php\n\$image_types = array(1 => 'PNG', 3 => 'SVG');\n");
	file_put_contents($work . '/shipped.php', "<?php\n" . $shipped);

	$child = <<<'PHP'
<?php
$scenario = json_decode(stream_get_contents(STDIN), true);
$warnings = array();

set_error_handler(function ($errno, $errstr) use (&$warnings) {
	$warnings[] = $errstr;

	return true;
});

define('CHECKED', 'on');
define('CACTI_ESCAPE_CHARACTER', '"');
define('GD_MO_D_Y', 0);
define('GD_MN_D_Y', 1);
define('GD_D_MO_Y', 2);
define('GD_D_MN_Y', 3);
define('GD_Y_MO_D', 4);
define('GD_Y_MN_D', 5);
define('POLLER_VERBOSITY_LOW', 2);
define('POLLER_VERBOSITY_DEBUG', 5);

$config = array(
	'cacti_server_os' => $scenario['os'] ?? 'unix',
	'base_path'       => $scenario['root'],
	'library_path'    => $scenario['root'] . '/lib',
	'include_path'    => $scenario['work'],
	'is_web'          => false,
);

$datechar = array(0 => '-', 1 => '/', 2 => '.');

function read_config_option($name, $force = false) {
	return $GLOBALS['scenario']['config'][$name] ?? '';
}

function read_user_setting($name, $default = false, $force = false, $user = 0) {
	return $GLOBALS['scenario']['user'][$name] ?? $default;
}

function get_rrdtool_version($force = false) {
	return '1.9.0';
}

function get_selected_theme() {
	return 'modern';
}

function cacti_log($string, $output = false, $environ = 'CMDPHP', $level = '') {
}

function db_fetch_cell_prepared($sql, $params = array(), $col_name = '', $log = true) {
	return $GLOBALS['scenario']['db_cell'] ?? '';
}

/* database boundary: substitute from the scenario map instead of host tables */
function substitute_host_data($string, $l_escape_string, $r_escape_string, $host_id) {
	return strtr($string, $GLOBALS['scenario']['substitutions'] ?? array());
}

function substitute_snmp_query_data($string, $host_id, $snmp_query_id, $snmp_index, $max_chars = 0) {
	return strtr($string, $GLOBALS['scenario']['substitutions'] ?? array());
}

require $scenario['work'] . '/shipped.php';
require $scenario['root'] . '/lib/rrd.php';

$out = array();

try {
	switch ($scenario['action']) {
		case 'quote':
			$out['quoted'] = array_map('rrdtool_quote_argument', $scenario['values']);
			break;
		case 'legacy_quote':
			$out['quoted'] = array_map('cacti_escapeshellarg', $scenario['values']);
			break;
		case 'graph_options':
			$graph = $scenario['graph'];
			$gda   = $scenario['graph_data_array'] ?? array();
			$out['options'] = rrd_function_process_graph_options($scenario['start'], $scenario['end'], $graph, $gda);
			break;
		case 'rrdcheck_command':
			/* capture the exact bytes rrdcheck writes to its rrdtool pipe */
			$out['written'] = array();

			foreach ($scenario['commands'] as $command) {
				$pipes = array(fopen('php://temp', 'w+'), fopen('php://temp', 'w+'));
				fwrite($pipes[1], "OK u:0.00 s:0.00 r:0.00\n");
				rewind($pipes[1]);

				rrdcheck_rrdtool_execute($command, $pipes);

				rewind($pipes[0]);
				$out['written'][] = stream_get_contents($pipes[0]);
			}

			break;
		case 'rrdcheck_rrdtool':
			$process = proc_open(array($scenario['rrdtool'], '-'), array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes, $scenario['cwd']);
			$out['output'] = array();

			foreach ($scenario['commands'] as $command) {
				$out['output'][] = rrdcheck_rrdtool_execute($command, $pipes);
			}

			fclose($pipes[0]);
			proc_close($process);

			break;
		case 'font':
			$out['font'] = rrdtool_function_set_font($scenario['type'], $scenario['no_legend'] ?? '', $scenario['themefonts'] ?? array());
			break;
	}
} catch (Throwable $e) {
	$out['error'] = get_class($e) . ': ' . $e->getMessage();
}

$out['warnings'] = $warnings;

print json_encode($out);
PHP;

	file_put_contents($work . '/child.php', $child);

	$scenario['root'] = $root;
	$scenario['work'] = $work;

	$process = proc_open(array(PHP_BINARY, '-d', 'error_reporting=E_ALL & ~E_DEPRECATED', $work . '/child.php'), array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);

	fwrite($pipes[0], json_encode($scenario));
	fclose($pipes[0]);

	$stdout = stream_get_contents($pipes[1]);
	$stderr = stream_get_contents($pipes[2]);

	fclose($pipes[1]);
	fclose($pipes[2]);
	proc_close($process);

	foreach (array('child.php', 'shipped.php', 'global_arrays.php') as $file) {
		unlink($work . '/' . $file);
	}

	rmdir($work);

	$decoded = json_decode($stdout, true);

	if (!is_array($decoded)) {
		throw new RuntimeException('harness failed: ' . $stdout . $stderr);
	}

	return $decoded;
}

/**
 * Render an output file and graph options plus one DEF/LINE through graphv
 * and return the reported image width and any rrdtool error.
 *
 * @return array{width: int|null, error: string}
 */
function cacti_test_rrdtool_graphv_width(string $options, string $dir) : array {
	$line   = 'graphv ' . str_replace(" \\\n", ' ', $options) . ' DEF:a=t.rrd:a:AVERAGE LINE1:a#00FF00';
	$output = cacti_test_rrdtool_batch($line, $dir);
	$width  = preg_match('/^image_width = (\d+)$/m', $output, $m) ? (int) $m[1] : null;
	$error  = preg_match('/^ERROR: .*$/m', $output, $e) ? $e[0] : '';

	return array('width' => $width, 'error' => $error);
}
