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
 |                                                                         |
 | This program is distributed in the hope that it will be useful,         |
 | but WITHOUT ANY WARRANTY; without even the implied warranty of          |
 | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the           |
 | GNU General Public License for more details.                            |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
 | Cacti is designed, written and maintained by the Cacti Group.           |
 |                                                                         |
 | Please read the included docs/CONTRIBUTING.md file for more information.|
 +-------------------------------------------------------------------------+
 */

require_once __DIR__ . '/../../../../lib/maintenance_cli.php';

$longopts = array(
	'host-id:',
	'graph-template-id:',
	'host-template-id:',
	'graph-regex:',
	'all',
	'preserve',
	'quiet',
	'list',
	'list-hosts',
	'list-host-templates',
	'list-graph-templates',
	'force',
	'version',
	'help',
);
$shortopts = 'VvHh';

/**
 * Exercise regex validation in an isolated process so translation and error
 * handler doubles cannot shadow Cacti functions during Pest collection.
 *
 * @param string $regex           Expression to validate.
 * @param bool   $broken_contract Replace the validator with a false result.
 *
 * @return array{result: string|false, handler: mixed}
 */
function remove_graphs_regex_result($regex, $broken_contract = false) {
	$root        = dirname(__DIR__, 4);
	$translation = 'function __($message) { return $message; }'
		. 'function CactiErrorHandler() { return true; }';
	$validator   = $broken_contract
		? 'function validate_is_rlike_regex($regex) { return false; }'
		: 'require ' . var_export($root . '/lib/html_utility.php', true) . ';';
	$code        = $translation . $validator
		. 'require ' . var_export($root . '/lib/maintenance_cli.php', true) . ';'
		. '$result = cacti_remove_graphs_regex_error(' . var_export($regex, true) . ');'
		. '$handler = set_error_handler(function () {});'
		. 'echo json_encode(array("result" => $result, "handler" => $handler));';
	$pipes       = array();
	$process     = proc_open(array(PHP_BINARY, '-r', $code), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);

	expect($process)->not->toBeFalse();

	$output = stream_get_contents($pipes[1]);
	$error  = stream_get_contents($pipes[2]);

	fclose($pipes[1]);
	fclose($pipes[2]);

	expect(proc_close($process))->toBe(0, $error);

	return json_decode($output, true);
}

test('remove_graphs accepts only declared options with the right value shape', function () use ($shortopts, $longopts) {
	foreach (array('--graph-template-id=5', '--host-id=0', '--all', '--force', '--list-hosts', '-V', '-h', '-Vv') as $parameter) {
		expect(cacti_remove_graphs_parameter_is_valid($parameter, $shortopts, $longopts))->toBeTrue($parameter);
	}

	foreach (array('--graph-typo=5', '--graph-type=weekly', '--all=1', '--host-id', '--host-id=', '--graph-regex', '--', '-', '-x', '-Xv', '-Hfoo', 'graph') as $parameter) {
		expect(cacti_remove_graphs_parameter_is_valid($parameter, $shortopts, $longopts))->toBeFalse($parameter);
	}
});

test('remove_graphs short option validation follows its declaration', function () use ($longopts) {
	expect(cacti_remove_graphs_parameter_is_valid('-qV', 'VvHhq', $longopts))->toBeTrue()
		->and(cacti_remove_graphs_parameter_is_valid('-qX', 'VvHhq', $longopts))->toBeFalse();
});

test('remove_graphs recognizes a declared long option written without "="', function () use ($longopts) {
	foreach (array('--host-id', '--graph-regex', '--graph-template-id', '--host-template-id') as $parameter) {
		expect(cacti_remove_graphs_takes_next_argument($parameter, $longopts))->toBeTrue($parameter);
	}

	foreach (array('--host-id=5', '--HOST-ID', '--force', '--bogus', 'host-id', '--') as $parameter) {
		expect(cacti_remove_graphs_takes_next_argument($parameter, $longopts))->toBeFalse($parameter);
	}
});

test('remove_graphs recognizes a following token that looks like an option', function () {
	/* A negative-looking value is rejected here too: it must be passed
	 * joined to its option with "=", e.g. "--host-id=-1". */
	foreach (array('--host-id=5', '-V', '-', '--', '-1') as $token) {
		expect(cacti_remove_graphs_next_looks_like_option($token))->toBeTrue($token);
	}

	foreach (array('5', '^foo', 'host-id') as $token) {
		expect(cacti_remove_graphs_next_looks_like_option($token))->toBeFalse($token);
	}
});

/**
 * Reproduce cli/remove_graphs.php's own argument loop, one decision per
 * token, so a regression there shows up without spawning the full CLI
 * bootstrap. Keep it in lockstep with the loop in that file.
 */
function remove_graphs_argument_outcomes($parms, $shortopts, $longopts) {
	$outcomes = array();
	$total    = count($parms);

	for ($i = 0; $i < $total; $i++) {
		$parameter = $parms[$i];

		if (cacti_remove_graphs_parameter_is_valid($parameter, $shortopts, $longopts)) {
			$outcomes[] = array($parameter, 'valid');

			continue;
		}

		if ($i + 1 < $total && cacti_remove_graphs_takes_next_argument($parameter, $longopts)) {
			if (cacti_remove_graphs_next_looks_like_option($parms[$i + 1])) {
				$outcomes[] = array($parameter, 'abort');

				break;
			}

			$outcomes[] = array($parameter, 'valid', $parms[$i + 1]);
			$i++;

			continue;
		}

		$action = cacti_remove_graphs_unknown_parameter_action($parameter, $shortopts, $longopts);

		if ($action === 'ignore' && cacti_remove_graphs_type_takes_next_argument($parameter) && $i + 1 < $total && !cacti_remove_graphs_next_looks_like_option($parms[$i + 1])) {
			if ($i + 2 < $total) {
				$outcomes[] = array($parameter, 'abort');

				break;
			}

			$outcomes[] = array($parameter, 'ignore', $parms[$i + 1]);
			$i++;

			continue;
		}

		$outcomes[] = array($parameter, $action);

		if ($action !== 'ignore' && $action !== 'warn') {
			break;
		}
	}

	return $outcomes;
}

test('remove_graphs loop takes a required value from the next argv token', function () use ($shortopts, $longopts) {
	$outcomes = remove_graphs_argument_outcomes(array('--host-id', '5', '--force'), $shortopts, $longopts);

	expect($outcomes)->toBe(array(
		array('--host-id', 'valid', '5'),
		array('--force', 'valid'),
	));
});

test('remove_graphs loop still accepts the "=" form', function () use ($shortopts, $longopts) {
	$outcomes = remove_graphs_argument_outcomes(array('--host-id=5', '--force'), $shortopts, $longopts);

	expect($outcomes)->toBe(array(
		array('--host-id=5', 'valid'),
		array('--force', 'valid'),
	));
});

test('remove_graphs loop takes a regex value from the next argv token', function () use ($shortopts, $longopts) {
	$outcomes = remove_graphs_argument_outcomes(array('--graph-regex', '^foo'), $shortopts, $longopts);

	expect($outcomes)->toBe(array(
		array('--graph-regex', 'valid', '^foo'),
	));
});

test('remove_graphs loop still aborts a mistyped filter even with a following value', function () use ($shortopts, $longopts) {
	$outcomes = remove_graphs_argument_outcomes(array('--HOST-ID', '5'), $shortopts, $longopts);

	expect($outcomes)->toBe(array(
		array('--HOST-ID', 'abort'),
	));
});

test('remove_graphs loop still aborts a trailing option with no value', function () use ($shortopts, $longopts) {
	$outcomes = remove_graphs_argument_outcomes(array('--force', '--host-id'), $shortopts, $longopts);

	expect($outcomes)->toBe(array(
		array('--force', 'valid'),
		array('--host-id', 'abort'),
	));
});

test('remove_graphs loop aborts instead of taking a following option as a value', function () use ($shortopts, $longopts) {
	$outcomes = remove_graphs_argument_outcomes(array('--graph-regex', '--host-id=5'), $shortopts, $longopts);

	expect($outcomes)->toBe(array(
		array('--graph-regex', 'abort'),
	));
});

test('remove_graphs loop aborts a bare option immediately followed by another option', function () use ($shortopts, $longopts) {
	$outcomes = remove_graphs_argument_outcomes(array('--host-id', '--force'), $shortopts, $longopts);

	expect($outcomes)->toBe(array(
		array('--host-id', 'abort'),
	));
});

test('remove_graphs loop still accepts a negative value joined with "="', function () use ($shortopts, $longopts) {
	$outcomes = remove_graphs_argument_outcomes(array('--host-id=-1'), $shortopts, $longopts);

	expect($outcomes)->toBe(array(
		array('--host-id=-1', 'valid'),
	));
});

test('remove_graphs loop takes the retired --graph-type value from the next argv token', function () use ($shortopts, $longopts) {
	$outcomes = remove_graphs_argument_outcomes(array('--graph-type', 'cg'), $shortopts, $longopts);

	expect($outcomes)->toBe(array(
		array('--graph-type', 'ignore', 'cg'),
	));
});

test('remove_graphs loop still accepts the retired --graph-type "=" form', function () use ($shortopts, $longopts) {
	$outcomes = remove_graphs_argument_outcomes(array('--graph-type=cg', '--force'), $shortopts, $longopts);

	expect($outcomes)->toBe(array(
		array('--graph-type=cg', 'ignore'),
		array('--force', 'valid'),
	));
});

test('remove_graphs loop still ignores a trailing --graph-type with no value', function () use ($shortopts, $longopts) {
	$outcomes = remove_graphs_argument_outcomes(array('--force', '--graph-type'), $shortopts, $longopts);

	expect($outcomes)->toBe(array(
		array('--force', 'valid'),
		array('--graph-type', 'ignore'),
	));
});

test('remove_graphs loop still validates an option that follows a bare --graph-type', function () use ($shortopts, $longopts) {
	$outcomes = remove_graphs_argument_outcomes(array('--graph-type', '--force'), $shortopts, $longopts);

	expect($outcomes)->toBe(array(
		array('--graph-type', 'ignore'),
		array('--force', 'valid'),
	));
});

test('remove_graphs loop still aborts an unrelated bare argument', function () use ($shortopts, $longopts) {
	$outcomes = remove_graphs_argument_outcomes(array('cg'), $shortopts, $longopts);

	expect($outcomes)->toBe(array(
		array('cg', 'abort'),
	));
});

/**
 * getopt() below reads the real argv itself, not this loop's parsed view of
 * it, and stops at the first bare word it finds there, silently dropping
 * every option that follows. Swallowing "cg" here would hide that stop from
 * this loop while getopt() still lost whatever came next, so the loop must
 * abort instead once anything follows the consumed value.
 */
test('remove_graphs loop aborts instead of letting getopt() silently drop a later filter', function () use ($shortopts, $longopts) {
	$outcomes = remove_graphs_argument_outcomes(array('--graph-type', 'cg', '--host-id', '5'), $shortopts, $longopts);

	expect($outcomes)->toBe(array(
		array('--graph-type', 'abort'),
	));
});

test('remove_graphs loop aborts when a filter given before --graph-type would still be followed by more', function () use ($shortopts, $longopts) {
	$outcomes = remove_graphs_argument_outcomes(array('--host-id=5', '--graph-type', 'cg', '--force'), $shortopts, $longopts);

	expect($outcomes)->toBe(array(
		array('--host-id=5', 'valid'),
		array('--graph-type', 'abort'),
	));
});

/**
 * Runs PHP's own getopt() against a real argv in a child process, since
 * getopt() always reads the running process's actual command line and
 * cannot be pointed at an arbitrary token array the way the loop above is.
 *
 * @param array $parms The tokens to pass as if they were argv.
 *
 * @return array The option names getopt() actually recognized.
 */
function remove_graphs_real_getopt_keys($parms, $shortopts, $longopts) {
	$code = 'echo json_encode(array_keys(getopt(' . var_export($shortopts, true) . ', ' . var_export($longopts, true) . ')));';
	$cmd  = array_merge(array(PHP_BINARY, '-r', $code, '--'), $parms);
	$pipes = array();
	$process = proc_open($cmd, array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);

	expect($process)->not->toBeFalse();

	$output = stream_get_contents($pipes[1]);
	$error  = stream_get_contents($pipes[2]);

	fclose($pipes[1]);
	fclose($pipes[2]);

	expect(proc_close($process))->toBe(0, $error);

	return json_decode($output, true);
}

test('every invocation the loop lets through still gives getopt() every filter it validated', function () use ($shortopts, $longopts) {
	foreach (array(
		array('--host-id=5', '--graph-type', 'cg'),
		array('--graph-type', 'cg'),
		array('--force', '--graph-type', 'cg'),
		array('--graph-type', '--host-id=5'),
	) as $parms) {
		$outcomes = remove_graphs_argument_outcomes($parms, $shortopts, $longopts);
		$last     = end($outcomes);

		expect($last[1])->not->toBe('abort', implode(' ', $parms));

		$expected = array();

		foreach ($outcomes as $outcome) {
			$name = ltrim(explode('=', $outcome[0], 2)[0], '-');

			if ($outcome[1] === 'valid' && strpos($outcome[0], '--') === 0) {
				$expected[] = $name;
			}
		}

		$actual = remove_graphs_real_getopt_keys($parms, $shortopts, $longopts);

		foreach ($expected as $name) {
			expect(in_array($name, $actual, true))->toBeTrue(implode(' ', $parms) . ' lost --' . $name);
		}
	}
});

test('remove_graphs uses the real regex length and semicolon guards', function () {
	$valid     = remove_graphs_regex_result('edge.*');
	$malformed = remove_graphs_regex_result('(');
	$too_long  = remove_graphs_regex_result(str_repeat('a', 51));
	$semicolon = remove_graphs_regex_result('edge;.*');
	$alteration = remove_graphs_regex_result('eth0|eth1');
	$repeat     = remove_graphs_regex_result('^Gi[0-9]{1,2}$');

	expect($valid['result'])->toBeFalse()
		->and($valid['handler'])->toBe('CactiErrorHandler')
		->and($malformed['result'])->toContain('Compilation failed')
		->and($too_long['result'])->toBe('Cacti regular expressions are limited to 50 characters only for security reasons.')
		->and($semicolon['result'])->not->toBeFalse()
		->and($alteration['result'])->toContain('do not support alternation')
		->and($repeat['result'])->toContain('do not support alternation');
});

test('remove_graphs fails closed when its regex validator breaks contract', function () {
	expect(remove_graphs_regex_result('edge.*', true)['result'])->toBe('Invalid regular expression.');
});

test('remove_graphs quiet mode follows the parsed option key', function () {
	expect(cacti_remove_graphs_quiet_enabled(array()))->toBeFalse()
		->and(cacti_remove_graphs_quiet_enabled(array('quiet' => false)))->toBeTrue();
});

test('reapply names builds balanced prepared query fragments', function () {
	foreach (array(
		array('all', 'edge', 2),
		array('1,2,3', '', 3),
		array('0', 'edge', 3),
		array('42', 'edge', 3),
	) as $case) {
		$where = cacti_reapply_names_where($case[0], $case[1]);

		expect($where)->toBeArray()
			->and(substr_count($where[0], '?'))->toBe(count($where[1]))
			->and(count($where[1]))->toBe($case[2]);
	}
});

test('reapply names preserves SQL parameter ordering', function () {
	list($where, $params) = cacti_reapply_names_where('7,9', 'edge');

	expect($where)->toContain('title_cache LIKE ?')
		->and($where)->toContain('host_id IN (?,?)')
		->and($params)->toBe(array('%edge%', '%edge%', 7, 9));
});

test('reapply names retains supported zero, whitespace and leading-zero ids', function () {
	foreach (array(' 5', '5 ', '1, 5', '007') as $host_id) {
		$where = cacti_reapply_names_where($host_id, '');

		expect($where)->toBeArray($host_id)
			->and(substr_count($where[0], '?'))->toBe(count($where[1]));
	}

	expect(cacti_reapply_names_where('0,5', '')[1])->toBe(array(0, 5))
		->and(cacti_reapply_names_where('1,0,3', '')[1])->toBe(array(1, 0, 3))
		->and(cacti_reapply_names_where('0,0', '')[1])->toBe(array(0, 0));
});

test('reapply names rejects an invalid member instead of narrowing the host list', function () {
	expect(cacti_reapply_names_where('1,abc,3', ''))->toBeFalse()
		->and(cacti_reapply_names_where('1,,3', ''))->toBeFalse()
		->and(cacti_reapply_names_where('1 UNION SELECT 1', ''))->toBeFalse()
		->and(cacti_reapply_names_where('', ''))->toBeFalse();
});

test('reapply names rejects malformed values that compare loosely to zero', function () {
	foreach (array('0e5', '0.0', '-0', '+0', '0.') as $host_id) {
		expect(cacti_reapply_names_where($host_id, ''))->toBeFalse($host_id);
	}

	expect(cacti_reapply_names_where('0', ''))->toBe(array(' AND graph_local.host_id=?', array(0)));
	expect(cacti_reapply_names_where('all', ''))->toBe(array('', array()));
});

test('maintenance failures keep their 1.2.31 negative exit codes', function () {
	foreach (array('removespikes.php', 'splice_rrd.php') as $script) {
		$source = file_get_contents(__DIR__ . '/../../../../cli/' . $script);

		expect($source)->toMatch('/exit\(-3\)/');
	}
});

test('remove_graphs wires strict validation before getopt', function () {
	$source = file_get_contents(__DIR__ . '/../../../../cli/remove_graphs.php');

	expect($source)->not->toBeFalse()
		->and($source)->toContain('cacti_remove_graphs_parameter_is_valid($parameter, $shortopts, $longopts)')
		->and($source)->toContain('cacti_remove_graphs_takes_next_argument($parameter, $longopts)')
		->and($source)->toContain('cacti_remove_graphs_next_looks_like_option($parms[$i + 1])')
		->and($source)->toContain('ERROR: Invalid Argument:')
		->and($source)->not->toContain("'graph-type::'");

	expect($source)->toContain('displayHosts($hosts, $quietMode)')
		->and($source)->toContain('displayHostTemplates($hostTemplates, $quietMode)')
		->and($source)->toContain('displayGraphTemplates($graphTemplates, $quietMode)');
});

test('every declared remove_graphs option has a switch branch', function () use ($shortopts, $longopts) {
	$source = file_get_contents(dirname(__DIR__, 4) . '/cli/remove_graphs.php');

	foreach ($longopts as $option) {
		expect($source)->toContain("case '" . rtrim($option, ':') . "':");
	}

	foreach (str_split(str_replace(':', '', $shortopts)) as $option) {
		expect($source)->toContain("case '$option':");
	}
});

test('all regex consumers honor the validator true-or-error contract', function () {
	$root = dirname(__DIR__, 4);

	$add_graphs = file_get_contents($root . '/cli/add_graphs.php');
	expect($add_graphs)->toContain("'snmp-value-regex:'")
		->and($add_graphs)->toContain("if (\$item === false || \$item === '')")
		->and($add_graphs)->toContain('validate_is_regex($item)')
		->and($add_graphs)->toContain('if ($validation !== true)')
		->and($add_graphs)->toContain("' AND field_value REGEXP ' . db_qstr(\$dsGraph")
		->and($add_graphs)->not->toContain('field_value REGEXP "');

	foreach (array(
		'cli/apply_automation_rules.php',
		'aggregate_graphs.php',
		'lib/functions.php',
		'lib/clog_webapi.php',
	) as $file) {
		$source = file_get_contents($root . '/' . $file);
		$lines  = preg_grep('/validate_is_regex\s*\(/', explode("\n", $source));

		foreach ($lines as $line) {
			expect($line)->toContain('=== true');
		}
	}
});

test('graph-name reapply wires invalid selectors to distinct failures', function () {
	$source = file_get_contents(__DIR__ . '/../../../../cli/poller_graphs_reapply_names.php');

	expect($source)->not->toBeFalse()
		->and($source)->toContain('cacti_reapply_names_where($host_id, $filter)')
		->and($source)->toContain('You must specify either a host_id')
		->and($source)->toContain("Invalid host id '");
});
