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
 * Loaded after include/global_constants.php and lib/functions.php in a child
 * PHP process. The database is the only stubbed boundary: the production
 * get_full_script_path() and get_full_test_script_path() build the command,
 * and /bin/sh parses it.
 */

if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit;
}

function db_fetch_row_prepared($sql, $params = array(), $log = true, $db_conn = false) {
	if (str_contains($sql, 'FROM host WHERE')) {
		return $GLOBALS['data_input_fixture']['host'];
	}

	// One row serves both test_data_source() and get_full_test_script_path().
	return array(
		'id'                    => 7,
		'data_input_id'         => 3,
		'type_id'               => $GLOBALS['data_input_fixture']['type'] ?? DATA_INPUT_TYPE_SCRIPT,
		'input_string'          => $GLOBALS['data_input_fixture']['template'],
		'data_template_data_id' => 7,
		'data_template_id'      => 5,
		'active'                => 'on',
		'rrd_step'              => 300,
		'name'                  => 'fixture',
	);
}

function db_fetch_cell_prepared($sql, $params = array(), $column = '', $log = true, $db_conn = false) {
	return 0;
}

function db_fetch_assoc_prepared($sql, $params = array(), $log = true, $db_conn = false) {
	$rows = array();

	foreach ($GLOBALS['data_input_fixture']['fields'] as $name => $value) {
		$rows[] = array('data_name' => (string) $name, 'value' => $value);
	}

	return $rows;
}

/**
 * @param array<string, string|null> $fields
 * @param array<string, mixed>       $host
 */
function data_input_command(string $caller, string $template, array $fields, array $host = array()): string {
	$GLOBALS['data_input_fixture'] = array('template' => $template, 'fields' => $fields, 'host' => $host);

	if ($caller === 'poller') {
		return (string) get_full_script_path(7);
	}

	return (string) get_full_test_script_path(5, 9);
}

/**
 * Runs the production test_data_source() for a script or script server method.
 *
 * @param array<string, string|null> $fields
 */
function data_input_test_source(string $template, array $fields, int $type): bool {
	$GLOBALS['data_input_fixture'] = array('template' => $template, 'fields' => $fields, 'host' => array('id' => 9), 'type' => $type);

	return test_data_source(5, 9);
}

/**
 * Lets the shell parse the command as the poller would, but prints each word
 * instead of running the script.
 *
 * @return array{status: int, words: list<string>, err: string}
 */
function data_input_shell_words(string $command, string $shell = '/bin/sh'): array {
	$pipes   = array();
	$process = proc_open(array($shell, '-c', 'printf "%s\\0" ' . $command), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);

	if (!is_resource($process)) {
		throw new RuntimeException('Cannot start ' . $shell);
	}

	$out = stream_get_contents($pipes[1]);
	$err = stream_get_contents($pipes[2]);
	fclose($pipes[1]);
	fclose($pipes[2]);
	$status = proc_close($process);
	$words  = $out === '' ? array() : explode("\0", substr($out, 0, -1));

	return array('status' => $status, 'words' => $words, 'err' => $err);
}
