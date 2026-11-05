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
 * #742 repair_database.php stops after simple_checks() says it is stopping;
 * #743 a failed hostname lookup or row fetch is a failure, not a repair.
 */

require_once dirname(__DIR__, 3) . '/Helpers/PhpSource.php';

/** Run a production repair stage with recording database stubs. */
function repair_stage_run($function, array $scenario) {
	$source = file_get_contents(dirname(__DIR__, 4) . '/cli/repair_database.php');
	expect($source)->toBeString();
	$program = 'namespace RepairProbe;$scenario=' . var_export($scenario, true) . ';'
		. '$force=true;$total_errors=0;$total_repairs=0;$total_failures=0;$repaired_hosts=array();$updates=0;$config=array();'
		. 'function print_separator($x=false){}function cacti_sizeof($a){return is_countable($a)?count($a):0;}'
		. 'function db_affected_rows(){return 0;}function db_execute($s){return true;}'
		. 'function db_execute_prepared($s,$p=array()){if(stripos($s,"UPDATE data_input_data")!==false){$GLOBALS["updates"]++;}return true;}'
		. 'function db_fetch_cell($s){return stripos($s,"dtd.local_data_id IS NULL")!==false ? $GLOBALS["scenario"]["incomplete"] : 0;}'
		. 'function db_fetch_assoc($s){return stripos($s,"type_code in")!==false ? $GLOBALS["scenario"]["entries"] : array();}'
		. 'function db_fetch_row_prepared($s,$p=array()){return stripos($s,"FROM data_local")!==false ? array("id"=>7,"host_id"=>3) : array("id"=>9,"local_data_id"=>7);}'
		. 'function db_fetch_cell_prepared($s,$p=array()){return $GLOBALS["scenario"]["hostname"];}'
		. 'function db_fetch_assoc_prepared($s,$p=array()){return array();}'
		. test_php_function_source($source, $function)
		. 'ob_start();$result=' . $function . '();ob_end_clean();'
		. 'echo json_encode(array("result"=>$result,"failures"=>$total_failures,"repairs"=>$total_repairs,"updates"=>$updates));';
	$process = proc_open(array(PHP_BINARY, '-r', $program), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
	expect(is_resource($process))->toBeTrue();
	$out = stream_get_contents($pipes[1]);
	$err = stream_get_contents($pipes[2]);
	fclose($pipes[1]);
	fclose($pipes[2]);
	expect(proc_close($process))->toBe(0, $err . $out);

	return json_decode($out, true, 512, JSON_THROW_ON_ERROR);
}

$hostnameEntry = array(array('data_input_field_id' => 1, 'data_template_data_id' => 9, 'type_code' => 'hostname'));

test('simple_checks reports that it stopped when a required count fails', function () {
	$state = repair_stage_run('simple_checks', array('incomplete' => false, 'entries' => array(), 'hostname' => 'router'));

	expect($state['result'])->toBeFalse()->and($state['failures'])->toBe(1);
});

test('the script runs the later stages only when simple_checks did not stop', function () {
	$source = file_get_contents(dirname(__DIR__, 4) . '/cli/repair_database.php');

	expect($source)->toContain("if (simple_checks() !== false) {\n\tdetailed_checks();\n\tsnmp_repairs();\n\tsnmp_index_repairs();\n}");
});

test('a failed or empty hostname lookup is counted as a failure, not a repair', function ($function, $hostname) use ($hostnameEntry) {
	$state = repair_stage_run($function, array('incomplete' => 0, 'entries' => $hostnameEntry, 'hostname' => $hostname));

	expect($state['updates'])->toBe(0)->and($state['failures'])->toBeGreaterThan(0)->and($state['repairs'])->toBe(0);
})->with(array(
	'simple checks, failed lookup'  => array('simple_checks', false),
	'simple checks, no device'      => array('simple_checks', ''),
	'index repairs, failed lookup'  => array('snmp_index_repairs', false),
));

test('a found hostname is still written', function ($function) use ($hostnameEntry) {
	$state = repair_stage_run($function, array('incomplete' => 0, 'entries' => $hostnameEntry, 'hostname' => 'router'));

	expect($state['updates'])->toBe(1)->and($state['failures'])->toBe(0);
})->with(array('simple_checks', 'snmp_index_repairs'));

test('a failed fetch of the broken rows is a failure', function ($function) {
	$state = repair_stage_run($function, array('incomplete' => 0, 'entries' => false, 'hostname' => 'router'));

	expect($state['failures'])->toBeGreaterThan(0)->and($state['updates'])->toBe(0);
})->with(array('simple_checks', 'snmp_index_repairs'));
