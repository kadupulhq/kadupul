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
 * rrdtool_tune() replaces the RRD file with resize.rrd only after RRDtool
 * confirmed the resize.  Each scenario runs in its own process because the
 * function declares a helper when it is called.
 */

require_once dirname(__DIR__, 3) . '/Helpers/PhpSource.php';

function rrd_tune_scenario(string $scenario): array {
	$directory = sys_get_temp_dir() . '/rrd-tune-' . bin2hex(random_bytes(8));
	mkdir($directory, 0700);
	$function = test_php_function_source(file_get_contents(dirname(__DIR__, 4) . '/lib/rrd.php'), 'rrdtool_tune');

	$program = '<?php namespace RrdTuneProbe; const CACTI_CLI = true; const RRDTOOL_OUTPUT_BOOLEAN = 6; const RRDTOOL_OUTPUT_STDOUT = 1;'
		. '$GLOBALS["scenario"] = ' . var_export($scenario, true) . ';'
		. 'function read_config_option($name) { return $name === "path_rrdtool" ? "rrdtool" : 0; }'
		. 'function cacti_sizeof($value) { return is_array($value) ? count($value) : 0; }'
		. 'function cacti_has_control_chars($value) { return preg_match("/[\\\\x00-\\\\x1f]/", $value) === 1; }'
		. 'function cacti_log($message, ...$args) { $GLOBALS["logs"][] = $message; }'
		. 'function __($text, ...$args) { return $text; }'
		. 'function rrdtool_execute($command, $log, $flag) {'
		. '  $GLOBALS["executed"][] = $command;'
		. '  if ($GLOBALS["scenario"] === "resize-succeeds") { file_put_contents(getcwd() . "/resize.rrd", "resized"); return true; }'
		. '  return $GLOBALS["scenario"] === "resize-fails" ? false : true;'
		. '}'
		. '$GLOBALS["logs"] = $GLOBALS["executed"] = array();'
		. 'chdir(' . var_export($directory, true) . ');'
		. 'file_put_contents("original.rrd", "original");'
		. 'if ($scenario !== "resize-succeeds") { file_put_contents("resize.rrd", "left over from an earlier run"); }'
		. $function
		. '$line = $GLOBALS["scenario"] === "control-character" ? "original.rrd 0 GROW 5\nupdate" : "original.rrd 0 GROW 5";'
		. '$result = rrdtool_tune(getcwd() . "/original.rrd", array("resize" => array($line)), false);'
		. 'echo json_encode(array($result, file_get_contents("original.rrd"), count($GLOBALS["executed"]), $GLOBALS["logs"]));';
	$program = str_replace('$scenario', '$GLOBALS["scenario"]', $program);
	file_put_contents($directory . '/probe.php', $program);

	$process = proc_open(array(PHP_BINARY, '-d', 'error_reporting=24575', $directory . '/probe.php'), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
	$out     = stream_get_contents($pipes[1]);
	$err     = stream_get_contents($pipes[2]);
	fclose($pipes[1]);
	fclose($pipes[2]);
	proc_close($process);

	foreach (glob($directory . '/*') as $file) {
		unlink($file);
	}
	rmdir($directory);

	expect($err)->toBe('');

	return json_decode($out, true, 512, JSON_THROW_ON_ERROR);
}

test('a failed resize leaves the original file in place even when a resize.rrd is left over', function () {
	[$result, $content, $executed, $logs] = rrd_tune_scenario('resize-fails');

	expect($result)->toBeFalse();
	expect($content)->toBe('original');
	expect($executed)->toBe(1);
	expect($logs)->toContain('ERROR: RRD resize failed; original file retained.');
});

test('a command with a control character is not run and does not replace the file', function () {
	[$result, $content, $executed] = rrd_tune_scenario('control-character');

	expect($result)->toBeFalse();
	expect($content)->toBe('original');
	expect($executed)->toBe(0);
});

test('a confirmed resize replaces the original file', function () {
	[$result, $content, $executed, $logs] = rrd_tune_scenario('resize-succeeds');

	expect($result)->not->toBeFalse();
	expect($content)->toBe('resized');
	expect($logs)->toBe(array());
});
