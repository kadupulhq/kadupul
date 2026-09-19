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
 * db_fetch_cell() answers false when its query fails.  Both of the master's
 * decisions in batchgapfix.php read a COUNT(*) through it and then truncate
 * graph_local_spikekill, so a false compared as a number drops through to the
 * TRUNCATE and destroys the queue the failure path exists to keep.  Both
 * blocks call exit(), so each is driven here in a subprocess with its
 * dependencies stubbed and no database; the stubs record the statements they
 * were handed to a file the parent test reads back.
 */

namespace BatchGapfixCountFailureTest;

function block($pattern) {
	$source = \file_get_contents(\dirname(__DIR__, 4) . '/cli/batchgapfix.php');

	expect(\preg_match($pattern, $source, $match))->toBe(1);

	return $match[0];
}

function run($block, $stubs, $prologue, $answers) {
	$dir = \sys_get_temp_dir() . '/batchgapfix-count-' . \bin2hex(\random_bytes(8));

	\mkdir($dir, 0700);

	$runner = '<?php' . "\n"
		. 'function db_execute($sql) { file_put_contents(getenv("OUT") . "/executed", $sql . "\n", FILE_APPEND); return true; }' . "\n"
		. 'function cacti_log($message, ...$args) {}' . "\n"
		. 'function unregister_process(...$args) { file_put_contents(getenv("OUT") . "/executed", "UNREGISTER\n", FILE_APPEND); }' . "\n"
		. $stubs . "\n"
		. $prologue . "\n"
		. $block . "\n";

	\file_put_contents($dir . '/run.php', $runner);

	$process = \proc_open(
		array(PHP_BINARY, $dir . '/run.php'),
		array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
		$pipes,
		null,
		\array_merge(\getenv(), array('OUT' => $dir, 'ANSWERS' => \json_encode($answers)))
	);

	$stdout = \stream_get_contents($pipes[1]);
	$stderr = \stream_get_contents($pipes[2]);
	\fclose($pipes[1]);
	\fclose($pipes[2]);
	$status = \proc_close($process);

	$executed = \file_exists($dir . '/executed') ? \file_get_contents($dir . '/executed') : '';

	foreach (\glob($dir . '/*') ?: array() as $file) {
		\unlink($file);
	}

	\rmdir($dir);

	return array('status' => $status, 'stdout' => $stdout, 'stderr' => $stderr, 'executed' => $executed);
}

test('the master keeps the queue when a result tally cannot be read', function ($succeeded, $failed, $status, $truncated) {
	$stubs = 'function db_fetch_cell($sql) {'
		. ' $answers = json_decode(getenv("ANSWERS"), true);'
		. ' return strpos($sql, "exit_code != 0") !== false ? $answers[1] : $answers[0]; }';

	$result = run(
		block('/^\t\$succeeded = db_fetch_cell\(.*?^\texit\(0\);\R/ms'),
		$stubs,
		'$child_status = 0; $not_finished = 0; $start = microtime(true); $end = $start + 1; $rate = 1; $rrdfiles = 4; $threads = 2; $type = "master"; $child = 0;',
		array($succeeded, $failed)
	);

	expect($result['status'])->toBe($status)
		->and(\strpos($result['executed'], 'TRUNCATE TABLE graph_local_spikekill') !== false)->toBe($truncated);
})->with(array(
	'an unreadable failure tally' => array('4', false, 1, false),
	'an unreadable success tally' => array(false, '0', 1, false),
	'genuine failures'            => array('1', '3', 1, false),
	'a clean run'                 => array('4', '0', 0, true),
));

test('a start run refuses to truncate when the in-progress count cannot be read', function ($running, $force, $status, $truncated) {
	$stubs = 'function db_fetch_cell($sql) { $answers = json_decode(getenv("ANSWERS"), true); return $answers[0]; }';

	$result = run(
		block('/^\t\t\$running = db_fetch_cell\(.*?TRUNCATE TABLE graph_local_spikekill\'\);\R\s*\}\R/ms'),
		$stubs,
		'$force = ' . \var_export($force, true) . ';',
		array($running)
	);

	expect($result['status'])->toBe($status)
		->and(\strpos($result['executed'], 'TRUNCATE TABLE graph_local_spikekill') !== false)->toBe($truncated);
})->with(array(
	'an unreadable count'            => array(false, false, 1, false),
	'an unreadable count with force' => array(false, true, 1, false),
	'a run already in progress'      => array('3', false, 1, false),
	'a forced restart'               => array('3', true, 0, true),
	'an idle queue'                  => array('0', false, 0, true),
));

test('registered master is released by shutdown on early failure', function () {
    $source = file_get_contents(dirname(__DIR__, 4) . '/cli/batchgapfix.php');
    preg_match('/register_shutdown_function\(function \(\) \{ unregister_process.*?\}\);/', $source, $match);
    expect($match)->not->toBeEmpty();
    $result = run($match[0] . 'exit(1);', '', '', array());
    expect($result['status'])->toBe(1)->and($result['executed'])->toBe("UNREGISTER\n");
});

test('interrupted repair retains the queue and marks child work failed', function ($child) {
    $source = file_get_contents(dirname(__DIR__, 4) . '/cli/batchgapfix.php');
    preg_match('/^function sig_handler\(.*?^}\R/ms', $source, $match);
    $result = run($match[0] . 'sig_handler(15);',
        'if (!defined("SIGTERM")) {define("SIGTERM",15);define("SIGINT",2);} function db_execute_prepared($sql,$args) {return db_execute($sql);}',
        '$child=' . $child . ';$type=' . var_export($child ? 'child' : 'master', true) . ';', array());
    expect($result['status'])->toBe(1)->and($result['executed'])->not->toContain('TRUNCATE')
        ->and(strpos($result['executed'], 'UPDATE graph_local_spikekill') !== false)->toBe($child !== 0);
})->with(array(0, 1));
