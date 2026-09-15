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
 * add_graphs.php --snmp-value-regex accepted alternation and bounded repeats
 * in 1.2.31 (eth0|eth1, ^Gi[0-9]{1,2}$). db_qstr_rlike() strips those
 * characters, so the filter is quoted with db_qstr() instead, which keeps
 * the expression and still quotes it as one SQL string literal.
 */

/**
 * Runs validate_is_regex() in a child process so its translation and error
 * handler dependencies cannot shadow the real functions in this Pest run.
 *
 * @param string $regex Expression to validate.
 *
 * @return true|string The validator result.
 */
function add_graphs_validate_regex(string $regex) {
	$root    = dirname(__DIR__, 4);
	$code    = 'function __($message) { return $message; }'
		. 'function CactiErrorHandler() { return true; }'
		. 'require ' . var_export($root . '/lib/html_utility.php', true) . ';'
		. 'echo json_encode(validate_is_regex(' . var_export($regex, true) . '));';
	$pipes   = array();
	$process = proc_open(array(PHP_BINARY, '-r', $code), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
	$output  = stream_get_contents($pipes[1]);
	$error   = stream_get_contents($pipes[2]);

	fclose($pipes[1]);
	fclose($pipes[2]);

	expect(proc_close($process))->toBe(0, $error);

	return json_decode($output, true);
}

test('add_graphs accepts the 1.2.31 alternation and repeat filters', function (string $regex) {
	expect(add_graphs_validate_regex($regex))->toBeTrue();
})->with(array('eth0|eth1', '^Gi[0-9]{1,2}$', '^(Gi|Te)[0-9]/[0-9]+$'));

test('add_graphs still refuses long and semicolon expressions', function () {
	expect(add_graphs_validate_regex(str_repeat('a', 51)))->toBe('Cacti regular expressions are limited to 50 characters only for security reasons.')
		->and(add_graphs_validate_regex("x';DROP TABLE host;--"))->toBeString();
});

test('add_graphs quotes the snmp value filter as one string literal', function () {
	$source = file_get_contents(dirname(__DIR__, 4) . '/cli/add_graphs.php');

	expect($source)->toContain("\$req .= ' AND field_value REGEXP ' . db_qstr(\$dsGraph['snmpValueRegex'][\$index_snmp_filter]) . ')';")
		->and($source)->toContain('$validation = validate_is_regex($item);')
		->and($source)->not->toContain('addslashes(')
		->and($source)->not->toContain('field_value REGEXP "');
});
