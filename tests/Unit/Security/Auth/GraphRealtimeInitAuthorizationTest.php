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
 * graph_realtime.php?action=init starts poller_realtime.php, which polls every
 * device behind the graph. It did so before checking graph permission and
 * before the real-time enabled setting was read. The shipped page runs here
 * against a stub include/auth.php; the PHP binary is a script that records
 * each argument it receives, so a poll shows up with its exact argv.
 * action=view returns the image the last poll cached for the session, so it
 * is refused under the same conditions.
 */

require_once dirname(__DIR__, 3) . '/Helpers/RrdGraphHarness.php';

function graph_realtime_init_run(array $scenario) : array {
	$root = dirname(__DIR__, 4);
	$work = sys_get_temp_dir() . '/cacti-rt-' . bin2hex(random_bytes(6));

	mkdir($work . '/include', 0700, true);
	mkdir($work . '/lib', 0700);

	$fsrc    = file_get_contents($root . '/lib/functions.php');
	$shipped = "<?php\n";

	foreach (array('cacti_escapeshellcmd', 'cacti_escapeshellarg', 'cacti_sizeof') as $name) {
		$shipped .= cacti_test_rrd_function_source($fsrc, $name) . "\n\n";
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
$calls    = array('graph' => 0, 'graph_users' => array(), 'allowed' => array());
$_SESSION = array('sess_user_id' => 7);

$realtime_sizes        = array(25 => '25%', 50 => '50%', 75 => '75%', 100 => '100%');
$realtime_default_size = 100;

define('RRDTOOL_OUTPUT_GRAPH_DATA', 3);

register_shutdown_function(function () {
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

	/* lib/auth.php get_allowed_graphs() adds the graph predicate only when $graph_id > 0,
	 * so any other id is allowed for a user who may view at least one graph */
	if ((int) $local_graph_id <= 0) {
		return count($GLOBALS['scenario']['allowed']) > 0;
	}

	return in_array((int) $local_graph_id, $GLOBALS['scenario']['allowed'], true);
}

function rrdtool_function_graph($local_graph_id, $rra_id, $graph_data_array, $rrdtool_pipe = false, &$xport_meta = array(), $user = 0) {
	$GLOBALS['calls']['graph']++;
	$GLOBALS['calls']['graph_users'][] = $user;

	/* the shipped renderer refuses a graph the given user may not view */
	if ($user > 0 && !is_graph_allowed($local_graph_id, $user)) {
		return 'GRAPH ACCESS DENIED';
	}

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

	$env = array('RT_SCENARIO' => $work . '/scenario.json', 'RT_CALLS' => $work . '/calls.json', 'RT_WORK' => $work, 'PATH' => getenv('PATH'));
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

$realtimeRequest = array('action' => 'init', 'local_graph_id' => 5, 'ds_step' => 10, 'graph_start' => -60, 'size' => 100, 'top' => 0, 'left' => 0);

test('an allowed graph is polled once with the 1.2.31 poller arguments and response', function () use ($realtimeRequest) {
	$run = graph_realtime_init_run(array('request' => $realtimeRequest, 'allowed' => array(5), 'config' => array('realtime_enabled' => 'on')));

	expect($run['polls'])->toBe(array('[-q][/opt/kadupul/poller_realtime.php][--graph=5][--interval=10][--poller_id=abc123]'))
		->and($run['calls']['graph'])->toBeGreaterThan(0)
		->and($run['response']['data'])->toBe('')
		->and(array_keys($run['response']))->toBe(array('local_graph_id', 'top', 'left', 'ds_step', 'graph_start', 'size', 'thumbnails', 'data', 'image_format'));
});

test('an allowed graph costs one permission query per request', function () use ($realtimeRequest) {
	foreach (array('init', 'countdown') as $action) {
		$run = graph_realtime_init_run(array('request' => array('action' => $action) + $realtimeRequest, 'allowed' => array(5), 'config' => array('realtime_enabled' => 'on')));

		expect($run['calls']['allowed'])->toBe(array(5))
			->and($run['polls'])->toHaveCount(1);
	}
});

test('a stored interval with a space reaches the poller as one argument', function () use ($realtimeRequest) {
	$request = $realtimeRequest;
	unset($request['ds_step']);

	$run = graph_realtime_init_run(array('request' => $request, 'allowed' => array(5), 'user' => array('realtime_interval' => '10 --force'), 'config' => array('realtime_enabled' => 'on')));

	expect($run['polls'])->toBe(array('[-q][/opt/kadupul/poller_realtime.php][--graph=5][--interval=10 --force][--poller_id=abc123]'));
});

test('a graph the user may not view is not polled', function () use ($realtimeRequest) {
	$run = graph_realtime_init_run(array('request' => $realtimeRequest, 'allowed' => array(6), 'config' => array('realtime_enabled' => 'on')));

	expect($run['polls'])->toBe(array())
		->and($run['calls']['graph'])->toBe(0)
		->and($run['calls']['allowed'])->toBe(array(5))
		->and($run['response']['data'])->toBe(base64_encode('ERRPNG:Permission Denied'));
});

test('a refused request keeps the reply shape realtime.js reads', function () use ($realtimeRequest) {
	$allowed = graph_realtime_init_run(array('request' => $realtimeRequest, 'allowed' => array(5), 'config' => array('realtime_enabled' => 'on')));
	$denied  = graph_realtime_init_run(array('request' => $realtimeRequest, 'allowed' => array(6), 'config' => array('realtime_enabled' => 'on')));

	expect(array_keys($denied['response']))->toBe(array_keys($allowed['response']))
		->and($denied['response']['local_graph_id'])->toBe(5)
		->and($denied['response']['ds_step'])->toBe('10')
		->and($denied['response']['graph_start'])->toBe('-60')
		->and($denied['response']['size'])->toBe('100')
		->and($denied['response']['thumbnails'])->toBe('false')
		->and($denied['response']['image_format'])->toBe('png');

	$request = $realtimeRequest;
	unset($request['graph_start'], $request['ds_step']);

	$countdown = graph_realtime_init_run(array(
		'request' => array('action' => 'countdown', 'graph_nolegend' => 'true', 'size' => 50) + $request,
		'allowed' => array(6),
		'user'    => array('realtime_interval' => '20', 'realtime_gwindow' => '300'),
		'config'  => array('realtime_enabled' => 'on'),
	));

	expect(array_keys($countdown['response']))->toBe(array_keys($allowed['response']))
		->and($countdown['response']['ds_step'])->toBe('20')
		->and($countdown['response']['graph_start'])->toBe('300')
		->and($countdown['response']['size'])->toBe('50')
		->and($countdown['response']['thumbnails'])->toBe('true');
});

test('no graph is polled while real-time is disabled', function () use ($realtimeRequest) {
	foreach (array('init', 'timespan', 'interval', 'countdown') as $action) {
		$run = graph_realtime_init_run(array('request' => array('action' => $action) + $realtimeRequest, 'allowed' => array(5), 'config' => array('realtime_enabled' => '')));

		expect($run['polls'])->toBe(array())
			->and($run['calls']['graph'])->toBe(0)
			->and($run['response']['data'])->toBe(base64_encode('ERRPNG:Real-time has been disabled by your administrator.'));
	}
});

test('a request without a graph id is not polled', function () use ($realtimeRequest) {
	$request = $realtimeRequest;
	unset($request['local_graph_id']);

	$run = graph_realtime_init_run(array('request' => $request, 'allowed' => array(5), 'config' => array('realtime_enabled' => 'on')));

	expect($run['polls'])->toBe(array())
		->and($run['calls']['graph'])->toBe(0);
});

test('a zero or negative graph id is refused like a missing one', function ($id) use ($realtimeRequest) {
	foreach (array('init', 'countdown') as $action) {
		$run = graph_realtime_init_run(array('request' => array('action' => $action, 'local_graph_id' => $id) + $realtimeRequest, 'allowed' => array(5), 'config' => array('realtime_enabled' => 'on')));

		expect($run['polls'])->toBe(array())
			->and($run['calls']['graph'])->toBe(0)
			->and($run['response']['data'])->toBe(base64_encode('ERRPNG:Permission Denied'))
			->and(array_keys($run['response']))->toBe(array('local_graph_id', 'top', 'left', 'ds_step', 'graph_start', 'size', 'thumbnails', 'data', 'image_format'));
	}
})->with(array('-1' => -1, '0' => 0, 'string -5' => '-5'));

$viewRequest = array('action' => 'view', 'local_graph_id' => 5);

test('an allowed graph with real-time enabled views its cached image as 1.2.31 did', function () use ($viewRequest) {
	$run = graph_realtime_init_run(array('request' => $viewRequest, 'allowed' => array(5), 'cache' => array(5 => 'CACHEDPNG'), 'config' => array('realtime_enabled' => 'on')));

	expect($run['stdout'])->toBe(base64_encode('CACHEDPNG'))
		->and($run['calls']['allowed'])->toBe(array(5))
		->and($run['polls'])->toBe(array());

	$run = graph_realtime_init_run(array('request' => $viewRequest, 'allowed' => array(5), 'config' => array('realtime_enabled' => 'on')));

	expect($run['stdout'])->toBe('');
});

test('a cached image is not viewed for a graph the user may not view', function () use ($viewRequest) {
	$run = graph_realtime_init_run(array('request' => $viewRequest, 'allowed' => array(6), 'cache' => array(5 => 'CACHEDPNG'), 'config' => array('realtime_enabled' => 'on')));

	expect($run['stdout'])->toBe(base64_encode('ERRPNG:Permission Denied'))
		->and($run['calls']['allowed'])->toBe(array(5));
});

test('a cached image is not viewed while real-time is disabled', function () use ($viewRequest) {
	$run = graph_realtime_init_run(array('request' => $viewRequest, 'allowed' => array(5), 'cache' => array(5 => 'CACHEDPNG'), 'config' => array('realtime_enabled' => '')));

	expect($run['stdout'])->toBe(base64_encode('ERRPNG:Real-time has been disabled by your administrator.'));
});

test('a cached image is not viewed for a zero or negative graph id', function ($id) use ($viewRequest) {
	$run = graph_realtime_init_run(array('request' => array('local_graph_id' => $id) + $viewRequest, 'allowed' => array(5), 'cache' => array($id => 'CACHEDPNG'), 'config' => array('realtime_enabled' => 'on')));

	expect($run['stdout'])->toBe(base64_encode('ERRPNG:Permission Denied'));
})->with(array('-1' => -1, '0' => 0));
