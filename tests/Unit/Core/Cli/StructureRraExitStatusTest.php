<?php

/* Copyright (C) 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later */

namespace StructureRraExitStatusTest;

$source = file_get_contents(dirname(__DIR__, 4) . '/cli/structure_rra_paths.php');
if ($source === false) {
	throw new \RuntimeException('Cannot read structure_rra_paths.php for fatal-exit regression coverage.');
}
$cases = array();
// Extract each real fatal diagnostic through its exit, without loading the
// operational CLI bootstrap or touching any RRD files or database.
preg_match_all('/print [^;]*FATAL:[^;]*;\s*(?:display_help\(\);\s*)?exit[^;]*;/', $source, $matches);
/* Keyed by a distinctive part of each diagnostic rather than by position, so
   reordering or inserting a branch cannot silently re-map the expectations. */
$expectedCodes = array(
	'designed for the main Data Collector' => 1,
	'designed for local RRDfile storage'   => 1,
	'Performance Booster required'         => 1,
	'specifying a Device ID'               => 1,
	'specifying a Device Template ID'      => 1,
	'Explicitly Instruct This Script'      => 1,
	'Could NOT Make New Directory'         => 1,
	'Set Permissions for Directory'        => 5,
	'Set Permissions for File'             => 6,
	'Could not Move RRD File'              => 3,
);

$matched = array();
foreach ($matches[0] as $index => $fragment) {
	$hits = array();

	foreach ($expectedCodes as $needle => $code) {
		if (strpos($fragment, $needle) !== false) {
			$hits[$needle] = $code;
		}
	}

	$matched += $hits;
	// A diagnostic matching none, or more than one, has no unambiguous
	// expectation; the inventory test names it rather than asserting on null.
	$cases['fatal branch ' . $index] = array($fragment, count($hits) === 1 ? reset($hits) : null);
}
dataset('structure RRA fatal branches', $cases);

test('every structure RRA fatal exit maps to exactly one expectation', function () use ($matches, $cases, $expectedCodes, $matched) {
	expect(count($matches[0]))->toBe(10);

	foreach ($cases as $name => $case) {
		expect($case[1])->not->toBeNull($name);
	}

	// Without this, a branch rewritten to repeat a diagnostic another branch
	// already carries would keep the count at ten and leave one code unchecked.
	expect(array_keys(array_diff_key($expectedCodes, $matched)))->toBe(array());
});

test('real fatal branches print diagnostics and exit nonzero before continuing', function ($fragment, $expected) {
	$setup = 'define("PHP_DEOL", PHP_EOL . PHP_EOL); function display_help() { echo "HELP", PHP_EOL; } '
		. '$new_base_path = "fixture-dir"; $new_rrd_path = "fixture-new.rrd"; $old_rrd_path = "fixture-old.rrd"; ';
	$process = proc_open(array(PHP_BINARY, '-r', $setup . $fragment . 'echo "UNREACHABLE";'),
		array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
	expect(is_resource($process))->toBeTrue();
	fclose($pipes[0]);
	$output = stream_get_contents($pipes[1]);
	$error = stream_get_contents($pipes[2]);
	fclose($pipes[1]);
	fclose($pipes[2]);
	$status = proc_close($process);
	expect($status)->toBe($expected);
	expect($output)->toContain('FATAL:');
	expect($output)->not->toContain('UNREACHABLE');
	expect($error)->toBe('');
})->with('structure RRA fatal branches');
