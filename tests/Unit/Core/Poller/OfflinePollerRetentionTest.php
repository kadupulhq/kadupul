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
 * A remote Data Collector that cannot reach the main database keeps what it
 * collected in poller_output until poller_recovery.php has delivered it, and
 * cmd.php reports a sample batch it could not store.
 */

namespace OfflinePollerRetention;

require_once dirname(__DIR__, 3) . '/Helpers/PhpSource.php';

function run_php(string $code): array {
	$file = tempnam(sys_get_temp_dir(), 'offline-retention-');
	file_put_contents($file, "<?php\n" . $code);
	$process = proc_open(array(PHP_BINARY, '-d', 'error_reporting=24575', $file), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
	$out     = stream_get_contents($pipes[1]);
	$err     = stream_get_contents($pipes[2]);
	fclose($pipes[1]);
	fclose($pipes[2]);
	$status = proc_close($process);
	unlink($file);

	return array('status' => $status, 'out' => $out, 'err' => $err);
}

test('the wait loop keeps poller_output rows that remote children write', function ($mode, $poller_id) {
	$source = file_get_contents(dirname(__DIR__, 4) . '/poller.php');
	$start  = strpos($source, "\t\t\twhile (1) {\n\t\t\t\t\$finished_processes");
	$end    = strpos($source, "\t\t\tif (\$rrdtool_pipe !== false) {", (int) $start);
	expect($start)->not->toBeFalse()->and($end)->not->toBeFalse();
	$loop = substr($source, $start, $end - $start);

	$program = <<<'PHP'
namespace OfflineWaitLoopProbe;
const SQL_NO_CACHE = '';
const MAX_POLLER_RUNTIME = 298;
const POLLER_VERBOSITY_MEDIUM = 3;
$db = new \PDO('sqlite::memory:');
$db->exec('CREATE TABLE poller_output (output TEXT)');
$calls = 0;
$drains = 0;
function db_fetch_cell_prepared(...$args) {
	global $db, $calls;
	// A child writes a sample before every check of the wait loop.
	$db->exec("INSERT INTO poller_output VALUES ('sample')");
	return $calls++;
}
function db_execute($sql) {
	global $db;
	return $db->exec(str_replace('TRUNCATE ', 'DELETE FROM ', $sql)) !== false;
}
function read_config_option(...$args) { return 0; }
function api_plugin_hook(...$args) {}
function log_cacti_stats(...$args) {}
function sleep(...$args) {}
function process_poller_output_batch(...$args) { $GLOBALS['drains']++; return 0; }
$poller_db_cnn_id = false;
$started_processes = 1;
$poller_finishing_dispatched = false;
$poller_output_deferred = false;
$rrdtool_pipe = false;
$rrds_processed = 0;
$rrd_write_failed = false;
$poller_start = $loop_start = microtime(true);
$method = $concurrent_processes = $max_threads = $total_polling_hosts = $hosts_per_process = $num_polling_items = 1;
$debug = false;
PHP;
	$program .= '$poller_id=' . $poller_id . ';$config=array("connection"=>' . var_export($mode, true) . ');' . $loop;
	$program .= 'echo json_encode(array((int) $db->query("SELECT count(*) FROM poller_output")->fetchColumn(), $drains, $calls));';
	$result = run_php($program);

	expect($result['status'])->toBe(0, $result['out'] . $result['err']);
	expect(json_decode($result['out'], true))->toBe(array(2, $poller_id === 1 ? 2 : 0, 2));
})->with(array(array('offline', 2), array('recovery', 2), array('online', 2), array('online', 1)));

test('bootstrap enters recovery while either local queue holds samples', function ($boost, $output) {
	$source = file_get_contents(dirname(__DIR__, 4) . '/include/global.php');
	$start  = strpos($source, "\t\t\$boost_records = db_fetch_cell(");
	$end    = strpos($source, "\t/* gather the existing cactidb version */");
	expect($start)->not->toBeFalse()->and($end)->not->toBeFalse();
	// Cut at the end of the recovery check, before the enclosing block closes.
	$block = substr($source, $start, $end - $start);
	$block = substr($block, 0, strrpos($block, '}'));

	$program = <<<'PHP'
$local_db_cnn_id = new PDO('sqlite::memory:');
foreach (array('poller_output_boost', 'poller_output') as $table) {
	$local_db_cnn_id->exec("CREATE TABLE $table (output TEXT)");
}
function db_fetch_cell($sql, $column = '', $log = true, $connection = false) {
	return $connection->query($sql)->fetchColumn();
}
$config = array('connection' => 'online');
PHP;
	foreach (array('poller_output_boost' => $boost, 'poller_output' => $output) as $table => $count) {
		if ($count > 0) {
			$program .= '$local_db_cnn_id->exec("INSERT INTO ' . $table . " VALUES ('retained')\");\n";
		}
	}
	$result = run_php($program . $block . 'echo $config["connection"];');

	expect($result['status'])->toBe(0, $result['out'] . $result['err']);
	expect($result['out'])->toBe($boost || $output ? 'recovery' : 'online');
})->with(array(array(0, 0), array(1, 0), array(0, 1), array(1, 1)));

test('cmd.php reports a batch it could not store in either queue', function ($redirect, $failure) {
	$source = file_get_contents(dirname(__DIR__, 4) . '/cmd.php');
	$body   = \test_php_function_source($source, 'cmd_write_poller_output');

	$program = <<<'PHP'
$db = new PDO('sqlite::memory:');
foreach (array('poller_output', 'poller_output_boost') as $table) {
	$db->exec("CREATE TABLE $table (local_data_id INT, rrd_name TEXT, time TEXT, output TEXT)");
}
$calls = 0;
$clock_resets = 0;
$logs = array();
function cacti_log($message, ...$args) { $GLOBALS['logs'][] = $message; }
function read_config_option($name) { return $GLOBALS['redirect'] ? 'on' : ''; }
function db_execute($sql, $log, $connection) {
	if (strpos($sql, 'SET timestamp = DEFAULT') !== false) { $GLOBALS['clock_resets']++; return true; }
	if (strpos($sql, 'SET timestamp') !== false) { return true; }
	if (++$GLOBALS['calls'] === $GLOBALS['failure']) { return false; }
	return $connection->exec(str_replace('INSERT IGNORE', 'INSERT OR IGNORE', $sql)) !== false;
}
PHP;
	$program .= '$redirect=' . var_export($redirect, true) . ';$failure=' . $failure . ';' . $body
		. '$ok = cmd_write_poller_output(array("(1, \'value\', \'2026-01-01 00:00:00\', \'sample\')"), $db);'
		. 'echo json_encode(array($ok, (int) $db->query("SELECT count(*) FROM poller_output")->fetchColumn(), (int) $db->query("SELECT count(*) FROM poller_output_boost")->fetchColumn(), $clock_resets, count($logs)));';
	$result = run_php($program);

	$copies = $redirect;
	$failed = $failure === 1 || ($failure === 2 && $copies);
	expect($result['status'])->toBe(0, $result['out'] . $result['err']);
	expect(json_decode($result['out'], true))->toBe(array(!$failed, $failure === 1 ? 0 : 1, $copies && $failure !== 2 ? 1 : 0, $copies ? 1 : 0, $failed ? 1 : 0));
})->with(array(false, true))->with(array(0, 1, 2));
