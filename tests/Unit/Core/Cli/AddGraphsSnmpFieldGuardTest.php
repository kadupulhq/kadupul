<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/*
 * add_graphs.php guarded --snmp-field with `if ($snmpField = "")`, an
 * assignment rather than a comparison. It did two wrong things at once: the
 * branch never ran because "" is falsy, so an empty field was never refused,
 * and the assignment emptied $snmpField for the displaySNMPValues() call on
 * the next line, so a field the operator did supply was discarded.
 *
 * The loop body is rebuilt here with the file's own condition so both effects
 * are observed rather than matched as text.
 */

namespace AddGraphsSnmpFieldGuardTest;

/** The condition exactly as the script writes it. */
function guard_condition() : string {
	$source = file_get_contents(dirname(__DIR__, 4) . '/cli/add_graphs.php');

	expect($source)->not->toBeFalse();

	preg_match('/if \((\$snmpField [^)]+)\) \{\n\t+print "ERROR: You must supply a valid snmp-field/', $source, $match);

	expect($match)->not->toBeEmpty();

	return $match[1];
}

/**
 * Run one iteration. Returns the field as displaySNMPValues() would receive
 * it, or 'REFUSED' when the guard fired.
 */
function run_iteration(string $condition, string $field) : string {
	$code = '$snmpField = ' . var_export($field, true) . ';'
		. ' if (' . $condition . ') { echo "REFUSED"; } else { echo $snmpField; }';

	$out = array();
	exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code) . ' 2>/dev/null', $out);

	return implode('', $out);
}

it('refuses an empty snmp field', function () {
	expect(run_iteration(guard_condition(), ''))->toBe('REFUSED');
});

it('passes a supplied snmp field through unchanged', function () {
	expect(run_iteration(guard_condition(), 'ifOperStatus'))->toBe('ifOperStatus');
});
