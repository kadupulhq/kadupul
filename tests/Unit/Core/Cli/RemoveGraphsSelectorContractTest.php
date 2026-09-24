<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace RemoveGraphsSelectorContractTest;

$root = dirname(__DIR__, 4);

/**
 * Run the real selection block of cli/remove_graphs.php for one invocation and
 * report what it decided, without a database. The block is taken from the file
 * so the help below is checked against the behaviour, not against a copy.
 *
 * @param array $selectors host_ids, host_template_ids, graph_template_ids, regex.
 * @param bool  $all       The --all flag.
 * @param bool  $list      The --list flag.
 *
 * @return array{status: int, out: string, where: string|null}
 */
function selection_outcome(array $selectors, $all = false, $list = false) {
	$source = file_get_contents(dirname(__DIR__, 4) . '/cli/remove_graphs.php');
	expect($source)->not->toBeFalse();

	$start = strpos($source, "\$sql_where  = 'WHERE gl.id > 0';");
	$end   = strpos($source, '$graphs = db_fetch_assoc(', $start);
	expect($start)->not->toBeFalse();
	expect($end)->not->toBeFalse();

	$fragment = substr($source, $start, $end - $start);
	// The guard is the decision under test; refuse a fragment that lost it.
	expect($fragment)->toContain('must use the --all option');

	$defaults = array('host_ids' => array(), 'host_template_ids' => array(),
		'graph_template_ids' => array(), 'regex' => array());
	$vars     = array_merge($defaults, $selectors);

	$code = 'function cacti_sizeof($a) { return is_array($a) ? count($a) : 0; }'
		. 'function db_qstr_rlike($r) { return "RLIKE " . chr(39) . $r . chr(39); }'
		. '$host_ids = ' . var_export($vars['host_ids'], true) . ';'
		. '$host_template_ids = ' . var_export($vars['host_template_ids'], true) . ';'
		. '$graph_template_ids = ' . var_export($vars['graph_template_ids'], true) . ';'
		. '$regex = ' . var_export($vars['regex'], true) . ';'
		. '$all = ' . var_export((bool) $all, true) . ';'
		. '$list = ' . var_export((bool) $list, true) . ';'
		. $fragment
		. 'echo "WHERE:" . $sql_where;';

	$pipes   = array();
	$process = proc_open(array(PHP_BINARY, '-r', $code),
		array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
	expect($process)->not->toBeFalse();

	$out = stream_get_contents($pipes[1]);
	$err = stream_get_contents($pipes[2]);
	fclose($pipes[1]);
	fclose($pipes[2]);
	$status = proc_close($process);

	expect($err)->toBe('');

	$where = null;
	if (strpos($out, 'WHERE:') !== false) {
		$where = substr($out, strpos($out, 'WHERE:') + 6);
	}

	return array('status' => $status, 'out' => $out, 'where' => $where);
}

/*
 * The help called --graph-template-id mandatory. It never was: each of the four
 * selectors is accepted on its own, which is what these cases pin down.
 */
test('each selector is accepted on its own', function () {
	$accepted = array();

	foreach (array(
		'graph_template_ids' => array(3),
		'host_template_ids'  => array(4),
		'host_ids'           => array(5),
		'regex'              => array('^edge'),
	) as $field => $value) {
		$result = selection_outcome(array($field => $value));

		$accepted[$field] = $result['status'] === 0 && $result['where'] !== 'WHERE gl.id > 0';
	}

	expect($accepted)->toBe(array(
		'graph_template_ids' => true,
		'host_template_ids'  => true,
		'host_ids'           => true,
		'regex'              => true,
	));
});

test('selectors narrow together rather than replacing one another', function () {
	$result = selection_outcome(array('host_ids' => array(5), 'graph_template_ids' => array(3)));

	expect($result['status'])->toBe(0)
		->and($result['where'])->toContain('gl.host_id IN (5)')
		->and($result['where'])->toContain('gl.graph_template_id IN (3)');
});

test('no selector is refused unless --all says so', function () {
	$refused = selection_outcome(array());

	expect($refused['status'])->toBe(1)
		->and($refused['out'])->toContain('must use the --all option');

	$explicit = selection_outcome(array(), true);

	expect($explicit['status'])->toBe(0)
		->and($explicit['where'])->toBe('WHERE gl.id > 0');
});

/* --list removes nothing, so it is the one mode allowed to select everything. */
test('a bare --list selects every graph instead of being refused', function () {
	$result = selection_outcome(array(), false, true);

	expect($result['status'])->toBe(0)
		->and($result['where'])->toBe('WHERE gl.id > 0');
});

test('--all ignores the other selectors', function () {
	$result = selection_outcome(array('host_ids' => array(5)), true);

	expect($result['where'])->toBe('WHERE gl.id > 0');
});

/*
 * Tie the help to the behaviour above, so the two cannot drift apart again.
 */
test('the help describes the contract the code implements', function () use ($root) {
	$source = file_get_contents($root . '/cli/remove_graphs.php');
	$start  = strpos($source, 'function display_help()');
	expect($start)->not->toBeFalse();

	$help  = substr($source, $start);
	$wrong = array();

	// No selector is mandatory, and the old wording said one was.
	if (strpos($help, 'Mandatory') !== false) {
		$wrong[] = 'still calls a selector mandatory';
	}

	if (strpos($help, 'you must provide from one to many graph-template-id') !== false) {
		$wrong[] = 'still requires graph-template-id';
	}

	// Both facts a reader cannot get from the option list alone.
	if (strpos($help, '--all') === false || strpos($help, 'refused') === false) {
		$wrong[] = 'does not say an empty selection is refused without --all';
	}

	if (strpos($help, 'lists every Graph') === false) {
		$wrong[] = 'does not say a bare --list lists every Graph';
	}

	// Every option the file actually parses must appear in the help.
	foreach (array('--graph-template-id', '--host-template-id', '--host-id',
		'--graph-regex', '--all', '--list', '--force', '--preserve') as $option) {
		if (substr_count($help, $option) === 0) {
			$wrong[] = 'omits ' . $option;
		}
	}

	expect($wrong)->toBe(array());
});
