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
 * #762: a Boost child whose partition has no rows succeeds, so an idle child
 * does not fail the run and keep the archive; a failed row count still fails.
 */

require_once dirname(__DIR__, 3) . '/Helpers/PhpSource.php';

function boost_idle_child_run(array $counts) {
	$source = file_get_contents(dirname(__DIR__, 4) . '/poller_boost.php');
	expect($source)->toBeString();
	$program = 'namespace BoostIdleProbe;$counts=' . var_export($counts, true) . ';$closed=0;$archive_table="";$run_id="r";'
		. 'function rrd_init(...$a){return "pipe";}function rrd_close($p){$GLOBALS["closed"]++;}'
		. 'function cacti_log(...$a){}function boost_debug($m){}function cacti_sizeof($a){return is_countable($a)?count($a):0;}'
		. 'function boost_get_arch_table_names($t){return array("poller_output_boost_arch_1"=>"poller_output_boost_arch_1","poller_output_boost_arch_2"=>"poller_output_boost_arch_2");}'
		. 'function db_fetch_cell_prepared($s,$p=array()){return array_shift($GLOBALS["counts"]);}'
		. test_php_function_source($source, 'boost_output_rrd_data')
		. 'echo json_encode(array(boost_output_rrd_data(3),$closed));';
	$process = proc_open(array(PHP_BINARY, '-r', $program), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
	expect(is_resource($process))->toBeTrue();
	$out = stream_get_contents($pipes[1]);
	$err = stream_get_contents($pipes[2]);
	fclose($pipes[1]);
	fclose($pipes[2]);
	expect(proc_close($process))->toBe(0, $err . $out);

	return json_decode($out, true, 512, JSON_THROW_ON_ERROR);
}

test('a child with no rows in its partition succeeds', function () {
	expect(boost_idle_child_run(array('0', '0')))->toBe(array(0, 1));
});

test('a failed row count still fails the child', function ($counts) {
	expect(boost_idle_child_run($counts))->toBe(array(-1, 1));
})->with(array(
	'first table'  => array(array(false, '0')),
	'second table' => array(array('0', false)),
));
