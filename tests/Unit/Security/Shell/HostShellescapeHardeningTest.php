<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * Source contracts supplement the native LtsWorkerExecutionTest cases.
 */

$src = file_get_contents(__DIR__ . '/../../../../host.php');

test('host.php passes the device ID as a separate argv element', function () use ($src) {
	expect($src)->toContain("'--qid=all', '--id=' . (int) \$host_id");
});

test('host.php does not append bare $host_id to shell_exec --id argument', function () use ($src) {
	expect($src)->not->toContain('shell_exec(');
});

test('host.php reindex preserves the LTS lock and waits for worker completion', function () use ($src) {
	$pos = strpos($src, 'poller_reindex_hosts.php');
	expect($pos)->not->toBeFalse();

	expect($src)->toContain("cacti_exec(read_config_option('path_php_binary'), array(");
	expect($src)->toContain('), $output, null);');
	expect($src)->toContain("register_shutdown_function('host_reindex_release', \$host_id);");
});
