<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
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
 * Tests for command injection hardening in graph_realtime.php.
 *
 * The poller uses direct argv execution; controller tests exercise the real
 * executor and verify permissions, interval policy and rendering separately.
 */

$graphRealtimePath = __DIR__ . '/../../../../graph_realtime.php';

// --- graph_realtime.php: argv boundary for poller invocation ---

test('graph_realtime.php uses the configured PHP binary without a shell', function () use ($graphRealtimePath) {
	$contents = file_get_contents($graphRealtimePath);

	expect($contents)->toContain("cacti_exec(read_config_option('path_php_binary'), array(");
	expect($contents)->not->toContain('shell_exec(');
});

test('graph_realtime.php waits for the poller script to finish', function () use ($graphRealtimePath) {
	$contents = file_get_contents($graphRealtimePath);

	expect($contents)->toContain("\$config['base_path'] . '/poller_realtime.php'");
	expect($contents)->toContain('poller_realtime.php');
	expect($contents)->toContain('), $poller_output, null);');
});

test('graph_realtime.php passes each poller argument separately', function () use ($graphRealtimePath) {
	$contents = file_get_contents($graphRealtimePath);

	expect($contents)->toContain("'--graph=' . \$local_graph_id,")
		->and($contents)->toContain("'--interval=' . \$graph_data_array['ds_step'],")
		->and($contents)->toContain("'--poller_id=' . \$hash");
});

test('graph_realtime.php does not pass raw grv local_graph_id to sprintf for shell', function () use ($graphRealtimePath) {
	$contents = file_get_contents($graphRealtimePath);

	expect($contents)->not->toMatch('/sprintf\s*\([^)]*grv\s*\(\s*[\'"]local_graph_id[\'"]\s*\)/');
});
