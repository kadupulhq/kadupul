<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-2.0-or-later

// Runs the shipped graph_realtime.php in a child PHP against a stub
// include/auth.php. The PHP binary is a script that records each argument it
// receives, so a real-time poll shows up with its exact argv.
function graph_realtime_run(array $scenario): array
{
    $root = dirname(__DIR__, 2);
    $work = sys_get_temp_dir() . '/kadupul-rt-' . bin2hex(random_bytes(6));

    mkdir($work . '/include', 0700, true);
    mkdir($work . '/lib', 0700);

    $fsrc    = file_get_contents($root . '/lib/functions.php');
    $shipped = "<?php\n";

    foreach (array('cacti_escapeshellcmd', 'cacti_escapeshellarg', 'cacti_sizeof') as $name) {
        $start = strpos($fsrc, 'function ' . $name . '(');
        $end   = strpos($fsrc, "\n}\n", (int) $start);

        if ($start === false || $end === false) {
            throw new RuntimeException('Missing production helper ' . $name);
        }

        $shipped .= substr($fsrc, $start, $end - $start + 2) . "\n";
    }

    file_put_contents($work . '/shipped.php', $shipped);
    file_put_contents($work . '/lib/rrd.php', "<?php\n");
    file_put_contents($work . '/php-marker.sh', "#!/bin/sh\nfor a in \"\$@\"; do printf '[%s]' \"\$a\"; done >> '" . $work . "/marker.txt'\necho >> '" . $work . "/marker.txt'\n");
    chmod($work . '/php-marker.sh', 0700);
    copy($root . '/graph_realtime.php', $work . '/graph_realtime.php');

    $scenario['config'] = ($scenario['config'] ?? array()) + array(
        'path_php_binary'     => $work . '/php-marker.sh',
        'realtime_cache_path' => $work,
    );

    foreach ($scenario['cache'] ?? array() as $id => $contents) {
        file_put_contents($work . '/user_abc123_lgi_' . $id . '.png', $contents);
    }

    file_put_contents($work . '/scenario.json', json_encode($scenario));

    file_put_contents($work . '/include/auth.php', <<<'PHP'
<?php
$scenario = json_decode(file_get_contents(getenv('RT_SCENARIO')), true);
$config   = array('base_path' => '/opt/kadupul', 'url_path' => '/', 'cacti_server_os' => 'unix');
$calls    = array('graph' => 0, 'allowed' => array(), 'settings' => array());
$_SESSION = array('sess_user_id' => 7);

$_SERVER['REQUEST_METHOD'] = $scenario['method'] ?? 'GET';

$realtime_sizes        = array(25 => '25%', 50 => '50%', 75 => '75%', 100 => '100%');
$realtime_default_size = 100;

define('RRDTOOL_OUTPUT_GRAPH_DATA', 3);

register_shutdown_function(function () {
	$GLOBALS['calls']['session'] = $_SESSION;
	file_put_contents(getenv('RT_CALLS'), json_encode($GLOBALS['calls']));
});

require getenv('RT_WORK') . '/shipped.php';

function get_request_var($name, $default = '') {
	return $GLOBALS['scenario']['request'][$name] ?? $default;
}

function get_nfilter_request_var($name, $default = '') {
	return get_request_var($name, $default);
}

function get_filter_request_var($name, $filter = FILTER_VALIDATE_INT, $options = array()) {
	return get_request_var($name);
}

function isset_request_var($name) {
	return isset($GLOBALS['scenario']['request'][$name]);
}

function isempty_request_var($name) {
	return empty($GLOBALS['scenario']['request'][$name]);
}

function set_request_var($name, $value) {
	$GLOBALS['scenario']['request'][$name] = $value;
}

function set_default_action($default = '') {
	if (!isset_request_var('action')) {
		set_request_var('action', $default);
	}
}

function load_current_session_value($request, $session, $default) {
	if (!isset_request_var($request)) {
		set_request_var($request, $default);
	}
}

function read_config_option($name, $force = false) {
	return $GLOBALS['scenario']['config'][$name] ?? '';
}

function read_user_setting($name, $default = false, $force = false, $user = 0) {
	return $GLOBALS['scenario']['user'][$name] ?? $default;
}

function set_user_setting($name, $value, $user = -1) {
	$GLOBALS['calls']['settings'][$name] = $value;
}

function cacti_log($message, $output = false, $environ = 'CMDPHP') {
	$GLOBALS['calls']['log'][] = $message;
}

function generate_hash() {
	return 'abc123';
}

function db_fetch_row_prepared($sql, $params = array(), $log = true) {
	return array('width' => 500, 'height' => 150);
}

function db_fetch_cell_prepared($sql, $params = array(), $col_name = '', $log = true) {
	return '1';
}

function is_graph_allowed($local_graph_id, $user_id = 0) {
	$GLOBALS['calls']['allowed'][] = $local_graph_id;

	/* lib/auth.php get_allowed_graphs() adds the graph predicate only when
	 * $graph_id > 0, so any other id passes for a user who may view a graph */
	if ((int) $local_graph_id <= 0) {
		return count($GLOBALS['scenario']['allowed']) > 0;
	}

	return in_array((int) $local_graph_id, $GLOBALS['scenario']['allowed'], true);
}

function rrdtool_function_graph($local_graph_id, $rra_id, $graph_data_array, $rrdtool_pipe = false, &$xport_meta = array(), $user = 0) {
	$GLOBALS['calls']['graph']++;

	return 'PNGDATA';
}

function rrdtool_create_error_image($string, $width = '', $height = '') {
	return 'ERRPNG:' . $string;
}

function html_escape($string) {
	return htmlspecialchars((string) $string, ENT_QUOTES);
}

function __($text, ...$args) {
	return $args ? vsprintf($text, $args) : $text;
}
PHP);

    $env     = array('RT_SCENARIO' => $work . '/scenario.json', 'RT_CALLS' => $work . '/calls.json', 'RT_WORK' => $work, 'PATH' => getenv('PATH'));
    $process = proc_open(array(PHP_BINARY, '-d', 'error_reporting=E_ALL & ~E_DEPRECATED', 'graph_realtime.php'), array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes, $work, $env);

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    proc_close($process);

    $result = array(
        'polls'    => file_exists($work . '/marker.txt') ? file($work . '/marker.txt', FILE_IGNORE_NEW_LINES) : array(),
        'calls'    => json_decode((string) @file_get_contents($work . '/calls.json'), true),
        'response' => json_decode($stdout, true),
        'stdout'   => $stdout,
        'raw'      => $stdout . $stderr,
    );

    foreach (array('include/auth.php', 'lib/rrd.php', 'shipped.php', 'php-marker.sh', 'graph_realtime.php', 'scenario.json', 'calls.json', 'marker.txt') as $file) {
        @unlink($work . '/' . $file);
    }

    foreach (glob($work . '/*.png') as $file) {
        unlink($file);
    }

    rmdir($work . '/include');
    rmdir($work . '/lib');
    rmdir($work);

    return $result;
}
